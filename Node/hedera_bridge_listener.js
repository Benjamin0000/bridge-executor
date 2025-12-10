import axios from "axios";
import { ethers } from "ethers";
import fs from "fs";
import path from "path";
dotenv.config({path: process.env.DOTENV_CONFIG_PATH});


// ------------------------------
// Config
// ------------------------------
const MIRROR = "https://mainnet-public.mirrornode.hedera.com";
const CONTRACT_ID = "0.0.10115692";
const TARGET_ENDPOINT = "https://hedera-api.kivon.io/api/bridge";
const POLL_INTERVAL = 2000; // 2 seconds
const CURSOR_FILE = path.join(process.cwd(), "lastHederaBridgeTimestamp.json");

const iface = new ethers.Interface([
  "event BridgeDeposit(string indexed nonce,address indexed from,address indexed tokenFrom,int64 amount,address to,address tokenTo,address poolAddress,uint64 desChain)"
]);

// ------------------------------
// Load or initialize lastTimestamp
// ------------------------------
function loadCursor() {
  if (fs.existsSync(CURSOR_FILE)) {
    try {
      const data = JSON.parse(fs.readFileSync(CURSOR_FILE, "utf-8"));
      return data.lastTimestamp || "0";
    } catch {
      return "0";
    }
  }
  return "0";
}

function saveCursor(ts) {
  fs.writeFileSync(CURSOR_FILE, JSON.stringify({ lastTimestamp: ts }, null, 2));
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
      amount: Number(parsed.args.amount), // safe conversion
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
// Polling function
// ------------------------------
async function pollBridgeDeposits() {
  try {
    const url = `${MIRROR}/api/v1/contracts/${CONTRACT_ID}/results/logs?order=asc&limit=10&timestamp=gt:${lastTimestamp}`;
    const res = await axios.get(url);

    if (!res.data.logs || res.data.logs.length === 0) return;

    for (const log of res.data.logs) {
      const decoded = tryDecode(log);
      if (!decoded) continue;

      console.log("🔥 BridgeDeposit found:", decoded);

      try {
        await axios.post(TARGET_ENDPOINT, decoded, {
          headers: {
            "X-Bridge-Secret": process.env.BRIDGE_INDEXER_KEY
          }
        });
        console.log("✅ Sent to backend:", decoded.txHash);
      } catch (err) {
        console.error("❌ Error sending to backend:", err.message);
      }
      // Update cursor after successful processing
      lastTimestamp = log.timestamp;
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
