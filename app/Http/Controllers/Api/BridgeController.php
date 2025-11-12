<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

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
        $nativeTokenSymbol = get_native_token_symbol($toNetwork);
        $nativeTokenPrice = get_token_price($nativeTokenSymbol);
        $feePct = (float)get_register('fee_pct') ?? 0.5;

        if (!$fromTokenPrice || !$toTokenPrice || !$nativeTokenPrice) {
            return response()->json([
                'success' => false,
                'message' => 'Missing token price data.',
            ], 400);
        }

        // --- Convert fromToken to toToken ---
        $usdValue = $amount * $fromTokenPrice;
        $toTokenAmount = $usdValue / $toTokenPrice;

        // --- Equivalent native token amount ---
        $nativeAmount = $usdValue / $nativeTokenPrice;

        // --- Deduct bridge fee ---
        $feeRate = $feePct / 100;
        $tokenAmountAfterFee = $toTokenAmount * (1 - $feeRate);
        $nativeAmountAfterFee = $nativeAmount * (1 - $feeRate);

        // --- Optional: truncate to safe decimals for NodeJS (to avoid ethers parseUnits errors) ---
        $tokenDecimals = get_token_decimals($toToken, $toNetwork); // function should return token decimals
        $nativeDecimals = get_token_decimals($nativeTokenSymbol, $toNetwork);
        $tokenAmountAfterFee = floor($tokenAmountAfterFee * (10 ** $tokenDecimals)) / (10 ** $tokenDecimals);
        $nativeAmountAfterFee = floor($nativeAmountAfterFee * (10 ** $nativeDecimals)) / (10 ** $nativeDecimals);

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
                'token_amount' => $tokenAmountAfterFee,
                'native_amount' => $nativeAmountAfterFee,
                'usd_value' => $usdValue,
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

        // --- Convert fromToken to toToken ---
        $usdValue = $amount * $fromTokenPrice;
        $toTokenAmount = $usdValue / $toTokenPrice;
        $nativeAmount = $usdValue / $nativeTokenPrice;

        // --- Deduct bridge fee ---
        $feeRate = $feePct / 100;
        $tokenAmountAfterFee = $toTokenAmount * (1 - $feeRate);
        $nativeAmountAfterFee = $nativeAmount * (1 - $feeRate);

        // --- Truncate to safe decimals ---
        $tokenDecimals = get_token_decimals($toToken, $toNetwork);
        $nativeDecimals = get_token_decimals($nativeTokenSymbol, $toNetwork);
        $tokenAmountAfterFee = floor($tokenAmountAfterFee * (10 ** $tokenDecimals)) / (10 ** $tokenDecimals);
        $nativeAmountAfterFee = floor($nativeAmountAfterFee * (10 ** $nativeDecimals)) / (10 ** $nativeDecimals);

        // --- NodeJS execute payload ---
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
                'token_amount' => $tokenAmountAfterFee,
                'native_amount' => $nativeAmountAfterFee,
                'usd_value' => $usdValue,
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
