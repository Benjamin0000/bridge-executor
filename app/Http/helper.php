<?php 

use App\Models\Register;
use App\Models\TokenPrice;

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
        'BNB' => 'binancecoin',
        'HBAR' => 'hedera-hashgraph',
        'CLXY' => 'calaxy',
        'SAUCE' => 'saucerswap',
        'DAI' => 'dai',
        'USDCt' => 'usdc',
        'USDC' => 'usdc',
    ];
    $price = TokenPrice::where('token', $SYMBOL_TO_ID[$token])->latest()->first();
    return $price ? $price->price : 1;
}

function get_native_token_symbol($network)
{
    return match($network) {
        'hedera' => 'HBAR',
        'bsc' => 'BNB',
        'binance' => 'BNB',
        'ethereum' => 'ETH',
        default => null,
    };
}