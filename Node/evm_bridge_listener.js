import dotenv from 'dotenv';
import { ethers } from "ethers";
import fs from "fs";
import path from "path";
import axios from "axios";
import { BRIDGE_CONTRACT } from "./tokens.js";
dotenv.config({path: process.env.DOTENV_CONFIG_PATH});
// ------------------------------
// 1. Bridge ABI
// ------------------------------
const BRIDGE_ABI = [
  "event BridgeDeposit(string indexed nonce, address indexed from, address indexed tokenFrom, int64 amount, address to, address tokenTo, address poolAddress, uint64 desChain)"
];
const TARGET_ENDPOINT = "https://hedera-api.kivon.io/api/bridge";
const alchemy_key = process.env.ALCHEMY_API_KEY || ""

if (!alchemy_key) {
  console.error("Missing env vars: alchemy_key");
  process.exit(1);
}

// ------------------------------
// 2. Chains config
// ------------------------------
const CHAINS = {
  ethereum: {
    rpc: [
      "https://ethereum-public.nodies.app", 
      "https://ethereum-rpc.publicnode.com",
      `https://eth-mainnet.g.alchemy.com/v2/${alchemy_key}`
    ],
    contract: BRIDGE_CONTRACT.ethereum
  },
  bsc: {
    rpc: [
      // "https://bsc-dataseed1.defibit.io",
      "https://bsc.publicnode.com",
      `https://bnb-mainnet.g.alchemy.com/v2/${alchemy_key}`
    ],
    contract: BRIDGE_CONTRACT.binance
  },
  base: {
    rpc: [
      "https://mainnet.base.org",
      "https://base-rpc.publicnode.com",
      `https://base-mainnet.g.alchemy.com/v2/${alchemy_key}`
    ],
    contract: BRIDGE_CONTRACT.base
  },
  arbitrum: {
    rpc: [
      "https://arb1.arbitrum.io/rpc",
      "https://arbitrum-one-rpc.publicnode.com", 
      `https://arb-mainnet.g.alchemy.com/v2/${alchemy_key}`
    ],
    contract: BRIDGE_CONTRACT.arbitrum
  },
  optimism: {
    rpc: [
      "https://mainnet.optimism.io",
      "https://optimism-rpc.publicnode.com",
      "https://optimism.drpc.org", 
      `https://opt-mainnet.g.alchemy.com/v2/${alchemy_key}`
    ],
    contract: BRIDGE_CONTRACT.optimism
  }
};


// ------------------------------
// 3. Provider wrapper with fallback
// ------------------------------
function getProvider(rpcs) {
  const providers = rpcs.map(rpc => ({
    url: rpc,
    provider: new ethers.JsonRpcProvider(rpc)
  }));

  return {
    async call(fn) {
      for (const { url, provider } of providers) {
        try {
          const result = await fn(provider);
          return { result, rpc: url };
        } catch (err) {
          console.log(`⚠️ RPC failed: ${url} | ${err.message}`);
        }
      }
      throw new Error("All RPCs failed!");
    }
  };
}

// ------------------------------
// 4. Persistence for last processed block
// ------------------------------
function getLastBlockFile(chainName) {
  return path.join(process.cwd(), `${chainName}-lastBlock.json`);
}

function loadLastBlock(chainName) {
  const file = getLastBlockFile(chainName);
  if (fs.existsSync(file)) {
    try {
      return JSON.parse(fs.readFileSync(file, "utf-8")).lastBlock;
    } catch {
      return null;
    }
  }
  return null;
}

function saveLastBlock(chainName, blockNumber) {
  const file = getLastBlockFile(chainName);
  fs.writeFileSync(file, JSON.stringify({ lastBlock: blockNumber }, null, 2));
}

// ------------------------------
// 5. Fetch logs with batching
// ------------------------------
async function fetchLogsBatched(chainName, provider, address, fromBlock, toBlock, batchSize = 100, topics = []) {
  let logs = [];

  const startTime = Date.now();
  console.log(
    `\n🔍 [${chainName}] Checking for logs...`,
    `\n   Contract: ${address}`,
    `\n   Blocks:   ${fromBlock} → ${toBlock}`
  );

  for (let start = fromBlock; start <= toBlock; start += batchSize) {
    const end = Math.min(start + batchSize - 1, toBlock);

    try {
      const { result: batchLogs, rpc } = await provider.call(p =>
        p.getLogs({
          address,
          fromBlock: start,
          toBlock: end,
          topics
        })
      );

      logs.push(...batchLogs);

      console.log(
        `   📦 Batch ${start} → ${end} | ${batchLogs.length} logs | RPC: ${rpc.split('//')[1]}`
      );

    } catch (e) {
      console.log(`   ❌ Batch ${start} → ${end} failed: ${e.message}`);
    }
  }

  const ms = Date.now() - startTime;
  console.log(`✅ [${chainName}] Logs fetched: ${logs.length} total (${ms} ms)\n`);

  return logs;
}


// ------------------------------
// 6. Start chain listener
// ------------------------------
async function startChainListener(chainName, config) {
  const provider = getProvider(config.rpc);
  const iface = new ethers.Interface(BRIDGE_ABI);

  let lastBlock = loadLastBlock(chainName);
  if (!lastBlock) {
    lastBlock = await provider.call(p => p.getBlockNumber());
    console.log(`→ Starting ${chainName} at current block ${lastBlock}`);
  } else {
    console.log(`→ Resuming ${chainName} from saved block ${lastBlock}`);
  }

  const topicHash = ethers.id("BridgeDeposit(string,address,address,int64,address,address,address,uint64)");

  let isRunning = false;
  setInterval(async () => {

    

    if (isRunning) return; // skip if previous run is still processing
    isRunning = true;

    console.log(`⏱️ Polling ${chainName} from block ${lastBlock + 1}...`);

    try {
      const latest = await provider.call(p => p.getBlockNumber());
      if (latest <= lastBlock) return;

      const logs = await fetchLogsBatched(
        chainName,
        provider,
        config.contract,
        lastBlock + 1,
        latest,
        100,
        [topicHash]
      );

      for (const log of logs) {
        let parsed;
        try {
          parsed = iface.parseLog(log);
        } catch {
          continue; // skip unrelated logs
        }

        const data = {
          nonceHash: parsed.args.nonce,
          from: parsed.args.from,
          tokenFrom: parsed.args.tokenFrom,
          amount: parsed.args.amount.toString(), // BigInt safe
          to: parsed.args.to,
          tokenTo: parsed.args.tokenTo,
          poolAddress: parsed.args.poolAddress,
          desChain: Number(parsed.args.desChain),
          txHash: log.transactionHash,
          blockNumber: log.blockNumber
        };

        console.log(`🔵 [${chainName}] BridgeDeposit`, data);
        // send to backend
      //   try {
      //     await axios.post(TARGET_ENDPOINT, data, {
      //       headers: {
      //         "X-Bridge-Secret": process.env.BRIDGE_INDEXER_KEY
      //       }
      //     });
      //   } catch (err) {
      //     console.error("❌ Error sending to backend:", err.message);
      //   }
      }

      lastBlock = latest;
      saveLastBlock(chainName, lastBlock);

    } catch (err) {
      console.log(`❌ Error on ${chainName}:`, err.message);
    } finally {
      isRunning = false; // release the guard
    }
  }, 15000); 
}

// ------------------------------
// 7. Start all chains
// ------------------------------
for (const [chainName, config] of Object.entries(CHAINS)) {

  startChainListener(chainName, config);
}

console.log("🔥 Multichain BridgeDeposit indexer running...");
