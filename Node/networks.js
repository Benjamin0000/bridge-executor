export const networks = [
  {
    name: "sepolia",
    rpc: process.env.ETH_RPC,
    contract: process.env.ETH_BRIDGE,
    destinationChain: "hedera",
  },
  {
    name: "bsc",
    rpc: process.env.BSC_RPC,
    contract: process.env.BSC_BRIDGE,
    destinationChain: "hedera",
  }
];
