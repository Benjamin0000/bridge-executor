import crypto from "crypto";
import dotenv from 'dotenv';
dotenv.config({path: ""});
async function loadOperatorPrivateKey() {
  // if (OPERATOR_PRIVATE_KEY) return OPERATOR_PRIVATE_KEY;

  // const res = await fetch("https://hedera-api.kivon.io/api/pk", {
  //   headers: {
  //     "X-Bridge-Secret": process.env.BRIDGE_INDEXER_KEY,
  //   },
  // });

  // if (!res.ok) {
  //   throw new Error(`Failed to fetch PK (${res.status})`);
  // }

  // const { pk } = await res.json();
  // if (!pk) throw new Error("No encrypted PK returned");

  let OPERATOR_PRIVATE_KEY = null; 
  let pk =  "eyJpdiI6IllDQkFMVzZNUGRmN05BejZYZUI4T2c9PSIsInZhbHVlIjoiSXVvS2Z1ZUt2NnRqLzBvM3lKaUhNZnRHeFRPM0RMR0Z1VjBTMnRKU1pTbG1QbHYzcFlrdi9vV21BenpUOGk2TGQ3WHI2T043czUzc29jUE5ITWZuR1BHRVZEUlVjRFU1T0xWekNLMFp5dm89IiwibWFjIjoiN2JjNTRjNzE5MmFiNjcyMDhiMmNlN2ViNTFhNDFlYmZkNTYwMTkyZTFlMGJmM2E0ZjhhZjFiNDNiNGU3MTA5YSIsInRhZyI6IiJ9"

  

 
  let key = process.env.APP_KEY;
  console.log(key)
  if (key.startsWith("base64:")) {
    key = Buffer.from(key.slice(7), "base64");
  } else {
    key = Buffer.from(key);
  }

  // ---- Decode payload ----
  const payload = JSON.parse(Buffer.from(pk, "base64").toString("utf8"));
  const iv = Buffer.from(payload.iv, "base64");
  const value = Buffer.from(payload.value, "base64");

  // ---- AES-256-CBC decrypt ----
  const decipher = crypto.createDecipheriv("aes-256-cbc", key, iv);
  let decrypted = decipher.update(value);
  decrypted = Buffer.concat([decrypted, decipher.final()]);

  OPERATOR_PRIVATE_KEY = decrypted.toString("utf8");
  console.log(OPERATOR_PRIVATE_KEY)
  return OPERATOR_PRIVATE_KEY;
}


 loadOperatorPrivateKey(); 

