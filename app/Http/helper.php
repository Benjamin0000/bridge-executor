<?php 

use App\Models\Register;
use App\Models\TokenPrice;
use kornrunner\Keccak;
use App\Providers\EvmEventDecoder; 


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


function pool_address_evm($new_address = null)
{
    if($new_address){
        set_register('pool_addres_evm', $new_address);
        return $new_address; 
    }
    return get_register('pool_addres_evm');
}


function pool_address_hedera($new_address = null)
{
    if($new_address){
        set_register('pool_addres_hedera', $new_address);
        return $new_address; 
    }
    return get_register('pool_addres_hedera'); 
}

function pool_address_pk($pk = null)
{
    if($pk){
        set_register('pool_address_pk', $pk);
        return $pk; 
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