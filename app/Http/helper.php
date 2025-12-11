<?php 

use App\Models\Register;
use App\Models\TokenPrice;
use kornrunner\Keccak;


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
