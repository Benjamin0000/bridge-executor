import axios from "axios";
import { ethers } from "ethers";

const MIRROR = "https://mainnet-public.mirrornode.hedera.com";
const CONTRACT_ID = "0.0.10115692";
const TARGET_ENDPOINT = "https://hedera-api.kivon.io/api/bridge";

const iface = new ethers.Interface([
  "event BridgeDeposit(string indexed nonce,address indexed from,address indexed tokenFrom,int64 amount,address to,address tokenTo,address poolAddress,uint64 desChain)"
]);

let lastTimestamp = "0";

async function pollBridgeDeposits() {
  try {
    const url = `${MIRROR}/api/v1/contracts/${CONTRACT_ID}/results/logs?order=asc&limit=10&timestamp=gt:${lastTimestamp}`;
    const res = await axios.get(url);

    if (!res.data.logs || res.data.logs.length === 0) return;

    for (const log of res.data.logs) {
      // Only decode if this log matches BridgeDeposit
      const decoded = tryDecode(log);
      if (!decoded) continue;

      console.log("🔥 BridgeDeposit found:", decoded);

    // send to  backend
    await axios.post(TARGET_ENDPOINT, decoded);

    console.log("sending to server payload")

      // update polling cursor
      lastTimestamp = log.timestamp;
    }
  } catch (err) {
    console.error("Polling error:", err.message);
  }
}

function tryDecode(log) {
  try {
    const parsed = iface.parseLog({
      data: log.data,
      topics: log.topics,
    });

    if (parsed.name !== "BridgeDeposit") return null;

    // parsed.args contains all event fields
    return {
      nonceHash: parsed.args.nonce.hash,
      from: parsed.args.from,
      tokenFrom: parsed.args.tokenFrom,
      amount: Number(parsed.args.amount),
      to: parsed.args.to,
      tokenTo: parsed.args.tokenTo,
      poolAddress: parsed.args.poolAddress,
      desChain: Number(parsed.args.desChain),
      txHash: log.transaction_hash,
      timestamp: log.timestamp,
    };

  } catch {
    return null; // ignore non-matching events
  }
}

setInterval(pollBridgeDeposits, 2000);