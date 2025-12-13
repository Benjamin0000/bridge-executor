import dotenv from 'dotenv';
import { AccountId, AccountInfoQuery, Hbar} from "@hashgraph/sdk"
dotenv.config({path: process.env.DOTENV_CONFIG_PATH});

const alchemy_key = process.env.ALCHEMY_API_KEY || ""

if (!alchemy_key) {
  console.error("Missing env vars: alchemy_key");
  process.exit(1);
}

/**
 * Truncates a string representation of a decimal number to a specific number of places.
 * This is safer than Math.round() for token inputs.
 * @param {string} amountStr - The human-readable amount string (e.g., "7.5555551").
 * @param {number} decimals - The token's maximum supported decimals (Token.decimals).
 * @returns {string} The truncated amount string (e.g., "7.555555").
 */
export function truncateDecimals(amountStr, decimals) {
    if (decimals === 0) {
        return amountStr.split('.')[0] || '0'; // Handle tokens with 0 decimals
    }

    const parts = amountStr.split('.');
    
    // If there is no decimal part, return as is.
    if (parts.length === 1) {
        return amountStr;
    }

    // Truncate the fractional part
    const fractionalPart = parts[1].substring(0, decimals);
    
    // Recombine the integer part with the truncated fractional part
    return parts[0] + '.' + fractionalPart;
}


export const erc20Abi = [
  "function balanceOf(address) view returns (uint256)",
  "function allowance(address owner, address spender) view returns (uint256)",
  "function approve(address spender, uint256 amount) returns (bool)",
  "function transfer(address to, uint256 amount) returns (bool)"
];

export const routerAbi = [
    "function getAmountsOut(uint amountIn, address[] calldata path) view returns (uint[] memory amounts)",
    "function swapExactETHForTokens(uint amountOutMin, address[] calldata path, address to, uint deadline) payable returns (uint[] memory amounts)"
];

export const RPC_URL = {
    hedera:'https://mainnet.hashio.io/api',
    ethereum:`https://eth-mainnet.g.alchemy.com/v2/${alchemy_key}`, 
    binance: `https://bnb-mainnet.g.alchemy.com/v2/${alchemy_key}`,
    base: `https://base-mainnet.g.alchemy.com/v2/${alchemy_key}`, 
    arbitrum: `https://arb-mainnet.g.alchemy.com/v2/${alchemy_key}`,
    optimism: `https://opt-mainnet.g.alchemy.com/v2/${alchemy_key}`, 
};


export const BALANCE_RPC_URL = {
    hedera: 'https://mainnet.hashio.io/api',
    ethereum: 'https://ethereum-rpc.publicnode.com', 
    binance: 'https://bsc.publicnode.com',
    base: 'https://base-rpc.publicnode.com', 
    arbitrum: 'https://arbitrum-one-rpc.publicnode.com',
    optimism: 'https://optimism-rpc.publicnode.com', 
}; 

export const WRAPPED_NATIVE = {
    hedera: '0x0000000000000000000000000000000000163b5a',
    ethereum: '0xC02aaA39b223FE8D0A0e5C4F27eAD9083C756Cc2',
    binance: '0xbb4CdB9CBd36B01bD1cBaEBF2De08d9173bc095c',
    base: '0x4200000000000000000000000000000000000006',
    arbitrum: '0x82aF49447D8a07e3bd95BD0d56f35241523fBab1',
    optimism: '0x4200000000000000000000000000000000000006',
}; 

export const ROUTER = { 
    hedera: '0x00000000000000000000000000000000002e7a5d',
    ethereum: '0x7a250d5630B4cF539739dF2C5dAcb4c659F2488D',
    binance: '0x10ED43C718714eb63d5aA57B78B54704E256024E', 
    base: '0x4752ba5dbc23f44d87826276bf6fd6b1c372ad24', 
    arbitrum: '0x4752ba5dbc23f44d87826276bf6fd6b1c372ad24',
    optimism: '0x4A7b5Da61326A6379179b40d00F57E5bbDC962c2', 
};


export const BRIDGE_CONTRACT = {
  ethereum: "0xe179c49A5006EB738A242813A6C5BDe46a54Fc5C",
  arbitrum: "0x119d249246160028fcCCc8C3DF4a5a3C11dc9a6B",
  base: "0xe179c49A5006EB738A242813A6C5BDe46a54Fc5C", 
  optimism: "0x119d249246160028fcCCc8C3DF4a5a3C11dc9a6B", 
  binance: "0x119d249246160028fcCCc8C3DF4a5a3C11dc9a6B",
  hedera: "0.0.10115692",
}

export const convertHederaIdToEVMAddress = (address)=> {
  try {
    const accountId = AccountId.fromString(address)
    const solidityAddress = accountId.toEvmAddress()
    const evmAddress = `0x${solidityAddress}`
    return evmAddress
  } catch (e) {
    throw new Error(`Invalid Hedera address format: ${address}. Details: ${e.message || e}`)
  }
}


export async function getEvmAddressFromAccountId(
  accountIdString,
  client
){
  const accountId = AccountId.fromString(accountIdString)

  try {
    // 1. Query the network for the account's information
    const info = await new AccountInfoQuery().setAccountId(accountId).execute(client)

    // 2. Extract the EVM Address
    let evmAddressBytes = info.contractAccountId

    // The contractAccountId property holds the EVM address as a hex string (Solidity address)
    if (evmAddressBytes) {
      // Check if the address starts with '0x' or if it's the 20-byte hex string
      // For key-derived accounts, it usually returns the 20-byte alias.

      // Clean up the string to ensure it's a valid 20-byte hex string
      // If it's the 40-character hex string, ensure it has the '0x' prefix
      if (evmAddressBytes.length === 40) {
        evmAddressBytes = "0x" + evmAddressBytes
      }

      // NOTE: For accounts with key-derived aliases (like 0x74...), this property
      // is the most reliable source for the alias once the account has been used.
      return evmAddressBytes.toLowerCase()
    } else {
      return convertHederaIdToEVMAddress(accountIdString)
    }
  } catch (error) {
    console.error("Error fetching account info:", error)
    throw new Error(`Failed to resolve EVM address for ${accountIdString}.`)
  }
}

/**
 * Safely parse any numeric input into a valid Hbar instance.
 * 
 * Handles:
 * - numbers or strings
 * - commas, spaces, etc.
 * - rounding to 8 decimal places (tinybar precision)
 * - clear error messages for invalid input
 */
export function safeHbar(amount) {
  if (amount === null || amount === undefined)
    throw new Error("Amount is required.");

  // Convert to string, remove commas/spaces
  const clean = String(amount).replace(/,/g, "").trim();

  // Validate numeric
  const num = Number(clean);
  if (isNaN(num)) throw new Error(`Invalid numeric amount: "${amount}"`);

  // Clamp to 8 decimal places (tinybar precision)
  const rounded = num.toFixed(8);

  try {
    return Hbar.fromString(rounded);
  } catch (err) {
     if (err instanceof Error) {
      throw new Error(`Invalid HBAR amount "${amount}": ${err.message}`);
     }else {
      throw new Error(`Unknown error parsing HBAR amount: ${err}`);
    }
  }
}



export const TOKENS = {
  ethereum: {
    ETH: {
      symbol: "ETH",
      address: "0x0000000000000000000000000000000000000000",
      decimals: 18,
      native: true
    },
    WBTC: {
      symbol: "WBTC",
      address: "0x2260FAC5E5542a773Aa44fBCfeDf7C193bc2C599",
      decimals: 8,
      native: false
    },
    USDC: {
      symbol: "USDC",
      address: "0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48",
      decimals: 6,
      native: false
    },
    USDT: {
      symbol: "USDT",
      address: "0xdAC17F958D2ee523a2206206994597C13D831ec7",
      decimals: 6,
      native: false
    },
  },

  binance: {
    BNB: {
      symbol: "BNB",
      address: "0x0000000000000000000000000000000000000000",
      decimals: 18,
      native: true
    },
    USDC: {
      symbol: "USDC",
      address: "0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d",
      decimals: 18,
      native: false
    },
    BTCB: {
      symbol: "BTCB",
      address: "0x7130d2A12B9BCbFAe4f2634d864A1Ee1Ce3Ead9c",
      decimals: 18,
      native: false
    },
    USDT: {
      symbol: "USDT",
      address: "0x55d398326f99059fF775485246999027B3197955",
      decimals: 18,
      native: false
    },
  },

  hedera: {
    HBAR: {
      symbol: "HBAR",
      address: "0x0000000000000000000000000000000000000000",
      decimals: 8,
      native: true
    },
    SAUCE: {
      symbol: "SAUCE",
      address: "0.0.731861",
      decimals: 6,
      native: false
    },
    PACK: {
      symbol: "PACK",
      address: "0.0.4794920",
      decimals: 6,
      native: false
    },
    WBTC: {
      symbol: "WBTC",
      address: "0.0.10082597",
      decimals: 8,
      native: false
    },
    WETH: {
      symbol: "WETH",
      address: "0.0.9770617",
      decimals: 8,
      native: false
    },
    USDC: {
      symbol: "USDC",
      address: "0.0.456858",
      decimals: 6,
      native: false
    },
    USDT: {
      symbol: "USDT",
      address: "0.0.1055472",
      decimals: 6,
      native: false
    },
  },

  arbitrum: {
    ETH: {
      symbol: "ETH",
      address: "0x0000000000000000000000000000000000000000",
      decimals: 18,
      native: true
    },
    WBTC: {
      symbol: "WBTC",
      address: "0x2f2a2543B76A4166549F7aaB2e75Bef0aefC5B0f",
      decimals: 8,
      native: false
    },
    USDC: {
      symbol: "USDC",
      address: "0xaf88d065e77c8cC2239327C5EDb3A432268e5831",
      decimals: 6,
      native: false
    },
    USDT: {
      symbol: "USDT",
      address: "0xFd086bC7CD5C481DCC9C85ebE478A1C0b69FCbb9",
      decimals: 6,
      native: false
    },
  },

  optimism: {
    ETH: {
      symbol: "ETH",
      address: "0x0000000000000000000000000000000000000000",
      decimals: 18,
      native: true
    },
    WBTC: {
      symbol: "WBTC",
      address: "0x68f180fcCe6836688e9084f035309E29Bf0A2095",
      decimals: 8,
      native: false
    },
    USDC: {
      symbol: "USDC",
      address: "0x0b2C639c533813f4Aa9D7837CAf62653d097Ff85",
      decimals: 6,
      native: false
    },
    USDT: {
      symbol: "USDT",
      address: "0x94b008aA00579c1307B0EF2c499aD98a8ce58e58",
      decimals: 6,
      native: false
    },
  },

  base: {
    ETH: {
      symbol: "ETH",
      address: "0x0000000000000000000000000000000000000000",
      decimals: 18,
      native: true
    },
    WBTC: {
      symbol: "WBTC",
      address: "0x0555E30da8f98308EdB960aa94C0Db47230d2B9c",
      decimals: 8,
      native: false
    },
    USDC: {
      symbol: "USDC",
      address: "0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913",
      decimals: 6,
      native: false
    },
    USDT: {
      symbol: "USDT",
      address: "0xfde4c96c8593536e31f229ea8f37b2ada2699bb2",
      decimals: 6,
      native: false
    },
  },
};
