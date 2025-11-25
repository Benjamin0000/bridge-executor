/**
 * scanner.js
 *
 * Reliable EVM block scanner:
 * - Scans blocks (confirmed)
 * - Finds native token transfers TO your monitored address
 * - Ignores transfers sent by contracts
 * - Deduplicates using a simple file-backed store
 * - Sends POST to your backend
 *
 * Usage:
 * 1. npm i ethers axios dotenv
 * 2. create .env with keys described below
 * 3. node scanner.js
 */

import fs from "fs";
import path from "path";
import axios from "axios";
import { ethers } from "ethers";
import dotenv from "dotenv";
dotenv.config();

// -------------------- CONFIG --------------------
const BACKEND_URL = process.env.BACKEND_URL || "http://104.248.47.146/api/add-liquidity";
const CONFIRMATIONS = Number(process.env.CONFIRMATIONS || 1); // number of confirmations before processing
const SCAN_INTERVAL_MS = Number(process.env.SCAN_INTERVAL_MS || 4000);

// Networks: you can override these in .env or edit here directly
const NETWORKS = [
  {
    name: "ethereum",
    rpc: process.env.RPC_ETH || `wss://eth-mainnet.g.alchemy.com/v2/${process.env.NEXT_PUBLIC_ALCHEMY_API_KEY}`,
    monitoredAddress: "0xf10ee4cf289d2f6b53a90229ce16b8646e724418".toLowerCase()
  },
  {
    name: "binance",
    rpc: process.env.RPC_BSC || `wss://bnb-mainnet.g.alchemy.com/v2/${process.env.NEXT_PUBLIC_ALCHEMY_API_KEY}`,
    monitoredAddress: "0xf10ee4cf289d2f6b53a90229ce16b8646e724418".toLowerCase()
  },
  {
    name: "base",
    rpc: process.env.RPC_BASE || `wss://base-mainnet.g.alchemy.com/v2/${process.env.NEXT_PUBLIC_ALCHEMY_API_KEY}`,
    monitoredAddress: "0xf10ee4cf289d2f6b53a90229ce16b8646e724418".toLowerCase()
  },
  {
    name: "arbitrum",
    rpc: process.env.RPC_ARB || `wss://arb-mainnet.g.alchemy.com/v2/${process.env.NEXT_PUBLIC_ALCHEMY_API_KEY}`,
    monitoredAddress: "0xf10ee4cf289d2f6b53a90229ce16b8646e724418".toLowerCase()
  },
  {
    name: "optimism",
    rpc: process.env.RPC_OP || `wss://opt-mainnet.g.alchemy.com/v2/${process.env.NEXT_PUBLIC_ALCHEMY_API_KEY}`,
    monitoredAddress: "0xf10ee4cf289d2f6b53a90229ce16b8646e724418".toLowerCase()
  }
];

// -------------------- Helpers: persistence --------------------
function ensureDir(dir) {
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
}
const DB_DIR = path.resolve(process.cwd(), ".scanner_data");
ensureDir(DB_DIR);

function lastBlockFile(network) {
  return path.join(DB_DIR, `last_block_${network}.txt`);
}
function processedFile(network) {
  return path.join(DB_DIR, `processed_tx_${network}.json`);
}

function loadLastBlock(network) {
  const f = lastBlockFile(network);
  if (!fs.existsSync(f)) return null;
  const s = fs.readFileSync(f, "utf8").trim();
  const n = parseInt(s, 10);
  return Number.isNaN(n) ? null : n;
}
function saveLastBlock(network, block) {
  fs.writeFileSync(lastBlockFile(network), String(block));
}

function loadProcessed(network) {
  const f = processedFile(network);
  if (!fs.existsSync(f)) return {};
  try {
    const obj = JSON.parse(fs.readFileSync(f, "utf8"));
    return obj || {};
  } catch (e) {
    return {};
  }
}
function saveProcessed(network, mapObj) {
  fs.writeFileSync(processedFile(network), JSON.stringify(mapObj, null, 2));
}

// -------------------- Utility --------------------
async function isContract(provider, address) {
  try {
    const code = await provider.getCode(address);
    return code && code !== "0x";
  } catch (e) {
    console.warn("isContract() error:", e.message);
    // in case of error, play safe and treat as contract to avoid false positives
    return true;
  }
}

// -------------------- Scanner per network --------------------
async function startScannerForNetwork(net) {
  console.log(`\nStarting scanner for ${net.name}`);
  // choose provider type automatically (http or wss)
  let provider;
  try {
    if (net.rpc.startsWith("wss://") || net.rpc.startsWith("ws://")) {
      provider = new ethers.WebSocketProvider(net.rpc);
    } else {
      provider = new ethers.JsonRpcProvider(net.rpc);
    }
  } catch (e) {
    console.error(`[${net.name}] Failed to create provider:`, e.message);
    return;
  }

  // attach net meta for logs if not present
  provider.networkName = net.name;

  // load state
  let lastBlock = loadLastBlock(net.name);
  if (lastBlock === null) {
    try {
      const bn = await provider.getBlockNumber();
      lastBlock = bn - 1; // start from previous block
      saveLastBlock(net.name, lastBlock);
      console.log(`[${net.name}] init lastBlock -> ${lastBlock}`);
    } catch (e) {
      console.error(`[${net.name}] getBlockNumber failed:`, e.message);
      lastBlock = 0;
    }
  }

  const processed = loadProcessed(net.name);

  // main loop (polling)
  const loop = async () => {
    try {
      const latest = await provider.getBlockNumber();
      const target = latest - CONFIRMATIONS + 1; // only process blocks with required confirmations
      if (target <= lastBlock) return;

      for (let b = lastBlock + 1; b <= target; b++) {
        const block = await provider.getBlock(b, true); // include transactions
        if (!block) {
          console.warn(`[${net.name}] missing block ${b}, skipping`);
          lastBlock = b;
          saveLastBlock(net.name, lastBlock);
          continue;
        }

        console.log(`[${net.name}] scanning block ${b} txs=${block.transactions?.length || 0}`);

        for (const tx of block.transactions || []) {
          try {
            if (!tx.to) continue;
            if (tx.to.toLowerCase() !== net.monitoredAddress.toLowerCase()) continue;

            // skip if already processed
            if (processed[tx.hash]) {
              // console.debug(`[${net.name}] tx ${tx.hash} already processed`);
              continue;
            }

            // confirm sender is not a contract
            const senderIsContract = await isContract(provider, tx.from);
            if (senderIsContract) {
              console.log(`[${net.name}] skipping contract sender ${tx.from} tx=${tx.hash}`);
              processed[tx.hash] = { skipped: "contract_sender", time: Date.now() };
              continue;
            }

            const amount = Number(ethers.formatEther(tx.value));
            if (!amount || amount <= 0) {
              // ignore zero value txs
              processed[tx.hash] = { skipped: "zero_value", time: Date.now() };
              continue;
            }

            // prepare payload
            const payload = {
              wallet_address: tx.from,
              network: net.name,
              amount: amount,
              txId: tx.hash
            };

            console.log(`[${net.name}] deposit found: from=${tx.from} amount=${amount} tx=${tx.hash}`);

            // relay to backend (with basic retry)
            let sent = false;
            let tries = 0;
            while (!sent && tries < 3) {
              tries++;
              try {
                const res = await axios.post(BACKEND_URL, payload, { timeout: 15000 });
                console.log(`[${net.name}] backend response:`, res.status, res.data?.message || "");
                sent = true;
                processed[tx.hash] = { relayed: true, time: Date.now(), backendStatus: res.status };
              } catch (err) {
                console.warn(`[${net.name}] backend post attempt ${tries} failed:`, err.message);
                if (tries >= 3) {
                  processed[tx.hash] = { relayed: false, error: err.message, time: Date.now() };
                } else {
                  await new Promise(r => setTimeout(r, 2000));
                }
              }
            }

            // persist processed map periodically (and immediately)
            saveProcessed(net.name, processed);
          } catch (innerErr) {
            console.error(`[${net.name}] error processing tx ${tx.hash}:`, innerErr.message);
          }
        } // end block transactions

        lastBlock = b;
        saveLastBlock(net.name, lastBlock);
      } // end block loop
    } catch (err) {
      console.error(`[${net.name}] scanner loop error:`, err.message);
      // on provider error for websocket, reconnect logic could go here
    }
  };

  // run loop immediately and then as interval
  await loop();
  setInterval(loop, SCAN_INTERVAL_MS);
}

// -------------------- Start all scanners --------------------
(async () => {
  for (const net of NETWORKS) {
    startScannerForNetwork(net);
  }
})();
