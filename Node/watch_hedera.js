import axios from 'axios';

const MONITORED_ACCOUNT = '0.0.10115610';
const BACKEND_URL = 'https://hedera-api.kivon.io/api/add-liquidity';
let lastTimestamp = null; // For pagination

async function pollHederaDeposits() {
  try {
    let url = `https://mainnet-public.mirrornode.hedera.com/api/v1/transactions?account.id=${MONITORED_ACCOUNT}&limit=10&order=desc`;
    if (lastTimestamp) {
      url += `&timestamp=gt:${lastTimestamp}`;
    }

    const { data } = await axios.get(url);

    for (const tx of data.transactions) {
      const txId = tx.transaction_id;
      const transfers = tx.transfers;

      // Find the deposit to our account
      const deposit = transfers.find(t => t.account === MONITORED_ACCOUNT && t.amount > 0);
      if (!deposit) continue;

      // Sender can be inferred as any negative amount in the same tx (or use 'unknown')
      const sender = transfers.find(t => t.account !== MONITORED_ACCOUNT && t.amount < 0)?.account || 'unknown';
      const amountHbar = deposit.amount / 1e8;

      // Send to backend
      await axios.post(BACKEND_URL, {
        wallet_address: sender,
        network: 'hedera',
        amount: amountHbar,
        txId: txId
      });

      console.log(`Deposit recorded: ${amountHbar} HBAR from ${sender}`);
    }

    // Update last timestamp
    if (data.transactions.length > 0) {
      lastTimestamp = data.transactions[0].consensus_timestamp;
    }

  } catch (err) {
    console.error('Error polling Hedera deposits:', err.message);
  }
}

// Poll every 10s
setInterval(pollHederaDeposits, 10000);
