<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Deposit;

class BridgeController extends Controller
{
    public function precheck(Request $request)
    {
        $fromNetwork = $request->input('fromNetwork');
        $toNetwork = $request->input('toNetwork');
        $fromToken = $request->input('fromToken');
        $toToken = $request->input('toToken');
        $amount = (float) $request->input('amount');
        $fromAddress = $request->input('fromAddress');
        $toAddress = $request->input('toAddress');

        if (!$fromNetwork || !$toNetwork || !$fromToken || !$toToken || !$amount || $amount <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Missing or invalid parameters.',
            ], 400);
        }

        // --- Token pricing ---
        $fromTokenPrice = get_token_price($fromToken);
        $toTokenPrice = get_token_price($toToken);
        $nativeTokenSymbol = get_native_token_symbol($toNetwork);
        $nativeTokenPrice = get_token_price($nativeTokenSymbol);
        $feePct = (float)get_register('fee_pct') ?? 0.5;

        if (!$fromTokenPrice || !$toTokenPrice || !$nativeTokenPrice) {
            return response()->json([
                'success' => false,
                'message' => 'Missing token price data.',
            ], 400);
        }

        // --- Convert fromToken → USD → toToken ---
        $usdValue = $amount * $fromTokenPrice;
        $toTokenAmount = $usdValue / $toTokenPrice;

        // --- Convert USD → native token equivalent ---
        $nativeAmount = $usdValue / $nativeTokenPrice;

        // --- Deduct bridge fee ---
        $feeRate = $feePct / 100;
        $tokenAmountAfterFee = $toTokenAmount * (1 - $feeRate);
        $nativeAmountAfterFee = $nativeAmount * (1 - $feeRate);

        // --- Round both amounts to 2dp for NodeJS safety ---
        $tokenAmountAfterFee = round($tokenAmountAfterFee, 8);
        $nativeAmountAfterFee = round($nativeAmountAfterFee, 8);

        $payload = [
            'network' => $toNetwork,
            'token' => $toToken,
            'amount' => $tokenAmountAfterFee,
            'nativeAmount' => $nativeAmountAfterFee,
        ];

        try {
            $response = Http::timeout(20)
                ->post(env('NODE_BRIDGE_URL') . '/bridge/precheck', $payload);

            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Node bridge precheck failed.',
                    'details' => $response->body(),
                ], 500);
            }
            $nodeResponse = $response->json();
            $deposit = Deposit::create([
                'nonce' => generateNounce(),
                'depositor' => $fromAddress,
                'token_from' => $fromToken,
                'token_to' => $toToken,
                'to' => $toAddress,
                'amount_in' => $amount,
                'amount_out' => $tokenAmountAfterFee,
                'timestamp' => now()->timestamp,
                'source_chain' => $fromNetwork,
                'destination_chain' => $toNetwork,
                'status' => 'none',
                'dest_native_amt' => $nativeAmountAfterFee 
            ]);

            return response()->json([
                'success' => true,
                'fee_pct' => $feePct,
                'token_amount' => $tokenAmountAfterFee,
                'native_amount' => $nativeAmountAfterFee,
                'usd_value' => round($usdValue, 4),
                'node_precheck' => $nodeResponse,
                'nonce' => $deposit->nonce,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Precheck error: ' . $e->getMessage(),
            ], 500);
        }
    }


    public function bridge(Request $request)
    {
        $fromNetwork = $request->input('fromNetwork');
        $toNetwork = $request->input('toNetwork');
        $fromToken = $request->input('fromToken');
        $toToken = $request->input('toToken');
        $amount = (float) $request->input('amount');
        $fromAddress = $request->input('fromAddress');
        $toAddress = $request->input('toAddress');

        if (!$fromNetwork || !$toNetwork || !$fromToken || !$toToken || !$amount || !$fromAddress || !$toAddress || $amount <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Missing required parameters.',
            ], 400);
        }

        // --- Token pricing ---
        $fromTokenPrice = get_token_price($fromToken);
        $toTokenPrice = get_token_price($toToken);
        $nativeTokenSymbol = get_native_token_symbol($toNetwork);
        $nativeTokenPrice = get_token_price($nativeTokenSymbol);
        $feePct = (float)get_register('fee_pct') ?? 0.5;

        if (!$fromTokenPrice || !$toTokenPrice || !$nativeTokenPrice) {
            return response()->json([
                'success' => false,
                'message' => 'Missing token price data.',
            ], 400);
        }

        // --- Convert fromToken → toToken ---
        $usdValue = $amount * $fromTokenPrice;
        $toTokenAmount = $usdValue / $toTokenPrice;

        // --- Convert to native token equivalent ---
        $nativeAmount = $usdValue / $nativeTokenPrice;

        // --- Deduct fee ---
        $feeRate = $feePct / 100;
        $tokenAmountAfterFee = round($toTokenAmount * (1 - $feeRate), 8);
        $nativeAmountAfterFee = round($nativeAmount * (1 - $feeRate), 8);

        // --- Save deposit in database ---
        $deposit = Deposit::create([
            'nouns' => generateNounce(),
            'depositor' => $fromAddress,
            'token_from' => $fromToken,
            'token_to' => $toToken,
            'to' => $toAddress,
            'amount_in' => $amount,
            'amount_out' => $tokenAmountAfterFee,
            'timestamp' => now()->timestamp,
            'source_chain' => $fromNetwork,
            'destination_chain' => $toNetwork,
            'status' => 'pending',
            'dest_native_amt' => $nativeAmountAfterFee 
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Bridge request created.',
            'deposit_id' => $deposit->id,
            'token_amount' => $tokenAmountAfterFee,
            'native_amount' => $nativeAmountAfterFee,
            'usd_value' => round($usdValue, 4),
            'status' => 'pending'
        ]);
    }

    public function set_bridge_status()
    {
        $nouns = $request->input('nouns');
        $deposit = Deposit::where('nouns', $nouns)->where('status', 'none')->first();
        if (!$deposit) {
            return response()->json(['success' => false, 'message' => 'Deposit not found or already processed.'], 404);
        }
        $deposit->status = 'pending';
        $deposit->save();
        return response()->json(['success' => true]);
    }

}
