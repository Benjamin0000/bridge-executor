<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TokenPriceController;
use App\Http\Controllers\Api\BridgeController;
use App\Http\Controllers\Api\VoltController;
use App\Models\Lp;
use App\Models\Volt;

Route::get('/token-prices', [TokenPriceController::class, 'get_price']);
Route::post('/precheck', [BridgeController::class, 'precheck']);
Route::post('/bridge', [BridgeController::class, 'bridge']);

Route::get('/volts', [VoltController::class, 'volts']);
Route::get('/volt/{network}', [VoltController::class, 'get_volt']);
Route::post('/add-liquidity', [VoltController::class, 'add_liquidity']);
Route::get('/user-liquidity', [VoltController::class, 'user_liquidity']);


Route::post('/set-fee', function (Request $request) {
    $request->validate([
        'fee_pct' => 'required|numeric|min:0',
        'lp_fee_pct' => 'required|numeric|min:0',
    ]);

    // Get fee from request
    $fee_pct = $request->fee_pct;
    $lp_fee_pct = $request->lp_fee_pct;

    set_register('fee_pct', $fee_pct);
    set_register('lp_fee_pct', $lp_fee_pct);

    return response()->json([
        'success' => true,
        'message' => 'Fee percentage updated successfully',
        'data' => [
            'fee_pct' => $fee_pct
        ]
    ]);
});


Route::get('/get-fee', function () {
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






Route::post('/distribute-fee', function (Request $request) {
    $request->validate([
        'net_amount' => 'required|numeric|min:0',
    ]);

    $feePct = (float)get_register('fee_pct') / 100;
    $lpPct = (float)get_register('lp_fee_pct') / 100;
    $adminPct = 1 - $lpPct; 

    $netAmount = $request->net_amount;
    // Calculate the fee amount from net_amount
    $feeAmount = ($netAmount * $feePct) / (1 - $feePct);

    // Store admin fee in register
    $adminFee = (float)get_register('total_fee'); 
    $adminFee +=  $feeAmount * $adminPct;
    set_register('total_fee', $adminFee);


    // Get all active liquidity providers
    $activeLps = Lp::where('active', true)->get();

    if ($activeLps->isEmpty()) {
        return response()->json([
            'success' => true,
            'message' => 'Total fee recorded, but no active liquidity providers to distribute profit.',
            'total_fee' => $feeAmount,
        ]);
    }

    // lp_fee_pct% of the total fee will be distributed among active LPs
     
    $distributableFee = $feeAmount * $lpPct;

    // Total active liquidity
    $totalLiquidity = $activeLps->sum('amount');

    if ($totalLiquidity > 0) {
        foreach ($activeLps as $lp) {
            // proportional profit = (lp.amount / totalLiquidity) * distributableFee
            $profitShare = ($lp->amount / $totalLiquidity) * $distributableFee;
            $lp->profit += $profitShare;
            $lp->save();
        }
    }

    return response()->json([
        'success' => true,
        'message' => 'Total fee recorded and distributed to active LPs.',
        'total_fee' => $feeAmount,
        'distributed_fee' => $distributableFee,
        'total_active_liquidity' => $totalLiquidity,
    ]);
});




Route::post('/update-withdrawal', function (Request $request) {
    // $request->validate([
    //     'recipient' => 'required|string',
    //     'amount' => 'required|numeric|min:0.000000000000000001',
    //     'type' => 'required|string|in:user,admin',
    // ]);

    $recipient = $request->recipient;
    $amount = (float) $request->amount;
    $type = strtolower($request->type);

    try {
        if ($type === 'user') {
            // 🧾 Find the LP record by wallet address
            $lp = Lp::where('wallet_address', $recipient)->first();

            if (!$lp) {
                return response()->json([
                    'success' => false,
                    'message' => 'Liquidity provider not found.',
                ], 404);
            }

            // Check if user has enough profit
            // if ($lp->profit < $amount) {
            //     return response()->json([
            //         'success' => false,
            //         'message' => 'Insufficient profit balance.',
            //     ], 400);
            // }

            // Deduct profit
            $lp->profit = 0;
            $lp->save();

            return response()->json([
                'success' => true,
                'message' => 'User profit withdrawn successfully.',
                'data' => [
                    'wallet_address' => $recipient,
                    'new_profit' => $lp->profit,
                ],
            ]);
        }

        if ($type === 'admin') {
            // 🧾 Deduct from admin total fee register
            $adminFee = (float) get_register('total_fee');
            // if ($adminFee < $amount) {
            //     return response()->json([
            //         'success' => false,
            //         'message' => 'Insufficient total fee balance.',
            //     ], 400);
            // }

            //$adminFee -= $amount;
            set_register('total_fee', 0);

            return response()->json([
                'success' => true,
                'message' => 'Admin total fee updated successfully.',
                'data' => [
                    'total_fee' => $adminFee,
                ],
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Invalid type provided.',
        ], 400);

    } catch (\Throwable $th) {
        return response()->json([
            'success' => false,
            'message' => 'Error processing withdrawal.',
            'error' => $th->getMessage(),
        ], 500);
    }
});