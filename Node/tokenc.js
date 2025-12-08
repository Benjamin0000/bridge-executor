import { 
    Client, 
    TokenCreateTransaction,
    TokenType,
    AccountId,
    PrivateKey
} from "@hashgraph/sdk";

async function createToken() {
    // Configure client with your testnet account credentials
    const client = Client.forTestnet();
    //go to portal.hedera.com/dashboard and set up a testnet account to get:
    client.setOperator(
        AccountId.fromString(''), //Account ID
        PrivateKey.fromStringECDSA("") //"Hex Encoded Private Key"
    );

    const transaction = new TokenCreateTransaction()
        .setTokenName("HedraFi Reward Token")
        .setTokenSymbol("HRT")
        .setDecimals(8)
        .setInitialSupply(20000000 * 10**8)
        .setTokenType(TokenType.FungibleCommon)
        .setTreasuryAccountId(AccountId.fromString('0.0.7349743'));

    const response = await transaction.execute(client);
    const receipt = await response.getReceipt(client);
    
    console.log(`Token ID: ${receipt.tokenId}`);
}

createToken();