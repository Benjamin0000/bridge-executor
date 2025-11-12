<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\TokenPrice;

class BridgeController extends Controller
{
    public function precheck(Request $request)
    {
        $fromNetwork = $request->input('fromNetwork');
        $toNetwork = $request->input('toNetwork');
        $fromToken = $request->input('fromToken');
        $toToken = $request->input('toToken');
        $amount = (float) $request->input('amount');

        // --- Basic validation ---
        if (!$fromNetwork || !$toNetwork || !$fromToken || !$toToken || !$amount || $amount <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Missing or invalid parameters.',
            ], 400);
        }

        // --- Token pricing ---
        $fromTokenPrice = get_token_price($fromToken);
        $toTokenPrice = get_token_price($toToken);
        $feePct = (float)get_register('fee_pct') ?? 0.5;

        if (!$fromTokenPrice || !$toTokenPrice) {
            return response()->json([
                'success' => false,
                'message' => 'Missing token price data.',
            ], 400);
        }

        // --- Convert fromToken to toToken ---
        $usdValue = $amount * $fromTokenPrice; // USD value of amount of fromToken
        $toTokenAmount = $usdValue / $toTokenPrice; // equivalent amount of toToken

        // --- Deduct bridge fee ---
        $feeRate = $feePct / 100;
        $tokenAmountAfterFee = $toTokenAmount * (1 - $feeRate);
        $nativeAmountAfterFee = $tokenAmountAfterFee; // same value for nativeAmount field

        // --- Prepare NodeJS precheck payload ---
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

            return response()->json([
                'success' => true,
                'fee_pct' => $feePct,
                'token_amount' => round($tokenAmountAfterFee, 8),
                'native_amount' => round($nativeAmountAfterFee, 8),
                'usd_value' => round($usdValue, 4),
                'node_precheck' => $nodeResponse,
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
        $isNative = (bool) $request->input('isNative', false);

        if (!$fromNetwork || !$toNetwork || !$fromToken || !$toToken || !$amount || !$fromAddress) {
            return response()->json([
                'success' => false,
                'message' => 'Missing required parameters.',
            ], 400);
        }

        // --- Convert fromToken to toToken using prices ---
        $fromTokenPrice = get_token_price($fromToken);
        $toTokenPrice = get_token_price($toToken);
        $feePct = (float) get_register('fee_pct') ?? 0.5;

        if (!$fromTokenPrice || !$toTokenPrice) {
            return response()->json([
                'success' => false,
                'message' => 'Missing token price data.',
            ], 400);
        }

        // USD value of amount of fromToken
        $usdValue = $amount * $fromTokenPrice;

        // Convert to target token amount
        $toTokenAmount = $usdValue / $toTokenPrice;

        // Deduct fee
        $feeRate = $feePct / 100;
        $tokenAmountAfterFee = $toTokenAmount * (1 - $feeRate);
        $nativeAmountAfterFee = $tokenAmountAfterFee; // same value, just in native units

        // NodeJS execute payload
        $payload = [
            'network' => $toNetwork,
            'token' => $toToken,
            'amount' => $tokenAmountAfterFee,
            'nativeAmount' => $nativeAmountAfterFee,
            'recipient' => $fromAddress,
            'isNative' => $isNative,
        ];

        try {
            $response = Http::timeout(30)
                ->post(env('NODE_BRIDGE_URL') . '/bridge/execute', $payload);

            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Node bridge execution failed.',
                    'details' => $response->body(),
                ], 500);
            }

            $nodeResponse = $response->json();

            return response()->json([
                'success' => true,
                'fee_pct' => $feePct,
                'token_amount' => round($tokenAmountAfterFee, 8),
                'native_amount' => round($nativeAmountAfterFee, 8),
                'usd_value' => round($usdValue, 4),
                'node_response' => $nodeResponse,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Bridge execution error: ' . $e->getMessage(),
            ], 500);
        }
    }
}
