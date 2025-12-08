import { ethers } from "ethers";

const BRIDGE_ABI = [
  "event BridgeDeposit(string indexed nonce, address indexed from, address indexed tokenFrom, int64 amount, address to, address tokenTo, address poolAddress, uint64 desChain)"
];

// ------------------------------
// 1. Chain config
// ------------------------------

const CHAINS = {
  ethereum: {
    rpc: ["https://cloudflare-eth.com"],
    contract: "0xe179c49A5006EB738A242813A6C5BDe46a54Fc5C"
  },
  bsc: {
    rpc: ["https://bsc-dataseed.binance.org", "https://bsc.publicnode.com"],
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
// 2. Build provider fallback
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
// 3. Listen to logs per chain
// ------------------------------

async function startChainListener(chainName, config) {
  const provider = getProvider(config.rpc);
  const iface = new ethers.Interface(BRIDGE_ABI);

  // Start from current block (real-time only)
  let lastBlock = await provider.call(p => p.getBlockNumber());

  console.log(`→ Starting ${chainName} at block ${lastBlock}…`);

  setInterval(async () => {
    try {
      const latest = await provider.call(p => p.getBlockNumber());

      if (latest <= lastBlock) return;

      const logs = await provider.call(p =>
        p.getLogs({
          address: config.contract,
          fromBlock: lastBlock + 1,
          toBlock: latest
        })
      );

      for (const log of logs) {
        const parsed = iface.parseLog(log);

        const data = {
          ...parsed.args,
          txHash: log.transactionHash,
          blockNumber: log.blockNumber,
        };

        console.log(`🔵 [${chainName}] BridgeDeposit`, data);

        // TODO: send to Laravel endpoint
        // await axios.post("https://server/api/bridge", data);
      }

      lastBlock = latest;
    } catch (err) {
      console.log(`❌ Error on ${chainName}:`, err.message);
    }
  }, 6000); // poll every 6s
}

// ------------------------------
// 4. Start multitple listeners
// ------------------------------

for (const [chainName, config] of Object.entries(CHAINS)) {
  startChainListener(chainName, config);
}

console.log("🔥 Multichain indexer running...");
