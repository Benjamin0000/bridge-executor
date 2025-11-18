import { AccountId, AccountInfoQuery, Hbar} from "@hashgraph/sdk"

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
    hedera: 'https://testnet.hashio.io/api',
    ethereum: 'https://go.getblock.io/abe4aeec068849e2a549ccf212ea7f4c',
    binance: 'https://go.getblock.io/42b1f41c49b643c198827aab54839e68'
}; 

export const WRAPPED_NATIVE = {
    hedera: '0x0000000000000000000000000000000000003aD2',
    ethereum: '0xfFf9976782d46CC05630D1f6eBAb18b2324d6B14',
    binance: '0xbb4CdB9CBd36B01bD1cBaEBF2De08d9173bc095c'
}; 

export const ROUTER = {
    hedera: '0x0000000000000000000000000000000000004b40',
    ethereum: '0xeE567Fe1712Faf6149d80dA1E6934E354124CfE3',
    binance: '0x10ED43C718714eb63d5aA57B78B54704E256024E'
};


export const BRIDGE_CONTRACT = {
  ethereum: "0xE3C9B2A7EfB6901db58B497E003B15f50c4E90D2",
  binance: "0x6C293F50Fd644ec898Cfd94AB977450E188e6078",
  hedera: "0.0.7267759",
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
    USDCt: {
      symbol: "USDCt",
      address: "0xDb740b2CdC598bDD54045c1f9401c011785032A6",
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
      address: "0xabbd60313073EB1673940f0f212C7baC5333707e",
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
      address: "0.0.1183558",
      decimals: 6,
      native: false 
    },
    CLXY: {
      symbol: "CLXY",
      address: "0.0.5365",
      decimals: 6,
      native: false
    },
    DAI: {
      symbol: "DAI",
      address: "0.0.5529",
      decimals: 8,
      native: false
    }
  },
};
