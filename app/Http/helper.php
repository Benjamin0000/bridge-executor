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


function decode()
{
    $decoder = new EvmEventDecoder();
    $log = json_decode('[{"data":"0x000000000000000000000000000000000000000000000000000012309ce54000000000000000000000000000f10ee4cf289d2f6b53a90229ce16b8646e7244180000000000000000000000000000000000000000000000000000000000000000000000000000000000000000f10ee4cf289d2f6b53a90229ce16b8646e7244180000000000000000000000000000000000000000000000000000000000000001","topics":["0xbbe04d88f19f37edec128135fc7a2fc4615cc1167ab6eb48edef93422342c6d0","0x2414c2cc2abad26fa1a38e5d4f85581204489e647100f93776b5bad8ee82ab35","0x000000000000000000000000f10ee4cf289d2f6b53a90229ce16b8646e724418","0x0000000000000000000000000000000000000000000000000000000000000000"],"index":1,"account":{"address":"0x119d249246160028fcccc8c3df4a5a3c11dc9a6b"},"transaction":{"hash":"0xaa6fd089ee435de34b9554d95d2b079dc9068c628ac4e289515e5cae37f2ad9e","nonce":8,"index":2,"from":{"address":"Over 9 levels deep, aborting normalization"},"to":{"address":"Over 9 levels deep, aborting normalization"},"value":"0x12309ce54000","gasPrice":"0x989680","maxFeePerGas":"0xcdfe60","maxPriorityFeePerGas":"0x0","gas":202972,"status":1,"gasUsed":196745,"cumulativeGasUsed":241816,"effectiveGasPrice":"0x989680","createdContract":null}}]');
    $decoded = $decoder->decodeLog($log);

    if ($decoded) {
        dd($decoded);
    }
}
