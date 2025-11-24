import axios from "axios";
import { sendToBackend } from "./sender.js";

export function startHederaScanner({ slug, mirrorNode, poolAddress }) {
    let lastTimestamp = null;

    async function scan() {
        try {
            const url = `${mirrorNode}/api/v1/transactions?account.id=${poolAddress}&limit=50`;

            const res = await axios.get(url);
            const txs = res.data.transactions || [];

            for (const tx of txs) {
                const timestamp = tx.consensus_timestamp;

                if (timestamp === lastTimestamp) continue;

                lastTimestamp = timestamp;

                const transfer = tx.transfers.find(t => t.account === poolAddress);
                if (!transfer) continue;

                const sender = tx.transfers.find(t => Number(t.amount) < 0);
                const amount = Math.abs(Number(transfer.amount)) / 1e8;

                await sendToBackend({
                    wallet_address: sender.account,
                    amount,
                    network: slug,
                    txId: tx.transaction_id
                });

                console.log(`[hedera] Deposit found:`, {
                    from: sender.account,
                    amount,
                    tx: tx.transaction_id
                });
            }

        } catch (e) {
            console.error("[hedera] Mirror scan error:", e.message);
        }

        setTimeout(scan, 5000);
    }

    console.log("[hedera] scanner started.");
    scan();
}
