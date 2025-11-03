import { ethers } from "ethers";
import axios from "axios";
import dotenv from "dotenv";
import { networks } from "./networks.js";
import abi from "./abi.json" assert { type: "json" };

dotenv.config();

async function saveToLaravel(data) {
  try {
    const res = await axios.post(process.env.LARAVEL_API_URL, data, {
    //   headers: {
    //     Authorization: `Bearer ${process.env.LARAVEL_API_KEY}`,
    //     "Content-Type": "application/json",
    //   },
    });
    console.log(`✅ [${data.source_chain}] Deposit saved: ${data.nouns}`);
  } catch (err) {
    console.error(`❌ [${data.source_chain}] Laravel error:`, err.response?.data || err.message);
  }
}

async function startListener(network) {
  const provider = new ethers.JsonRpcProvider(network.rpc);
  const contract = new ethers.Contract(network.contract, abi, provider);

  console.log(`👂 Listening on ${network.name.toUpperCase()}...`);

  contract.on("BridgeDeposit", async (nouns, from, tokenFrom, amount, to, tokenTo, poolAddress, event) => {
    try {
      const block = await provider.getBlock(event.blockNumber);
      const deposit = {
        nouns,
        depositor: from,
        token_from: tokenFrom,
        token_to: tokenTo,
        pool_address: poolAddress,
        to,
        amount: amount.toString(),
        timestamp: block.timestamp,
        tx_hash: event.transactionHash,
        source_chain: network.name,
        destination_chain: network.destinationChain,
        status: "pending",
      };

      console.log(`🌉 [${network.name}] New deposit detected: ${nouns}`);
      // await saveToLaravel(deposit);
    } catch (e) {
      console.error(`⚠️ [${network.name}] Listener error:`, e);
    }
  });
}

// Start all network listeners
for (const net of networks) {
  startListener(net);
}
