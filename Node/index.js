import dotenv from 'dotenv';
import express from "express";
import { ethers } from "ethers";
import {
  Client,
  AccountBalanceQuery,
  AccountId,
  PrivateKey,
  TransferTransaction 
} from "@hashgraph/sdk";
dotenv.config({path: process.env.DOTENV_CONFIG_PATH});

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

const app = express();
app.use(express.json());

const {
  HEDERA_OPERATOR_ADDRESS,
  EVM_OPERATOR_ADDRESS,
  OPERATOR_PRIVATE_KEY,
} = process.env;

if (!HEDERA_OPERATOR_ADDRESS || !EVM_OPERATOR_ADDRESS || !OPERATOR_PRIVATE_KEY) {
  console.error("Missing environment variables (HEDERA_OPERATOR_ADDRESS, EVM_OPERATOR_ADDRESS, OPERATOR_PRIVATE_KEY)");
  process.exit(1);
}

// Config
const SLIPPAGE_TOLERANCE = 0.005; // 0.5%
const EVM_NATIVE_DECIMALS = 18;
const HEDERA_NATIVE_DECIMALS = 8;

async function getEvmAmountsOut(amountInBigInt, path, routerAddress, provider) {
  try {
    const router = new ethers.Contract(routerAddress, routerAbi, provider);
    // router.getAmountsOut expects same units as parseUnits used for amountIn
    const amounts = await router.getAmountsOut(amountInBigInt, path);
    // ethers v6 returns BigInt elements — keep them as-is
    return amounts;
  } catch (e) {
    // route missing / no liquidity / RPC error
    return null;
  }
}


async function checkBridgeAllowance(fromNetwork, fromToken, fromAddress, fromAmount) {
    let bridge_contract = BRIDGE_CONTRACT[fromNetwork];
    const fromProvider = new ethers.JsonRpcProvider(RPC_URL[fromNetwork]);
    const TokenFrom = TOKENS?.[fromNetwork]?.[fromToken];
    if (!TokenFrom) {
        throw new Error("Token configuration not found.");
    }
    let requireAllowance = false; 
    // 1. Create the contract instance

    const token_address = fromNetwork == 'hedera' ? convertHederaIdToEVMAddress(TokenFrom.address) : TokenFrom.address;  

    if(fromNetwork == 'hedera'){
        bridge_contract = convertHederaIdToEVMAddress(bridge_contract); 
        const client = Client.forMainnet().setOperator(
          AccountId.fromString(HEDERA_OPERATOR_ADDRESS),
          PrivateKey.fromStringECDSA(OPERATOR_PRIVATE_KEY)
        );
        fromAddress = await getEvmAddressFromAccountId(fromAddress, client);
    }

    const fromTokenContract = new ethers.Contract(token_address, erc20Abi, fromProvider);
    // 2. Truncate decimal amount string
    // This is the human-readable value (e.g., "10.12")
    const fromAmountStr = truncateDecimals(String(fromAmount), TokenFrom.decimals);
    // 3. Convert human-readable amount to the token's native BigInt (wei)
    // This is the amount the user intends to spend (e.g., 10120000000000000000)
    const amountInWei = ethers.parseUnits(fromAmountStr, TokenFrom.decimals);
    
    // 4. Check if user needs to approve bridge contract
    const allowance = await fromTokenContract.allowance(fromAddress, bridge_contract); 

    console.log('bridge from contract', bridge_contract)
    console.log('bridge from network', fromNetwork)
    console.log('allowance', allowance)
    console.log('amount in wei', amountInWei)
    console.log('sender in wei', fromAddress)
    console.log('token from contract',  token_address)

    console.log()
    // 5. Compare the two BigInt values directly
    if (allowance < amountInWei) {
      requireAllowance = true; 
    }
    return requireAllowance;
}

app.post("/bridge/precheck", async (req, res) => {

  console.log("All body", req.body)
  try {
    const { network, token, amount, nativeAmount, fromNetwork, fromAddress, fromToken, fromAmount } = req.body;

    // --- Basic validation ---
    if (!network || !token) {
      return res.status(400).json({ canBridge: false, message: "Missing network or token" });
    }

    if (!RPC_URL?.[network]) {
      return res.status(400).json({ canBridge: false, message: `Unsupported network: ${network}` });
    }

    const Token = TOKENS?.[network]?.[token];
    if (!Token) {
      return res.status(400).json({ canBridge: false, message: "Unsupported token for the specified network" });
    }

    // amount: amount of token user wants to bridge (in token units, e.g. "1.5")
    // nativeAmount: amount of native token (in native units) intended to be used for swapping if token not present
    if (typeof amount === "undefined" || typeof nativeAmount === "undefined") {
      return res.status(400).json({ canBridge: false, message: "Missing amount or nativeAmount" });
    }

    // parse amounts as strings to preserve precision then parseUnits later
    const amountStr = truncateDecimals(String(amount), Token.decimals);
    const nativeAmountStr = String(nativeAmount);

    // Validate numeric-ish strings
    if (isNaN(Number(amountStr)) || Number(amountStr) <= 0) {
      return res.status(400).json({ canBridge: false, message: "Invalid token amount" });
    }
    if (isNaN(Number(nativeAmountStr)) || Number(nativeAmountStr) < 0) {
      return res.status(400).json({ canBridge: false, message: "Invalid nativeAmount" });
    }

    let poolHasFunds = false;
    let estimatedOutBigInt = null;
    let slippageOk = true;
    const provider = new ethers.JsonRpcProvider(RPC_URL[network]);



    let requireAllowance = false; 

    const TokenFrom = TOKENS?.[fromNetwork]?.[fromToken];

    if(!TokenFrom.native){
        requireAllowance = await checkBridgeAllowance(fromNetwork, fromToken, fromAddress, fromAmount); 
    }

    // --- HEDERA PATH (native HTS or HTS swap fallback) ---
    if (network === "hedera") {
      // Setup Hedera client for operator balance read
      const client = Client.forTestnet().setOperator(
        AccountId.fromString(HEDERA_OPERATOR_ADDRESS),
        PrivateKey.fromStringECDSA(OPERATOR_PRIVATE_KEY)
      );

      const poolBal = await new AccountBalanceQuery()
        .setAccountId(AccountId.fromString(HEDERA_OPERATOR_ADDRESS))
        .execute(client);

      // Convert HBAR balance to tinybars BigInt
      const poolHbarTinybars = BigInt(poolBal.hbars.toTinybars().toString());

      if (Token.native) {
        // Token is HBAR native => check pool native balance
        const requiredTinybars = safeHbar(amountStr).toTinybars().toString ? BigInt(safeHbar(amountStr).toTinybars().toString()) : BigInt(0);
        poolHasFunds = poolHbarTinybars >= requiredTinybars;
      } else {
        // Token is HTS (non-native). AccountBalanceQuery returns token balances map.
        // Implementation detail: SDK exposes tokens as `poolBal.tokens` — convert value to BigInt safely.
        const tokenBal = poolBal.tokens._map ? poolBal.tokens._map.get(Token.address) : undefined;
        // tokenBal may be a Long-like object — convert to string then BigInt
        const tokenBalBigInt = tokenBal ? BigInt(tokenBal.toString()) : BigInt(0);
        // Required token base units

        const requiredTokenBase = BigInt(ethers.parseUnits(amountStr, Token.decimals).toString());
        poolHasFunds = tokenBalBigInt >= requiredTokenBase;
      }

      // If pool lacks token but might have native HBAR for swap, do fallback
      if (!poolHasFunds && !Token.native) {
        // check pool native amount for swap
        const requiredNativeTinybars = safeHbar(nativeAmountStr).toTinybars().toString ? BigInt(safeHbar(nativeAmountStr).toTinybars().toString()) : BigInt(0);
        if (poolHbarTinybars >= requiredNativeTinybars) {
          // simulate EVM swap on Hedera EVM: wrapped native (WHBAR) -> target token
          const routerAddress = ROUTER[network];
          const wrappedNativeAddress = WRAPPED_NATIVE[network];
          // convert HTS id to EVM token address if necessary
          const tokenOutAddress = Token.address.startsWith("0x") ? Token.address : convertHederaIdToEVMAddress(Token.address);
          const path = [wrappedNativeAddress, tokenOutAddress];

          // For Hedera EVM wrapped tokens we use typical 18 decimals on the EVM side
          const amountInBigInt = BigInt(ethers.parseUnits( truncateDecimals(nativeAmountStr, EVM_NATIVE_DECIMALS), EVM_NATIVE_DECIMALS).toString());
          const amounts = await getEvmAmountsOut(amountInBigInt, path, routerAddress, provider);

          if (amounts && amounts.length > 1) {
            estimatedOutBigInt = amounts[1]; // bigint
            // slippage calc (bigint)
            const amountOutMin = estimatedOutBigInt - (estimatedOutBigInt * BigInt(Math.floor(SLIPPAGE_TOLERANCE * 1000)) / BigInt(1000));
            slippageOk = amountOutMin <= estimatedOutBigInt && estimatedOutBigInt > 0n;
            poolHasFunds = slippageOk; // if slippage OK treat as pool has funds for swap path
          } else {
            slippageOk = false;
          }
        }
      }
    } else {
      // --- EVM networks (ethereum, bsc, etc.) ---
      if (Token.native) {
        // pool native balance (ETH/BNB/etc.) - provider.getBalance returns bigint
        const bal = await provider.getBalance(EVM_OPERATOR_ADDRESS);
        const required = BigInt(ethers.parseUnits(amountStr, EVM_NATIVE_DECIMALS).toString());
        poolHasFunds = bal >= required;
      } else {
        // ERC20 token check
        const tokenContract = new ethers.Contract(Token.address, erc20Abi, provider);
        const bal = await tokenContract.balanceOf(EVM_OPERATOR_ADDRESS);
        const requiredTokenBase = BigInt(ethers.parseUnits(amountStr, Token.decimals).toString());
        poolHasFunds = BigInt(bal.toString()) >= requiredTokenBase;
        // fallback: if operator doesn't hold token, check native for swap
        if (!poolHasFunds) {
          const nativeBal = await provider.getBalance(EVM_OPERATOR_ADDRESS);
          const requiredNative = BigInt(ethers.parseUnits( truncateDecimals(nativeAmountStr, EVM_NATIVE_DECIMALS), EVM_NATIVE_DECIMALS).toString());
          if (nativeBal >= requiredNative) {
            const routerAddress = ROUTER[network];
            const wrappedNativeAddress = WRAPPED_NATIVE[network];
            const path = [wrappedNativeAddress, Token.address];
            const amountInBigInt = BigInt(ethers.parseUnits( truncateDecimals(nativeAmountStr, EVM_NATIVE_DECIMALS), EVM_NATIVE_DECIMALS).toString());
            const amounts = await getEvmAmountsOut(amountInBigInt, path, routerAddress, provider);

            if (amounts && amounts.length > 1) {
              estimatedOutBigInt = amounts[1];
              const amountOutMin = estimatedOutBigInt - (estimatedOutBigInt * BigInt(Math.floor(SLIPPAGE_TOLERANCE * 1000))) / BigInt(1000);
              slippageOk = amountOutMin <= estimatedOutBigInt && estimatedOutBigInt > 0n;
              poolHasFunds = slippageOk;
            } else {
              slippageOk = false;
            }
          }
        }
      }
    }

    if (!poolHasFunds) {
      return res.json({
        canBridge: false,
        message: "Operator/pool has insufficient liquidity",
      });
    }

    if (!slippageOk) {
      return res.json({
        canBridge: false,
        message: "Slippage too high or no liquidity path available",
      });
    }

    // Format estimatedOut if present — use token decimals if non-native; otherwise use native decimals
    let estimatedOutFormatted = null;
    if (estimatedOutBigInt) {
      const decimals = Token.native ? (network === "hedera" ? HEDERA_NATIVE_DECIMALS : EVM_NATIVE_DECIMALS) : Token.decimals;
      estimatedOutFormatted = ethers.formatUnits(estimatedOutBigInt, decimals);
    }

    return res.json({
      canBridge: true,
      message: "Precheck passed",
      estimatedOut: estimatedOutFormatted,
      requireAllowance: requireAllowance
    });

  } catch (err) {
    console.error("Precheck error:", err);
    return res.status(500).json({
      canBridge: false,
      message: "Server error during precheck",
      error: err?.message ?? String(err),
    });
  }
});




app.post("/bridge/execute", async (req, res) => {
  try {
    const { network, token, amount, nativeAmount, recipient } = req.body;

    if (!network || !token || !amount || !nativeAmount || !recipient) {
      return res.status(400).json({ error: "Missing required parameters" });
    }

    console.log(req.body)

    const parsedAmount = Number(amount);
    const parsedNativeAmount = Number(nativeAmount);
    if (isNaN(parsedAmount) || parsedAmount <= 0 || isNaN(parsedNativeAmount) || parsedNativeAmount < 0) {
      return res.status(400).json({ error: "Invalid amount or nativeAmount" });
    }

    const Token = TOKENS[network][token];
    if (!Token) {
      return res.status(400).json({ error: "Unsupported token for this network" });
    }

    const provider = new ethers.JsonRpcProvider(RPC_URL[network]);
    const wallet = new ethers.Wallet(OPERATOR_PRIVATE_KEY, provider);

    // ------------------------------------------------------------------
    // 🟣 HEDERA NETWORK
    // ------------------------------------------------------------------
    if (network === "hedera") {
      const client = Client.forTestnet().setOperator(
        AccountId.fromString(HEDERA_OPERATOR_ADDRESS),
        PrivateKey.fromStringECDSA(OPERATOR_PRIVATE_KEY)
      );

      const poolBal = await new AccountBalanceQuery()
        .setAccountId(AccountId.fromString(HEDERA_OPERATOR_ADDRESS))
        .execute(client);

      const hbarBalance = BigInt(poolBal.hbars.toTinybars().toString());
      const requiredTinybars = BigInt(safeHbar(parsedNativeAmount).toTinybars().toString());
      let hasTokenFunds = false;

      if (!Token.native) {
        const tokenBal = poolBal.tokens._map.get(Token.address);
        const tokenBalTiny = tokenBal ? BigInt(tokenBal.toString()) : 0n;
        const requiredTokenTiny = BigInt(ethers.parseUnits(truncateDecimals(parsedAmount.toString(), Token.decimals), Token.decimals).toString());
        hasTokenFunds = tokenBalTiny >= requiredTokenTiny;
      }

      // ✅ CASE 1: Native HBAR transfer
      if (Token.native) {
        const tx = await new TransferTransaction()
          .addHbarTransfer(HEDERA_OPERATOR_ADDRESS, safeHbar(parsedAmount).negated())
          .addHbarTransfer(recipient, safeHbar(parsedAmount))
          .execute(client);

        const receipt = await tx.getReceipt(client);
        return res.json({
          status: "success",
          message: "HBAR native transfer successful",
          txHash: tx.transactionId.toString(),
          network,
          type: "native-transfer",
          statusText: receipt.status.toString(),
        });
      }

      // ✅ CASE 2: HTS Token transfer if pool has funds
      if (!Token.native && hasTokenFunds) {
        const tx = await new TransferTransaction()
          .addTokenTransfer(Token.address, HEDERA_OPERATOR_ADDRESS, -parsedAmount * 10 ** Token.decimals)
          .addTokenTransfer(Token.address, recipient, parsedAmount * 10 ** Token.decimals)
          .execute(client);

        const receipt = await tx.getReceipt(client);
        return res.json({
          status: "success",
          message: `${Token.symbol} HTS token transfer successful`,
          txHash: tx.transactionId.toString(),
          network,
          type: "token-transfer",
          statusText: receipt.status.toString(),
        });
      }

      // ✅ CASE 3: Swap only if token insufficient and native balance enough
      if (!hasTokenFunds && hbarBalance >= requiredTinybars) {
        console.log(`🔄 Insufficient ${Token.symbol}, swapping native HBAR...`);
        const router = new ethers.Contract(ROUTER[network], routerAbi, wallet);
        const wrappedNative = WRAPPED_NATIVE[network];
        const tokenAddress = convertHederaIdToEVMAddress(Token.address);

        const amountIn = ethers.parseUnits(truncateDecimals(parsedNativeAmount.toString(), 18), 18);
        const path = [wrappedNative, tokenAddress];
        console.log('parsed amount', parsedNativeAmount.toString())
        console.log('the amount in', amountIn)

        const amounts = await router.getAmountsOut(amountIn, path);
        if (!amounts || amounts.length === 0) throw new Error("No liquidity path available");

        const slippage = 0.005;
        const amountOutMin = amounts[1] - (amounts[1] * BigInt(Math.floor(slippage * 1000))) / BigInt(1000);

        const evmRecipient = await getEvmAddressFromAccountId(recipient, client);

        const tx = await router.swapExactETHForTokens(
          amountOutMin,
          path,
          evmRecipient,
          Math.floor(Date.now() / 1000) + 60 * 10,
          { value: amountIn, gasLimit: BigInt(1_000_000) }
        );

        const receipt = await tx.wait();
        return res.json({
          status: "success",
          message: "Swap completed successfully",
          txHash: receipt.hash,
          network,
          type: "swap",
          blockNumber: receipt.blockNumber,
        });
      }

      throw new Error("Insufficient pool liquidity for both token and native swap");
    }

    // ------------------------------------------------------------------
    // 🟢 EVM NETWORKS
    // ------------------------------------------------------------------
    else {
      const tokenContract = !Token.native ? new ethers.Contract(Token.address, erc20Abi, provider) : null;
      let poolTokenBal = 0n;
      let poolNativeBal = await provider.getBalance(EVM_OPERATOR_ADDRESS);

      if (!Token.native) {
        const bal = await tokenContract.balanceOf(EVM_OPERATOR_ADDRESS);
        poolTokenBal = BigInt(bal.toString());
      }

      const requiredToken = BigInt(ethers.parseUnits( truncateDecimals(parsedAmount.toString(), Token.decimals), Token.decimals ).toString());
      const requiredNative = BigInt(ethers.parseUnits( truncateDecimals(parsedNativeAmount.toString(), 18), 18).toString() );

      // ✅ CASE 1: Native transfer
      if (Token.native) {
        const tx = await wallet.sendTransaction({
          to: recipient,
          value: requiredToken,
        });
        const receipt = await tx.wait();
        return res.json({
          status: "success",
          message: "Native transfer successful",
          txHash: receipt.hash,
          network,
          type: "native-transfer",
          blockNumber: receipt.blockNumber,
        });
      }

      // ✅ CASE 2: ERC20 direct transfer if sufficient
      if (!Token.native && poolTokenBal >= requiredToken) {
        const tx = await tokenContract.connect(wallet).transfer(recipient, requiredToken);
        const receipt = await tx.wait();
        return res.json({
          status: "success",
          message: `${Token.symbol} ERC20 transfer successful`,
          txHash: receipt.hash,
          network,
          type: "token-transfer",
          blockNumber: receipt.blockNumber,
        });
      }

      // ✅ CASE 3: Fallback swap only if pool native >= requiredNative
      if (poolNativeBal >= requiredNative) {
        console.log(`🔄 Pool low on ${Token.symbol}, performing swap...`);
        const router = new ethers.Contract(ROUTER[network], routerAbi, wallet);
        const wrappedNative = WRAPPED_NATIVE[network];
        const path = [wrappedNative, Token.address];
        const amounts = await router.getAmountsOut(requiredNative, path);
        if (!amounts || amounts.length === 0) throw new Error("No liquidity path available");

        console.log('token paths')
        console.log(path)

        const amountOutMin = amounts[1] - (amounts[1] * BigInt(Math.floor(SLIPPAGE_TOLERANCE * 1000))) / BigInt(1000);
        const tx = await router.swapExactETHForTokens(
          amountOutMin,
          path,
          recipient,
          Math.floor(Date.now() / 1000) + 600,
          { value: requiredNative, gasLimit: BigInt(1_000_000) }
        );
        const receipt = await tx.wait();
        return res.json({
          status: "success",
          message: "Swap completed successfully",
          txHash: receipt.hash,
          network,
          type: "swap",
          blockNumber: receipt.blockNumber,
        });
      }

      throw new Error("Insufficient liquidity for transfer or swap");
    }
  } catch (err) {
    console.error("❌ Execute error:", err);
    res.status(500).json({ status: "failed", error: err.message });
  }
});


// run server
const PORT = 3000;
app.listen(PORT, () => console.log(`Bridge service running on port ${PORT}`));
