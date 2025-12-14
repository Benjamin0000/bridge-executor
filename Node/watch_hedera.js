import axios from "axios";
import fs from "fs";
import path from "path";
import dotenv from "dotenv";

dotenv.config({ path: process.env.DOTENV_CONFIG_PATH });

// ------------------------------
// Config
// ------------------------------
const MONITORED_ACCOUNT = "0.0.10145769";
const BACKEND_URL = "https://hedera-api.kivon.io/api/add-liquidity";
const MIRROR_BASE = "https://mainnet-public.mirrornode.hedera.com";

const POLL_INTERVAL = 5000;
const CURSOR_FILE = path.resolve("./last-hbar-deposit-cursor.json");

// ------------------------------
// Cursor helpers (timestamp + lastTxId)
// ------------------------------
function loadCursor() {
  try {
    if (fs.existsSync(CURSOR_FILE)) {
      return JSON.parse(fs.readFileSync(CURSOR_FILE, "utf8"));
    }
  } catch {}
  return { timestamp: null, lastTxId: null };
}

function saveCursor(cursor) {
  fs.writeFileSync(CURSOR_FILE, JSON.stringify(cursor, null, 2));
}

let cursor = loadCursor();

// ------------------------------
// Polling logic
// ------------------------------
async function pollHederaDeposits() {
  try {
    let nextUrl =
      `${MIRROR_BASE}/api/v1/transactions` +
      `?account.id=${MONITORED_ACCOUNT}` +
      `&limit=100` +
      `&order=asc` +
      (cursor.timestamp ? `&timestamp=gte:${cursor.timestamp}` : "");

    let nextCursor = { ...cursor };

    while (nextUrl) {
      const { data } = await axios.get(nextUrl);

      for (const tx of data.transactions ?? []) {
        // 🔒 Skip exact last processed transaction
        if (
          tx.consensus_timestamp === cursor.timestamp &&
          tx.transaction_id === cursor.lastTxId
        ) {
          continue;
        }

        const transfers = tx.transfers;
        if (!transfers?.length) continue;

        const deposit = transfers.find(
          t => t.account === MONITORED_ACCOUNT && t.amount > 0
        );
        if (!deposit) continue;

        const sender =
          transfers.find(t => t.amount < 0 && t.account !== MONITORED_ACCOUNT)
            ?.account || "unknown";

        const amountHbar = deposit.amount / 1e8;

        try {
          await axios.post(
            BACKEND_URL,
            {
              wallet_address: sender,
              network: "hedera",
              amount: amountHbar,
              txId: tx.transaction_id,
            },
            {
              headers: {
                "X-Bridge-Secret": process.env.BRIDGE_INDEXER_KEY,
              },
            }
          );

          console.log(
            `✅ HBAR deposit: ${amountHbar} HBAR from ${sender} (${tx.transaction_id})`
          );

          // advance cursor ONLY after successful POST
          nextCursor.timestamp = tx.consensus_timestamp;
          nextCursor.lastTxId = tx.transaction_id;

        } catch (err) {
          console.error("❌ Backend error:", err.message);
          return; // retry next poll without committing cursor
        }
      }

      nextUrl = data.links?.next
        ? `${MIRROR_BASE}${data.links.next}`
        : null;
    }

    // ✅ Commit cursor once per successful poll
    cursor = nextCursor;
    saveCursor(cursor);

  } catch (err) {
    console.error("Polling error:", err.message);
  }
}

// ------------------------------
// Non-overlapping polling loop
// ------------------------------
async function loop() {
  await pollHederaDeposits();
  setTimeout(loop, POLL_INTERVAL);
}

console.log("🚀 Hedera HBAR deposit monitor running...");
loop();
