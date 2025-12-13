import axios from 'axios';
import fs from 'fs';
import path from 'path';

const MONITORED_ACCOUNT = '0.0.10145769';
const BACKEND_URL = 'https://hedera-api.kivon.io/api/add-liquidity';
const MIRROR_BASE = 'https://mainnet-public.mirrornode.hedera.com';

const TIMESTAMP_FILE = path.resolve('./last-timestamp.json');
let lastTimestamp = null;

function loadLastTimestamp() {
  if (fs.existsSync(TIMESTAMP_FILE)) {
    lastTimestamp = JSON.parse(fs.readFileSync(TIMESTAMP_FILE, 'utf8')).lastTimestamp;
  }
}

function saveLastTimestamp(ts) {
  fs.writeFileSync(TIMESTAMP_FILE, JSON.stringify({ lastTimestamp: ts }, null, 2));
}

async function pollHederaDeposits() {
  try {
    let nextUrl =
      `${MIRROR_BASE}/api/v1/transactions` +
      `?account.id=${MONITORED_ACCOUNT}` +
      `&limit=100` +
      `&order=asc` +
      (lastTimestamp ? `&timestamp=gt:${lastTimestamp}` : '');

    let maxSeenTimestamp = lastTimestamp;

    while (nextUrl) {
      const { data } = await axios.get(nextUrl);

      for (const tx of data.transactions ?? []) {
        const transfers = tx.transfers;
        if (!transfers?.length) continue;

        const deposit = transfers.find(
          t => t.account === MONITORED_ACCOUNT && t.amount > 0
        );
        if (!deposit) continue;

        const sender =
          transfers.find(t => t.amount < 0 && t.account !== MONITORED_ACCOUNT)
            ?.account || 'unknown';

        const amountHbar = deposit.amount / 1e8;

        // console.log(`📥 Deposit: ${amountHbar} HBAR from ${sender}`);

        await axios.post(
          BACKEND_URL,
          {
            wallet_address: sender,
            network: 'hedera',
            amount: amountHbar,
            txId: tx.transaction_id
          },
          {
            headers: { 'X-Bridge-Secret': process.env.BRIDGE_INDEXER_KEY }
          }
        );

        maxSeenTimestamp = tx.consensus_timestamp;
      }

      nextUrl = data.links?.next
        ? `${MIRROR_BASE}${data.links.next}`
        : null;
    }

    if (maxSeenTimestamp && maxSeenTimestamp !== lastTimestamp) {
      lastTimestamp = maxSeenTimestamp;
      saveLastTimestamp(maxSeenTimestamp);
    }
  } catch (err) {
    console.error('Polling error:', err.message);
  }
}

loadLastTimestamp();
setInterval(pollHederaDeposits, 5000);
