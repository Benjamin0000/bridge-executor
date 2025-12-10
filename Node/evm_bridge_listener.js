// indexer.js
import { ethers } from "ethers";
import fs from "fs";
import path from "path";
import axios from "axios";

// ------------------------------
// 1. Bridge ABI
// ------------------------------
const BRIDGE_ABI = [
  "event BridgeDeposit(string indexed nonce, address indexed from, address indexed tokenFrom, int64 amount, address to, address tokenTo, address poolAddress, uint64 desChain)"
];

// ------------------------------
// 2. Chains config
// ------------------------------
const CHAINS = {
  ethereum: {
    rpc: ["https://ethereum-public.nodies.app"],
    contract: "0xe179c49A5006EB738A242813A6C5BDe46a54Fc5C"
  },
  bsc: {
    rpc: [
      "https://bsc.publicnode.com",
      "https://bsc-dataseed1.defibit.io"
    ],
    contract: "0x119d249246160028fcCCc8C3DF4a5a3C11dc9a6B"
  },
  base: {
    rpc: ["https://mainnet.base.org"],
    contract: "0xe179c49A5006EB738A242813A6C5BDe46a54Fc5C"
  },
  arbitrum: {
    rpc: ["https://arb1.arbitrum.io/rpc"],
    contract: "0x119d249246160028fcCCc8C3DF4a5a3C11dc9a6B"
  },
  optimism: {
    rpc: ["https://mainnet.optimism.io"],
    contract: "0x119d249246160028fcCCc8C3DF4a5a3C11dc9a6B"
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
          return await fn(provider);
        } catch (err) {
          console.log("RPC failed:", url, "|", err.message);
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
async function fetchLogsBatched(provider, address, fromBlock, toBlock, batchSize = 10, topics = []) {
  let logs = [];

  for (let start = fromBlock; start <= toBlock; start += batchSize) {
    const end = Math.min(start + batchSize - 1, toBlock);

    try {
      const batchLogs = await provider.call(p =>
        p.getLogs({
          address,
          fromBlock: start,
          toBlock: end,
          topics
        })
      );
      logs.push(...batchLogs);
    } catch (e) {
      console.log(`❌ Batch failed ${start} → ${end}:`, e.message);
    }
  }

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

  setInterval(async () => {
    try {
      const latest = await provider.call(p => p.getBlockNumber());
      if (latest <= lastBlock) return;

      const logs = await fetchLogsBatched(
        provider,
        config.contract,
        lastBlock + 1,
        latest,
        10,
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
          nonceHash: parsed.args.nonce.hash,
          from: parsed.args.from,
          tokenFrom: parsed.args.tokenFrom,
          amount: Number(parsed.args.amount), // BigInt safe
          to: parsed.args.to,
          tokenTo: parsed.args.tokenTo,
          poolAddress: parsed.args.poolAddress,
          desChain: Number(parsed.args.desChain),
          txHash: log.transactionHash,
          blockNumber: log.blockNumber
        };

        console.log(`🔵 [${chainName}] BridgeDeposit`, data);

        // send to Laravel endpoint
        // await axios.post("https://server/api/bridge", data);
      }

      lastBlock = latest;
      saveLastBlock(chainName, lastBlock);

    } catch (err) {
      console.log(`❌ Error on ${chainName}:`, err.message);
    }
  }, 6000);
}

// ------------------------------
// 7. Start all chains
// ------------------------------
for (const [chainName, config] of Object.entries(CHAINS)) {
  startChainListener(chainName, config);
}

console.log("🔥 Multichain BridgeDeposit indexer running...");
