import { AccountId, AccountInfoQuery, Hbar} from "@hashgraph/sdk"


export const erc20Abi = [
  "function balanceOf(address) view returns (uint256)",
  "function decimals() view returns (uint8)",
];

export const routerAbi = [
    "function getAmountsOut(uint amountIn, address[] calldata path) view returns (uint[] memory amounts)",
    "function swapExactETHForTokens(uint amountOutMin, address[] calldata path, address to, uint deadline) payable returns (uint[] memory amounts)"
];

export const RPC_URL = {
    hedera: 'https://testnet.hashio.io/api',
    ethereum: 'https://ethereum-sepolia-rpc.publicnode.com',
    binance: 'https://bsc-dataseed.binance.org'
}; 

export const WRAPPED_NATIVE = {
    hedera: '0x0000000000000000000000000000000000003aD2',
    ethereum: '0x7b79995e5f793A07Bc00c21412e50Ecae098E7f9',
    binance: '0xbb4CdB9CBd36B01bD1cBaEBF2De08d9173bc095c'
}; 

export const ROUTER = {
    hedera: '0x0000000000000000000000000000000000004b40',
    ethereum: '0xeE567Fe1712Faf6149d80dA1E6934E354124CfE3',
    binance: '0x10ED43C718714eb63d5aA57B78B54704E256024E'
};

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

// Helper function to round amount to token decimals
export function formatAmountForToken(amount, decimals) {
  const factor = 10 ** decimals;
  const num = typeof amount === "string" ? parseFloat(amount) : amount;
  const rounded = Math.floor(num * factor) / factor;
  return rounded.toString();
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
      decimals: 6
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
      decimals: 18
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
      decimals: 6
    },
    CLXY: {
      symbol: "CLXY",
      address: "0.0.5365",
      decimals: 6
    },
    DAI: {
      symbol: "DAI",
      address: "0.0.5529",
      decimals: 8
    }
  },
};
