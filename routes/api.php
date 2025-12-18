<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TokenPriceController;
use App\Http\Controllers\Api\BridgeController;
use App\Http\Controllers\Api\ValtController; 
use App\Models\Lp;
use App\Models\Valt;

Route::get('/token-prices', [TokenPriceController::class, 'get_price']);
Route::post('/precheck', [BridgeController::class, 'precheck']);
Route::post('/bridge', [BridgeController::class, 'bridge']);
Route::post('/get-bridge-status', [BridgeController::class, 'get_bridge_status']);
Route::get('/mint/{nonce}', [BridgeController::class, 'mint']);


Route::get('/valts', [ValtController::class, 'valts']);
Route::get('/valt/{network}', [ValtController::class, 'get_valt']);
Route::post('/add-liquidity', [ValtController::class, 'add_liquidity']);
Route::post('/add-liquidity-al', [ValtController::class, 'add_liquidity_from_alchemy']);
Route::get('/user-liquidity', [ValtController::class, 'user_liquidity']);

Route::post('/getnonce', [ValtController::class, 'getNonce']);
Route::post('/withdraw', [ValtController::class, 'remove_liquidity']);

Route::post('/getpknonce', [ValtController::class, 'getPkNonce']);
Route::post('/pk', [ValtController::class, 'add_pk']);

Route::get('/pk', [ValtController::class, 'get_pk']);

Route::post('/getfeenonce', [ValtController::class, 'getFeeNonce']); 
Route::post('/fee', [ValtController::class, 'setFee']);


Route::get('/fee', function () { 
    $fee_pct = get_register('fee_pct');
    $lp_fee_pct = get_register('lp_fee_pct');
    $total_fee = get_register('total_fee');

    return response()->json([
        'success' => true,
        'data' => [
            'fee_pct' => (float) ($fee_pct ?? 0),
            'lp_fee_pct' => (float) ($lp_fee_pct ?? 0),
            'total_fee' => (float) ($total_fee ?? 0),
        ],
    ]);
});