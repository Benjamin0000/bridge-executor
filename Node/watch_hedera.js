import axios from 'axios';

const MONITORED_ACCOUNT = '0.0.10145769';
const BACKEND_URL = 'https://hedera-api.kivon.io/api/add-liquidity';
let lastTimestamp = null;

async function pollHederaDeposits() {
  try {
    let url = `https://mainnet-public.mirrornode.hedera.com/api/v1/transactions?account.id=${MONITORED_ACCOUNT}&limit=10&order=desc`;
    if (lastTimestamp) url += `&timestamp=gt:${lastTimestamp}`;

    const { data } = await axios.get(url);

    for (const tx of data.transactions) {
      const transfers = tx.transfers;
      if (!transfers || transfers.length === 0) continue;

      // Deposit record (our account must receive positive)
      const deposit = transfers.find(t => t.account === MONITORED_ACCOUNT && t.amount > 0);
      if (!deposit) continue;

      // Sender = account with negative amount
      const sender = transfers.find(t => t.amount < 0 && t.account !== MONITORED_ACCOUNT)?.account || 'unknown';

      const amountHbar = deposit.amount / 1e8;

      console.log(`📥 Deposit detected: ${amountHbar} HBAR from ${sender}`);

      const body = {
        wallet_address: sender,
        network: 'hedera',
        amount: amountHbar,
        txId: tx.transaction_id
      }; 

      await axios.post(BACKEND_URL, body, {
          headers: {
            "X-Bridge-Secret": process.env.BRIDGE_INDEXER_KEY
          }
      });
    }

    // Track last seen timestamp
    if (data.transactions.length > 0) {
      lastTimestamp = data.transactions[0].consensus_timestamp;
    }

  } catch (err) {
    console.error("Error polling Hedera deposits:", err.message);
  }
}

setInterval(pollHederaDeposits, 5000);
