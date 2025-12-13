import axios from "axios";
import { ethers } from "ethers";
import fs from "fs";
import path from "path";
import dotenv from "dotenv";

dotenv.config({ path: process.env.DOTENV_CONFIG_PATH });

// ------------------------------
// Config
// ------------------------------
const MIRROR = "https://mainnet-public.mirrornode.hedera.com";
const CONTRACT_ID = "0.0.10115692";
const TARGET_ENDPOINT = "https://hedera-api.kivon.io/api/bridge";
const POLL_INTERVAL = 2000;
const CURSOR_FILE = path.join(process.cwd(), "lastHederaBridgeTimestamp.json");

// use higher limit safely
const PAGE_LIMIT = 100;

const iface = new ethers.Interface([
  "event BridgeDeposit(string indexed nonce,address indexed from,address indexed tokenFrom,int64 amount,address to,address tokenTo,address poolAddress,uint64 desChain)"
]);

// ------------------------------
// Cursor helpers
// ------------------------------
function loadCursor() {
  try {
    if (fs.existsSync(CURSOR_FILE)) {
      const data = JSON.parse(fs.readFileSync(CURSOR_FILE, "utf-8"));
      return data.lastTimestamp || "0";
    }
  } catch {}
  return "0";
}

function saveCursor(ts) {
  fs.writeFileSync(
    CURSOR_FILE,
    JSON.stringify({ lastTimestamp: ts }, null, 2)
  );
}

let lastTimestamp = loadCursor();

// ------------------------------
// Decode BridgeDeposit logs
// ------------------------------
function tryDecode(log) {
  try {
    const parsed = iface.parseLog({
      data: log.data,
      topics: log.topics,
    });

    if (parsed.name !== "BridgeDeposit") return null;

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
    return null;
  }
}

// ------------------------------
// Polling with pagination
// ------------------------------
async function pollBridgeDeposits() {
  try {
    let nextUrl =
      `${MIRROR}/api/v1/contracts/${CONTRACT_ID}/results/logs` +
      `?order=asc` +
      `&limit=${PAGE_LIMIT}` +
      `&timestamp=gt:${lastTimestamp}`;

    let maxSeenTimestamp = lastTimestamp;

    while (nextUrl) {
      const res = await axios.get(nextUrl);
      const logs = res.data.logs || [];

      for (const log of logs) {
        const decoded = tryDecode(log);
        if (!decoded) continue;

        try {
          await axios.post(TARGET_ENDPOINT, decoded, {
            headers: {
              "X-Bridge-Secret": process.env.BRIDGE_INDEXER_KEY,
            },
          });

          console.log("✅ BridgeDeposit sent:", decoded.txHash);
          maxSeenTimestamp = log.timestamp;
        } catch (err) {
          console.error("❌ Backend error:", err.message);
          return; // stop & retry next poll (no cursor advance)
        }
      }

      nextUrl = res.data.links?.next
        ? `${MIRROR}${res.data.links.next}`
        : null;
    }

    // ✅ Commit cursor once, safely
    if (maxSeenTimestamp !== lastTimestamp) {
      lastTimestamp = maxSeenTimestamp;
      saveCursor(lastTimestamp);
    }
  } catch (err) {
    console.error("Polling error:", err.message);
  }
}

// ------------------------------
// Start polling loop
// ------------------------------
console.log("🚀 Hedera BridgeDeposit indexer running...");
setInterval(pollBridgeDeposits, POLL_INTERVAL);
