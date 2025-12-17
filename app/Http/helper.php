<?php 

use App\Models\Register;
use App\Models\TokenPrice;
use kornrunner\Keccak;
use Elliptic\EC;
use App\Providers\EvmEventDecoder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Crypt;

function set_register($name, $value="")
{
    if( $reg = Register::where('name', $name)->first() ){
        $reg->value = $value;
        $reg->save();
        return;
    }
    Register::create([
        'name'=>$name,
        'value'=>$value
    ]);
}

function get_register($name)
{
    $reg = Register::where('name', $name)->first();
    if(!$reg)
        $reg = Register::create(['name'=>$name]);
    return $reg->value; 
}



function pool_address_evm()
{
    return env('EVM_OPERATOR_ADDRESS');
}


function pool_address_hedera()
{
    return env('HEDERA_OPERATOR_ADDRESS');
}


function pool_address_pk($pk = null)
{
    if ($pk !== null) {
        $encryptedPk = Crypt::encryptString($pk);
        set_register('pool_address_pk', $encryptedPk);
        return true;
    }
    return get_register('pool_address_pk');
}


function get_token_price($token)
{
    $SYMBOL_TO_ID = [
        'ETH' => 'ethereum',
        'WETH' => 'ethereum',
        'BNB' => 'binancecoin',
        'HBAR' => 'hedera-hashgraph',
        'PACK' => 'hashpack',
        'SAUCE' => 'saucerswap',
        'USDC' => 'usd-coin',
        'USDT' => 'tether',
        'WBTC' => 'bitcoin', 
        'BTCB' => 'bitcoin'
    ];

    $price = TokenPrice::where('token', $SYMBOL_TO_ID[$token])->latest()->first();
    return $price->price;
}

function get_native_token_symbol($network)
{
    return match($network) {
        'hedera' => 'HBAR',
        'ethereum' => 'ETH',
        'bsc' => 'BNB',
        'binance' => 'BNB',
        'base' => 'ETH',
        'arbitrum' => 'ETH',
        'optimism' => 'ETH',
    };
}

function generateNounce()
{
    return bin2hex(random_bytes(16));
}



function keccak256($input)
{
    return '0x' . Keccak::hash($input, 256);
}


function compareNounces($nonce1, $nonce2)
{
    return hash_equals($nonce1, $nonce2);
}

/**
 * Verify that the incoming request is from Alchemy.
 * You can implement signature verification, secret token, or any custom check here.
 */
function verifyAlchemyRequest(): bool
{
    $signature = request()->header('X-Alchemy-Signature');
    $signingKey = env('ALCHEMY_WEBHOOK_SECRET');
    $rawBody = file_get_contents('php://input');

    $computedHash = hash_hmac('sha256', $rawBody, $signingKey);

    // --- TEMPORARY DEBUGGING LINES ---
    Log::info('Received Sig: ' . $signature);
    Log::info('Computed Hash: ' . $computedHash);
    Log::info('Raw Body Length: ' . strlen($rawBody));
    // --- END DEBUGGING ---

    // Ensure you account for any potential prefix like '0x' in the signature
    // Example: $signature = str_ireplace('0x', '', $signature);

    return hash_equals($computedHash, $signature);
}


function verifyEvmWalletSignature($message, $signature, $address)
{
    $address   = strtolower($address);
    $signature = strtolower(str_replace('0x', '', $signature));

    if (strlen($signature) !== 130) {
        return false;
    }

    $r = substr($signature, 0, 64);
    $s = substr($signature, 64, 64);
    $v = hexdec(substr($signature, 128, 2));
    if ($v < 27) $v += 27;

    $prefix  = "\x19Ethereum Signed Message:\n" . strlen($message);
    $msgHash = Keccak::hash($prefix . $message, 256);

    $ec = new EC('secp256k1');
    $pubKey = $ec->recoverPubKey(
        $msgHash,
        ['r' => $r, 's' => $s],
        $v - 27
    );

    $pubKeyHex = substr($pubKey->encode('hex', false), 2);
    $derived   = '0x' . substr(Keccak::hash(hex2bin($pubKeyHex), 256), 24);

    return strtolower($derived) === $address;
}


function verifyHederaWalletSignature($message, $signature, $address)
{

}




/**
 * Verifies wallet signatures for EVM or Hedera.
 *
 * @param string      $type          "evm" or "hedera"
 * @param string      $message       The exact message signed (raw UTF-8)
 * @param string      $signature     Hex string of signature (r||s for secp256k1, raw bytes for ED25519)
 * @param string      $address       EVM address or Hedera account ID
 * @param string|null $publicKeyHex  Optional Hedera public key (DER or compressed)
 *
 * @return bool
 */
function verifyWalletSignature(
    string $type,
    string $message,
    string $signature,
    string $address,
    ?string $publicKeyHex = null
): bool {
    try {

        /* ============================================================
         * EVM (Ethereum / MetaMask / HashPack EVM)
         * ============================================================ */
        if ($type === 'evm') {
            $address   = strtolower($address);
            $signature = strtolower(str_replace('0x', '', $signature));

            if (strlen($signature) !== 130) {
                Log::error("[EVM] Signature has invalid length");
                return false;
            }

            $r = substr($signature, 0, 64);
            $s = substr($signature, 64, 64);
            $v = hexdec(substr($signature, 128, 2));
            if ($v < 27) $v += 27;

            $prefix  = "\x19Ethereum Signed Message:\n" . strlen($message);
            $msgHash = Keccak::hash($prefix . $message, 256);

            $ec = new EC('secp256k1');
            $pubKey = $ec->recoverPubKey(
                $msgHash,
                ['r' => $r, 's' => $s],
                $v - 27
            );

            $pubKeyHex = substr($pubKey->encode('hex', false), 2);
            $derived   = '0x' . substr(Keccak::hash(hex2bin($pubKeyHex), 256), 24);

            return strtolower($derived) === $address;
        }

        /* ============================================================
         * HEDERA (HashPack, Blade, Wallawallet)
         * ============================================================ */
        if ($type === 'hedera') {
            Log::info("[HEDERA] Starting signature verification", ['account' => $address]);

            // 1️⃣ Message bytes (raw UTF-8)
            $messageBytes = $message;
            Log::info("[HEDERA] Message bytes length", ['length' => strlen($messageBytes)]);

            // 2️⃣ Decode signature
            $signatureBin = hex2bin(str_replace('0x', '', $signature));
            if (!$signatureBin || strlen($signatureBin) !== 64) {
                Log::error("[HEDERA] Invalid signature length");
                return false;
            }

            // 3️⃣ Resolve public key
            if (!$publicKeyHex) {
                $res = Http::get("https://mainnet-public.mirrornode.hedera.com/api/v1/accounts/{$address}");
                if (!$res->ok()) {
                    Log::error("[HEDERA] Failed to fetch account data from Mirror Node");
                    return false;
                }

                $publicKeyHex = $res->json('key.key');
                if (!$publicKeyHex) {
                    Log::error("[HEDERA] Public key not found in account data");
                    return false;
                }
            }

            $publicKeyHex = normalizeHederaPublicKey($publicKeyHex);
            if (!$publicKeyHex) {
                Log::error("[HEDERA] Failed to normalize public key");
                return false;
            }

            $pubKeyBin = hex2bin($publicKeyHex);
            if (!$pubKeyBin) {
                Log::error("[HEDERA] Failed to decode public key");
                return false;
            }

            // 4️⃣ Determine type: ED25519 or secp256k1
            if (strlen($pubKeyBin) === 32) {
                Log::info("[HEDERA] Using ED25519 verification");
                $valid = sodium_crypto_sign_verify_detached($signatureBin, $messageBytes, $pubKeyBin);
                Log::info("[HEDERA] ED25519 verification result", ['valid' => $valid]);
                return $valid;
            }

            if (strlen($pubKeyBin) === 33) {
                Log::info("[HEDERA] Using secp256k1 + SHA-384");

                $hash = hash('sha256', $messageBytes, true);
                $derSig = ecdsaRawSigToDer($signatureBin);

                $ec  = new EC('secp256k1');
                $key = $ec->keyFromPublic(bin2hex($pubKeyBin), 'hex');

                $valid = $key->verify(bin2hex($hash), bin2hex($derSig));
                Log::info("[HEDERA] secp256k1 verification result", ['valid' => $valid]);

                return $valid;
            }

            Log::error("[HEDERA] Unsupported public key length", ['length' => strlen($pubKeyBin)]);
            return false;
        }

        Log::error("[SIGNATURE] Unsupported wallet type: {$type}");
        return false;

    } catch (\Throwable $e) {
        Log::error("[SIGNATURE] Verification error: " . $e->getMessage());
        report($e);
        return false;
    }
}

/**
 * Convert 64-byte raw r||s signature to DER encoded signature
 */
function ecdsaRawSigToDer(string $sig64): string
{
    $r = ltrim(substr($sig64, 0, 32), "\x00");
    $s = ltrim(substr($sig64, 32, 32), "\x00");

    if (ord($r[0]) & 0x80) $r = "\x00" . $r;
    if (ord($s[0]) & 0x80) $s = "\x00" . $s;

    return "\x30"
        . chr(strlen($r) + strlen($s) + 4)
        . "\x02" . chr(strlen($r)) . $r
        . "\x02" . chr(strlen($s)) . $s;
}

/**
 * Normalize Hedera public key (DER → compressed 33 bytes)
 */
function normalizeHederaPublicKey(string $hex): ?string
{
    $hex = strtolower(str_replace('0x', '', $hex));
    $bin = hex2bin($hex);
    if (!$bin) return null;

    // Already compressed
    if (strlen($bin) === 33 && in_array($bin[0], ["\x02", "\x03"])) return $hex;

    // Uncompressed (0x04)
    if (strlen($bin) === 65 && $bin[0] === "\x04") {
        $y = substr($bin, 33, 32);
        $prefix = (ord($y[31]) % 2 === 0) ? "\x02" : "\x03";
        return bin2hex($prefix . substr($bin, 1, 32));
    }

    // DER encoded
    if (strlen($bin) > 33) {
        $raw = substr($bin, -33);
        if (in_array($raw[0], ["\x02", "\x03"])) return bin2hex($raw);
    }

    return null;
}