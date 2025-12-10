import dotenv from "dotenv";
import express from "express";
import { ethers } from "ethers";
import {
  Client,
  AccountBalanceQuery,
  TokenInfoQuery,
  AccountId,
  PrivateKey,
  TransferTransaction
} from "@hashgraph/sdk";

dotenv.config({ path: process.env.DOTENV_CONFIG_PATH });

import {
  RPC_URL,
  TOKENS,
  WRAPPED_NATIVE,
  ROUTER,
  convertHederaIdToEVMAddress,
  getEvmAddressFromAccountId,
  safeHbar,
  erc20Abi,
  routerAbi,
  truncateDecimals,
  BRIDGE_CONTRACT
} from "./tokens.js";

// ------------------------------------------------------
// Express Setup
// ------------------------------------------------------
const app = express();
app.use(express.json());

// ------------------------------------------------------
// ENV Vars
// ------------------------------------------------------
const {
  HEDERA_OPERATOR_ADDRESS,
  EVM_OPERATOR_ADDRESS,
  OPERATOR_PRIVATE_KEY
} = process.env;

if (!HEDERA_OPERATOR_ADDRESS || !EVM_OPERATOR_ADDRESS || !OPERATOR_PRIVATE_KEY) {
  console.error("Missing env vars: HEDERA_OPERATOR_ADDRESS, EVM_OPERATOR_ADDRESS, OPERATOR_PRIVATE_KEY");
  process.exit(1);
}

// ------------------------------------------------------
// Constants
// ------------------------------------------------------
const SLIPPAGE_TOLERANCE = 0.005; // 0.5%
const EVM_NATIVE_DECIMALS = 18;
const HEDERA_NATIVE_DECIMALS = 8;

// ------------------------------------------------------
// INTERNAL HELPERS
// ------------------------------------------------------
async function getEvmAmountsOut(amountInBigInt, path, routerAddress, provider) {
  try {
    const router = new ethers.Contract(routerAddress, routerAbi, provider);
    return await router.getAmountsOut(amountInBigInt, path); // returns BigInt[]
  } catch {
    return null;
  }
}

async function checkBridgeAllowance(fromNetwork, fromToken, fromAddress, fromAmount) {
  let bridgeContract = BRIDGE_CONTRACT[fromNetwork];
  const provider = new ethers.JsonRpcProvider(RPC_URL[fromNetwork]);
  const TokenFrom = TOKENS?.[fromNetwork]?.[fromToken];

  if (!TokenFrom) throw new Error("Token configuration not found");

  // Resolve token address
  const tokenAddress =
    fromNetwork === "hedera"
      ? convertHederaIdToEVMAddress(TokenFrom.address)
      : TokenFrom.address;

  // Hedera special-case (convert account → evm)
  if (fromNetwork === "hedera") {
    bridgeContract = convertHederaIdToEVMAddress(bridgeContract);

    const client = Client.forMainnet().setOperator(
      AccountId.fromString(HEDERA_OPERATOR_ADDRESS),
      PrivateKey.fromStringECDSA(OPERATOR_PRIVATE_KEY)
    );

    fromAddress = await getEvmAddressFromAccountId(fromAddress, client);
  }

  const contract = new ethers.Contract(tokenAddress, erc20Abi, provider);

  // Keep decimals safe
  const fromAmountStr = truncateDecimals(String(fromAmount), TokenFrom.decimals);
  const amountWei = ethers.parseUnits(fromAmountStr, TokenFrom.decimals);

  const allowance = await contract.allowance(fromAddress, bridgeContract);
  return allowance < amountWei;
}


app.post("/bridge/precheck", async (req, res) => {
  try {
    const {
      network,
      token,
      amount,
      nativeAmount,
      fromNetwork,
      fromAddress,
      fromToken,
      fromAmount
    } = req.body;

    // Basic validation
    if (!network || !token)
      return res.status(400).json({ canBridge: false, message: "Missing network or token" });

    if (!RPC_URL?.[network])
      return res.status(400).json({ canBridge: false, message: `Unsupported network: ${network}` });

    const Token = TOKENS?.[network]?.[token];
    if (!Token)
      return res.status(400).json({ canBridge: false, message: "Unsupported token" });

    if (amount === undefined || nativeAmount === undefined)
      return res.status(400).json({ canBridge: false, message: "Missing amount/nativeAmount" });

    // Normalize values
    const amountStr = truncateDecimals(String(amount), Token.decimals);
    const nativeAmountStr = String(nativeAmount);

    if (Number(amountStr) <= 0)
      return res.status(400).json({ canBridge: false, message: "Invalid token amount" });

    if (Number(nativeAmountStr) < 0)
      return res.status(400).json({ canBridge: false, message: "Invalid nativeAmount" });

    const provider = new ethers.JsonRpcProvider(RPC_URL[network]);
    let poolHasFunds = false;
    let estimatedOutBigInt = null;
    let slippageOk = true;

    // ------------------------------
    // Check allowance (non-native)
    // ------------------------------
    const TokenFrom = TOKENS?.[fromNetwork]?.[fromToken];
    let requireAllowance = false;

    if (!TokenFrom.native)
      requireAllowance = await checkBridgeAllowance(fromNetwork, fromToken, fromAddress, fromAmount);

    // =============================================================
    // HEDERA PRECHECK
    // =============================================================
    if (network === "hedera") {
      const client = Client.forMainnet().setOperator(
        AccountId.fromString(HEDERA_OPERATOR_ADDRESS),
        PrivateKey.fromStringECDSA(OPERATOR_PRIVATE_KEY)
      );

      const bal = await new AccountBalanceQuery()
        .setAccountId(AccountId.fromString(HEDERA_OPERATOR_ADDRESS))
        .execute(client);

      const poolNative = BigInt(bal.hbars.toTinybars().toString());

      if (Token.native) {
        const needed = BigInt(safeHbar(amountStr).toTinybars().toString());
        poolHasFunds = poolNative >= needed;
      } else {
        const tokenBal = bal.tokens._map?.get(Token.address);
        const poolToken = tokenBal ? BigInt(tokenBal.toString()) : 0n;

        const neededToken = BigInt(ethers.parseUnits(amountStr, Token.decimals).toString());
        poolHasFunds = poolToken >= neededToken;

        // Fallback swap
        if (!poolHasFunds) {
          const neededNative = BigInt(safeHbar(nativeAmountStr).toTinybars().toString());

          if (poolNative >= neededNative) {
            const routerAddress = ROUTER[network];
            const wrappedNativeAddr = WRAPPED_NATIVE[network];
            const outToken = convertHederaIdToEVMAddress(Token.address);

            const amountInBigInt = ethers.parseUnits(
              truncateDecimals(nativeAmountStr, 8),
              8
            );

            const path = [wrappedNativeAddr, outToken];
            const amounts = await getEvmAmountsOut(amountInBigInt, path, routerAddress, provider);

            if (amounts?.length > 1) {
              estimatedOutBigInt = amounts[1];
              const amountOutMin =
                estimatedOutBigInt -
                (estimatedOutBigInt * BigInt(Math.floor(SLIPPAGE_TOLERANCE * 1000))) /
                  1000n;

              slippageOk = amountOutMin <= estimatedOutBigInt && estimatedOutBigInt > 0n;
              poolHasFunds = slippageOk;
            } else {
              slippageOk = false;
            }
          }
        }
      }
    }

    // =============================================================
    // EVM PRECHECK
    // =============================================================
    else {
      if (Token.native) {
        const bal = await provider.getBalance(EVM_OPERATOR_ADDRESS);
        const needed = ethers.parseUnits(amountStr, EVM_NATIVE_DECIMALS);
        poolHasFunds = bal >= needed;
      } else {
        const tokenContract = new ethers.Contract(Token.address, erc20Abi, provider);
        const bal = BigInt((await tokenContract.balanceOf(EVM_OPERATOR_ADDRESS)).toString());

        const neededToken = ethers.parseUnits(amountStr, Token.decimals);
        poolHasFunds = bal >= neededToken;

        if (!poolHasFunds) {
          const nativeBal = await provider.getBalance(EVM_OPERATOR_ADDRESS);
          const neededNative = ethers.parseUnits(nativeAmountStr, EVM_NATIVE_DECIMALS);

          if (nativeBal >= neededNative) {
            const routerAddress = ROUTER[network];
            const wrappedNativeAddr = WRAPPED_NATIVE[network];

            const path = [wrappedNativeAddr, Token.address];
            const amounts = await getEvmAmountsOut(neededNative, path, routerAddress, provider);

            if (amounts?.length > 1) {
              estimatedOutBigInt = amounts[1];
              const amountOutMin =
                estimatedOutBigInt -
                (estimatedOutBigInt * BigInt(Math.floor(SLIPPAGE_TOLERANCE * 1000))) /
                  1000n;

              slippageOk = amountOutMin <= estimatedOutBigInt && estimatedOutBigInt > 0n;
              poolHasFunds = slippageOk;
            } else {
              slippageOk = false;
            }
          }
        }
      }
    }

    // =============================================================
    // FINAL RESPONSE
    // =============================================================
    if (!poolHasFunds)
      return res.json({ canBridge: false, message: "Insufficient liquidity" });

    if (!slippageOk)
      return res.json({ canBridge: false, message: "Slippage too high" });

    let estimatedOutFormatted = null;
    if (estimatedOutBigInt) {
      const decimals = Token.native
        ? network === "hedera"
          ? HEDERA_NATIVE_DECIMALS
          : EVM_NATIVE_DECIMALS
        : Token.decimals;

      estimatedOutFormatted = ethers.formatUnits(estimatedOutBigInt, decimals);
    }

    return res.json({
      canBridge: true,
      message: "Precheck passed",
      estimatedOut: estimatedOutFormatted,
      requireAllowance
    });
  } catch (err) {
    console.error("Precheck error:", err);
    res.status(500).json({
      canBridge: false,
      message: "Server error during precheck",
      error: err.message
    });
  }
});


app.post("/bridge/execute", async (req, res) => {
  try {
    const { network, token, amount, nativeAmount, recipient } = req.body;

    if (!network || !token || !amount || !nativeAmount || !recipient)
      return res.status(400).json({ error: "Missing required parameters" });

    const parsedAmount = Number(amount);
    const parsedNativeAmount = Number(nativeAmount);

    if (parsedAmount <= 0 || parsedNativeAmount < 0)
      return res.status(400).json({ error: "Invalid amount/nativeAmount" });

    const Token = TOKENS[network][token];
    if (!Token)
      return res.status(400).json({ error: "Unsupported token" });

    const provider = new ethers.JsonRpcProvider(RPC_URL[network]);
    const wallet = new ethers.Wallet(OPERATOR_PRIVATE_KEY, provider);

    // =============================================================
    // HEDERA EXECUTE
    // =============================================================
    if (network === "hedera") {
      const client = Client.forMainnet().setOperator(
        AccountId.fromString(HEDERA_OPERATOR_ADDRESS),
        PrivateKey.fromStringECDSA(OPERATOR_PRIVATE_KEY)
      );

      const bal = await new AccountBalanceQuery()
        .setAccountId(AccountId.fromString(HEDERA_OPERATOR_ADDRESS))
        .execute(client);

      const poolHbar = BigInt(bal.hbars.toTinybars().toString());

      // ---- Native HBAR transfer
      if (Token.native) {
        const tx = await new TransferTransaction()
          .addHbarTransfer(HEDERA_OPERATOR_ADDRESS, safeHbar(parsedAmount).negated())
          .addHbarTransfer(recipient, safeHbar(parsedAmount))
          .execute(client);

        const receipt = await tx.getReceipt(client);

        return res.json({
          status: "success",
          message: "HBAR transfer successful",
          txHash: tx.transactionId.toString(),
          network,
          type: "native-transfer",
          statusText: receipt.status.toString()
        });
      }

      // ---- HTS TRANSFER IF TOKEN BALANCE AVAILABLE
      const tokenBal = bal.tokens._map.get(Token.address);
      const poolToken =
        tokenBal ? BigInt(tokenBal.toString()) : 0n;

      const neededToken = ethers.parseUnits(
        truncateDecimals(parsedAmount.toString(), Token.decimals),
        Token.decimals
      );

      const hasTokenFunds = poolToken >= neededToken;

      if (hasTokenFunds) {
        const tx = await new TransferTransaction()
          .addTokenTransfer(Token.address, HEDERA_OPERATOR_ADDRESS, -(parsedAmount * 10 ** Token.decimals))
          .addTokenTransfer(Token.address, recipient, parsedAmount * 10 ** Token.decimals)
          .execute(client);

        const receipt = await tx.getReceipt(client);

        return res.json({
          status: "success",
          message: `${Token.symbol} HTS token transfer successful`,
          txHash: tx.transactionId.toString(),
          network,
          type: "token-transfer",
          statusText: receipt.status.toString()
        });
      }

      // ---- SWAP FALLBACK
      const neededNative = BigInt(safeHbar(parsedNativeAmount).toTinybars().toString());

      if (poolHbar >= neededNative) {
        const router = new ethers.Contract(ROUTER[network], routerAbi, wallet);

        const wrappedNative = WRAPPED_NATIVE[network];
        const tokenAddress = convertHederaIdToEVMAddress(Token.address);
        const amountIn = ethers.parseUnits(
          truncateDecimals(parsedNativeAmount.toString(), 18),
          18
        );

        const path = [wrappedNative, tokenAddress];
        const amounts = await router.getAmountsOut(amountIn, path);
        if (!amounts?.length) throw new Error("No liquidity path available");

        const evmRecipient = await getEvmAddressFromAccountId(recipient, client);

        const tx = await router.swapExactETHForTokens(
          0, // slippage already enforced in precheck
          path,
          evmRecipient,
          Math.floor(Date.now() / 1000) + 600,
          { value: amountIn, gasLimit: 1_000_000n }
        );

        const receipt = await tx.wait();
        return res.json({
          status: "success",
          message: "Swap completed",
          txHash: receipt.hash,
          network,
          type: "swap",
          blockNumber: receipt.blockNumber
        });
      }

      throw new Error("Insufficient liquidity");
    }

    // =============================================================
    // EVM EXECUTE
    // =============================================================
    const tokenContract = !Token.native
      ? new ethers.Contract(Token.address, erc20Abi, provider)
      : null;

    const poolNative = await provider.getBalance(EVM_OPERATOR_ADDRESS);

    let poolToken = 0n;
    if (!Token.native) {
      poolToken = BigInt((await tokenContract.balanceOf(EVM_OPERATOR_ADDRESS)).toString());
    }

    const neededToken = ethers.parseUnits(
      truncateDecimals(parsedAmount.toString(), Token.decimals),
      Token.decimals
    );

    const neededNative = ethers.parseUnits(
      truncateDecimals(parsedNativeAmount.toString(), 18),
      18
    );

    // ---- Native transfer
    if (Token.native) {
      const tx = await wallet.sendTransaction({ to: recipient, value: neededToken });
      const receipt = await tx.wait();

      return res.json({
        status: "success",
        message: "Native transfer successful",
        txHash: receipt.hash,
        network,
        type: "native-transfer",
        blockNumber: receipt.blockNumber
      });
    }

    // ---- Direct ERC20 transfer
    if (poolToken >= neededToken) {
      const tx = await tokenContract.connect(wallet).transfer(recipient, neededToken);
      const receipt = await tx.wait();

      return res.json({
        status: "success",
        message: `${Token.symbol} transfer successful`,
        txHash: receipt.hash,
        network,
        type: "token-transfer",
        blockNumber: receipt.blockNumber
      });
    }

    // ---- Swap fallback
    if (poolNative >= neededNative) {
      const router = new ethers.Contract(ROUTER[network], routerAbi, wallet);
      const wrappedNative = WRAPPED_NATIVE[network];
      const path = [wrappedNative, Token.address];

      await router.getAmountsOut(neededNative, path); // ensures liquidity

      const tx = await router.swapExactETHForTokens(
        0, // slippage was validated
        path,
        recipient,
        Math.floor(Date.now() / 1000) + 600,
        { value: neededNative, gasLimit: 1_000_000n }
      );

      const receipt = await tx.wait();
      return res.json({
        status: "success",
        message: "Swap completed",
        txHash: receipt.hash,
        network,
        type: "swap",
        blockNumber: receipt.blockNumber
      });
    }

    throw new Error("Insufficient liquidity for transfer or swap");
  } catch (err) {
    console.error("Execute error:", err);
    res.status(500).json({ status: "failed", error: err.message });
  }
});

// =============================================================
// BALANCE ROUTE
// =============================================================
app.get("/balance", async (req, res) => {
  try {
    const { network, address, token } = req.query;

    if (!network || !address)
      return res.status(400).json({ error: "network and address required" });

    // Hedera balance
    if (network === "hedera") {
      const client = Client.forMainnet().setOperator(
        AccountId.fromString(HEDERA_OPERATOR_ADDRESS),
        PrivateKey.fromStringECDSA(OPERATOR_PRIVATE_KEY)
      );

      const bal = await new AccountBalanceQuery()
        .setAccountId(AccountId.fromString(address))
        .execute(client);

      if (!token) {
        return res.json({ balance: (Number(bal.hbars.toTinybars()) / 1e8).toString() });
      }

      const tokenBal = bal.tokens.get(token);
      if (!tokenBal) return res.json({ balance: "0" });

      const info = await new TokenInfoQuery().setTokenId(token).execute(client);
      const formatted = Number(tokenBal) / 10 ** info.decimals;

      return res.json({ balance: formatted.toString() });
    }

    // EVM balance
    const provider = new ethers.JsonRpcProvider(RPC_URL[network]);

    if (!token) {
      return res.json({ balance: ethers.formatEther(await provider.getBalance(address)) });
    }

    const contract = new ethers.Contract(token, erc20Abi, provider);
    const [rawBal, decimals] = await Promise.all([
      contract.balanceOf(address),
      contract.decimals()
    ]);

    return res.json({ balance: ethers.formatUnits(rawBal, decimals) });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// =============================================================
// SERVER START
// =============================================================
app.listen(5001, () => console.log("Bridge service running on port 5001"));
