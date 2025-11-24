import axios from "axios";
import { ethers } from "ethers";
import { sendToBackend } from "./sender.js";

export function startEvmScanner({ slug, rpc, poolAddress }) {
    const provider = new ethers.JsonRpcProvider(rpc);
    let lastBlock = 0;
    poolAddress = poolAddress.toLowerCase();

    async function scan() {
        try {
            const current = await provider.getBlockNumber();

            if (lastBlock === 0) lastBlock = current - 1;

            for (let bn = lastBlock + 1; bn <= current; bn++) {
                const block = await provider.getBlock(bn, true);
                if (!block?.transactions) continue;

                console.log(`[${slug}] scanning block ${bn}`);

                for (const tx of block.transactions) {
                    if (tx.to?.toLowerCase() === poolAddress) {
                        const amount = Number(ethers.formatEther(tx.value));

                        await sendToBackend({
                            wallet_address: tx.from,
                            amount,
                            network: slug,
                            txId: tx.hash
                        });

                        console.log(`[${slug}] Deposit found:`, {
                            from: tx.from, amount, hash: tx.hash
                        });
                    }
                }
            }

            lastBlock = current;
        } catch (e) {
            console.error(`[${slug}] EVM SCAN ERROR`, e.message);
        }

        setTimeout(scan, 5000);
    }

    console.log(`[${slug}] EVM scanner started.`);
    scan();
}
