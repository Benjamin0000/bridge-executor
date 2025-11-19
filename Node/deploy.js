import {
  Client,
  PrivateKey,
  AccountId,
  FileCreateTransaction,
  FileAppendTransaction,
  ContractCreateTransaction,
  ContractFunctionParameters,
} from "@hashgraph/sdk";
import fs from "fs";
import dotenv from "dotenv";
dotenv.config();

async function main() {
  try {
    const operatorId = AccountId.fromString(process.env.HEDERA_ACCOUNT_ID);
    const operatorKey = PrivateKey.fromStringECDSA(process.env.HEDERA_PRIVATE_KEY);

    const client = Client.forMainnet().setOperator(operatorId, operatorKey);

    const bytecode = fs.readFileSync("./contract.bin");

    // 1️⃣ Upload bytecode file
    const createFileTx = new FileCreateTransaction()
      .setKeys([operatorKey])
      .freezeWith(client);

    const signedCreateFileTx = await createFileTx.sign(operatorKey);
    const createFileSubmit = await signedCreateFileTx.execute(client);
    const createFileRx = await createFileSubmit.getReceipt(client);
    const fileId = createFileRx.fileId;

    console.log("✅ Bytecode file created:", fileId.toString());
    console.log("Transaction ID (file creation):", createFileSubmit.transactionId.toString());

    // 2️⃣ Append the bytecode
    const appendTx = new FileAppendTransaction()
      .setFileId(fileId)
      .setContents(bytecode)
      .freezeWith(client);

    const signedAppendTx = await appendTx.sign(operatorKey);
    const appendSubmit = await signedAppendTx.execute(client);
    const appendRx = await appendSubmit.getReceipt(client);

    console.log("✅ Bytecode appended:", fileId.toString());
    console.log("Transaction ID (append):", appendSubmit.transactionId.toString());

    // 3️⃣ Constructor arguments
    const constructorParams = new ContractFunctionParameters()
      .addAddress("0xf10ee4cf289d2f6b53a90229ce16b8646e724418") // argument 1
      .addAddress("0xf10ee4cf289d2f6b53a90229ce16b8646e724418"); // argument 2

    // 4️⃣ Deploy contract
    const contractTx = new ContractCreateTransaction()
      .setGas(7_000_000) // conservative buffer
      .setBytecodeFileId(fileId)
      .setConstructorParameters(constructorParams)
      .freezeWith(client);

    const signedContractTx = await contractTx.sign(operatorKey);
    const contractSubmit = await signedContractTx.execute(client);
    const contractRx = await contractSubmit.getReceipt(client);
    const contractRecord = await contractSubmit.getRecord(client);

    console.log("✅ Contract deployed successfully!");
    console.log("Contract ID:", contractRx.contractId.toString());
    console.log("Transaction ID (contract deploy):", contractSubmit.transactionId.toString());
    console.log("Gas used:", contractRecord.contractCreateResult?.gasUsed.toString());
    console.log("Status:", contractRx.status.toString());

  } catch (err) {
    console.error("❌ Deployment failed:", err);
  }
}

main();
