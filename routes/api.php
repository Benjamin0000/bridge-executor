<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Models\Lp;

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


Route::post('/add-liquidity', function (Request $request) {

    $request->validate([
        'wallet_address' => 'required|string',
        'amount' => 'required|numeric|min:0',
    ]);

    $wallet = $request->wallet_address;
    $amount = $request->amount;

    $existingLp = Lp::where('wallet_address', $wallet)->latest()->first();

    if ($existingLp && $existingLp->active) {
        //If active LP exists — add to amount
        $existingLp->amount += $amount;
        $existingLp->save();

        return response()->json([
            'success' => true,
            'message' => 'Liquidity added to existing active provider.',
            'data' => $existingLp,
        ]);
    }

    //If no active LP or LP inactive — create new record
    $newLp = Lp::create([
        'wallet_address' => $wallet,
        'amount' => $amount,
        'profit' => 0,
        'active' => true,
    ]);

    return response()->json([
        'success' => true,
        'message' => 'New liquidity provider record created.',
        'data' => $newLp,
    ]);
});



Route::get('/user-liquidity', function (Request $request) {
    $request->validate([
        'wallet_address' => 'required|string',
    ]);

    $wallet = $request->wallet_address;

    // Get all active LP records for the wallet
    $activeLps = Lp::where('wallet_address', $wallet)
                    ->where('active', true)
                    ->get();

    // Calculate total liquidity and total profit
    $totalLiquidity = $activeLps->sum('amount');
    $totalProfit = $activeLps->sum('profit');

    return response()->json([
        'success' => true,
        'wallet_address' => $wallet,
        'total_liquidity' => $totalLiquidity, 
        'profit' => $totalProfit             
    ]);
});



Route::post('/distribute-fee', function (Request $request) {
    $request->validate([
        'net_amount' => 'required|numeric|min:0',
    ]);

    $netAmount = $request->net_amount;
    $feePct = (float)get_register('fee_pct') / 100;

    // Calculate the fee amount from net_amount
    $feeAmount = ($netAmount * $feePct) / (1 - $feePct);

    $totalFee = $feeAmount;


    // Store total fee in register
    $totalFee += (float)get_register('total_fee'); 
    set_register('total_fee', $totalFee);

    // Get all active liquidity providers
    $activeLps = Lp::where('active', true)->get();

    if ($activeLps->isEmpty()) {
        return response()->json([
            'success' => true,
            'message' => 'Total fee recorded, but no active liquidity providers to distribute profit.',
            'total_fee' => $totalFee,
        ]);
    }

    // lp_fee_pct% of the total fee will be distributed among active LPs
    $pct = (float)get_register('lp_fee_pct') / 100; 
    $distributableFee = $totalFee * $pct;

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
        'total_fee' => $totalFee,
        'distributed_fee' => $distributableFee,
        'total_active_liquidity' => $totalLiquidity,
    ]);
});