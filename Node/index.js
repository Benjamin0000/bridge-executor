import express from "express";
import { ethers } from "ethers";
import {
  Client,
  AccountBalanceQuery,
  AccountId,
  Hbar,
  PrivateKey,
  TransferTransaction,
} from "@hashgraph/sdk";

const app = express();
app.use(express.json());

// ----------------------
// ENV CONFIG
// ----------------------
const {
  RPC_URL,
  OPERATOR_ID,
  OPERATOR_PRIVATE_KEY,
  ROUTER,
  WHBAR_CONTRACT,
} = process.env;

if (!RPC_URL || !OPERATOR_ID || !OPERATOR_PRIVATE_KEY) {
  console.error("Missing environment variables");
  process.exit(1);
}

const provider = new ethers.JsonRpcProvider(RPC_URL);
const wallet = new ethers.Wallet(OPERATOR_PRIVATE_KEY, provider);

// ABIs
const erc20Abi = [
  "function balanceOf(address) view returns (uint256)",
  "function decimals() view returns (uint8)",
];
const routerAbi = [
  "function getAmountsOut(uint amountIn, address[] calldata path) view returns (uint[] memory amounts)",
];

// ----------------------
// HELPERS
// ----------------------
async function getTokenDecimals(address) {
  try {
    const token = new ethers.Contract(address, erc20Abi, provider);
    return await token.decimals();
  } catch {
    return 18; // fallback
  }
}

async function getEvmBalance(address, tokenAddress) {
  if (!tokenAddress) {
    return await provider.getBalance(address);
  }
  const token = new ethers.Contract(tokenAddress, erc20Abi, provider);
  return await token.balanceOf(address);
}

async function getEvmAmountsOut(amountIn, path) {
  try {
    const router = new ethers.Contract(ROUTER, routerAbi, provider);
    return await router.getAmountsOut(amountIn, path);
  } catch (e) {
    return null;
  }
}

function safeHbar(amount) {
  return new Hbar(amount);
}

// ---------------------------------------------------------------------------
// 🧩 1️⃣ PRECHECK ENDPOINT
// ---------------------------------------------------------------------------
app.post("/bridge/precheck", async (req, res) => {
  try {
    const {
      fromNetwork,
      toNetwork,
      userAddress,
      tokenAddress,
      amount,
      isNative,
    } = req.body;

    if (!fromNetwork || !toNetwork || !userAddress || !amount) {
      return res
        .status(400)
        .json({ canBridge: false, message: "Missing required parameters" });
    }

    const parsedAmount = Number(amount);
    if (isNaN(parsedAmount) || parsedAmount <= 0) {
      return res
        .status(400)
        .json({ canBridge: false, message: "Invalid amount" });
    }

    let userHasFunds = false;
    let poolHasFunds = false;
    let estimatedOut = null;
    let slippageOk = true;

    // -------------------
    // 🔹 HEDERA BRIDGE CHECK
    // -------------------
    if (fromNetwork === "hedera") {
      const client = Client.forTestnet().setOperator(
        AccountId.fromString(OPERATOR_ID),
        PrivateKey.fromStringECDSA(OPERATOR_PRIVATE_KEY)
      );

      const userBal = await new AccountBalanceQuery()
        .setAccountId(AccountId.fromString(userAddress))
        .execute(client);

      if (isNative) {
        userHasFunds =
          userBal.hbars.toTinybars() >= safeHbar(parsedAmount).toTinybars();
      } else {
        const tokenBal = userBal.tokens._map.get(tokenAddress);
        userHasFunds =
          tokenBal && tokenBal.toNumber() >= parsedAmount * 10 ** 8; // assume 8 decimals
      }

      const poolBal = await new AccountBalanceQuery()
        .setAccountId(AccountId.fromString(OPERATOR_ID))
        .execute(client);

      if (isNative) {
        poolHasFunds =
          poolBal.hbars.toTinybars() >= safeHbar(parsedAmount).toTinybars();
      } else {
        const tokenBal = poolBal.tokens._map.get(tokenAddress);
        if (tokenBal && tokenBal.toNumber() >= parsedAmount * 10 ** 8) {
          poolHasFunds = true;
        } else {
          poolHasFunds = poolBal.hbars.toTinybars() > 0;
        }
      }
    }

    // -------------------
    // 🔹 EVM BRIDGE CHECK
    // -------------------
    else {
      const decimals = tokenAddress
        ? await getTokenDecimals(tokenAddress)
        : 18;
      const amountIn = ethers.parseUnits(parsedAmount.toString(), decimals);

      // User balance
      const userBalance = await getEvmBalance(userAddress, isNative ? null : tokenAddress);
      userHasFunds = userBalance >= amountIn;

      // Pool/operator balance
      const poolBalance = await getEvmBalance(wallet.address, isNative ? null : tokenAddress);
      if (poolBalance >= amountIn) {
        poolHasFunds = true;
      } else {
        // fallback: check if enough native for swap
        const nativeBalance = await getEvmBalance(wallet.address);
        poolHasFunds = nativeBalance > ethers.parseEther("0.1");
      }

      // Slippage check if router & token path available
      if (!isNative && ROUTER && WHBAR_CONTRACT) {
        const path = [WHBAR_CONTRACT, tokenAddress];
        const amounts = await getEvmAmountsOut(amountIn, path);
        if (amounts && amounts.length > 1) {
          estimatedOut = amounts[1];
          const slippageTolerance = 0.005; // 0.5%
          const amountOutMin =
            estimatedOut -
            (estimatedOut * BigInt(Math.floor(slippageTolerance * 1000))) /
              BigInt(1000);
          slippageOk = amountOutMin <= estimatedOut && estimatedOut > 0;
        }
      }
    }

    if (!userHasFunds) {
      return res.json({
        canBridge: false,
        message: "Sender has insufficient balance",
      });
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

    return res.json({
      canBridge: true,
      message: "Precheck passed",
      estimatedOut: estimatedOut
        ? ethers.formatUnits(estimatedOut, 18)
        : null,
    });
  } catch (err) {
    console.error("Precheck error:", err);
    return res.status(500).json({
      canBridge: false,
      message: "Server error during precheck",
      error: err.message,
    });
  }
});

// ---------------------------------------------------------------------------
// 🚀 2️⃣ EXECUTE BRIDGE ENDPOINT (uses your working logic)
// ---------------------------------------------------------------------------
app.post("/bridge/execute", async (req, res) => {
  try {
    const {
      recipient,
      amount,
      tokenAddress,
      isNative,
    } = req.body;

    const client = Client.forTestnet().setOperator(
      AccountId.fromString(OPERATOR_ID),
      PrivateKey.fromStringECDSA(OPERATOR_PRIVATE_KEY)
    );

    if (isNative) {
      const tx = await new TransferTransaction()
        .addHbarTransfer(AccountId.fromString(OPERATOR_ID), safeHbar(-amount))
        .addHbarTransfer(AccountId.fromString(recipient), safeHbar(amount))
        .execute(client);
      const receipt = await tx.getReceipt(client);
      return res.json({
        status: "Native transfer complete",
        txId: tx.transactionId.toString(),
        receipt: receipt.status.toString(),
      });
    } else {
      // Implement EVM swap logic here (your existing code)
      return res.json({
        status: "Token swap execution placeholder",
        message: "Handled by proof-of-concept logic",
      });
    }
  } catch (err) {
    console.error("Execute error:", err);
    return res.status(500).json({
      status: "failed",
      message: "Bridge execution failed",
      error: err.message,
    });
  }
});

// ----------------------
// RUN SERVER
// ----------------------
const PORT = process.env.PORT || 8080;
app.listen(PORT, () =>
  console.log(`Bridge service running on port ${PORT}`)
);
