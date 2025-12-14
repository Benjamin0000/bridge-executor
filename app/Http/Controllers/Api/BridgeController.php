<?php

namespace App\Http\Controllers\Api;

use App\Services\EvmEventDecoder; 

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use App\Models\Deposit;
use App\Jobs\ProcessDeposit; 
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr; 



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
        $tokenAmountAfterFee = $tokenAmountAfterFee;
        $nativeAmountAfterFee = $nativeAmountAfterFee;

        $payload = [
            'network' => $toNetwork,
            'token' => $toToken,
            'amount' => $tokenAmountAfterFee,
            'nativeAmount' => $nativeAmountAfterFee,
            'fromNetwork' => $fromNetwork, 
            'fromAddress' => $fromAddress, 
            'fromToken' => $fromToken, 
            'fromAmount' => $amount
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
            $nonce = generateNounce();
            $deposit = Deposit::create([
                'nonce' => $nonce,
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
                'dest_native_amt' => $nativeAmountAfterFee, 
                'nonce_hash'=>keccak256($nonce)
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
        $payload = $request->all();
        // ----------------------------
        // 1. Handle EVM events (Alchemy)
        // ----------------------------
        $evmBlock = Arr::get($payload, 'event.data.block');

        if (!empty($evmBlock)) {
            //  Log::info('Alchemy access payload. ', $payload);
            // if (!verifyAlchemyRequest()) {
            //     Log::info('Alchemy access faild. ');
            //     return response()->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
            // }

            // Log::info('Alchemy access verified. ');

            $logs = Arr::get($evmBlock, 'logs', []);
            $decoder = new EvmEventDecoder();

            foreach ($logs as $log) {
                $decoded = $decoder->decodeLog($log);

                if (!$decoded) continue;

                // --- BridgeDeposit ---
                if (($decoded['event'] ?? null) === 'BridgeDeposit') {
                    $eventNonce = $decoded['decoded']['nonce'] ?? null;

                    if ($eventNonce) {
                        $deposit = Deposit::where('nonce_hash', '0x'.$eventNonce)
                                          ->where('status', 'none')
                                          ->first();

                        if (!$deposit) {
                            Log::warning("Deposit not found for nonce: {$eventNonce}");
                            continue;
                        }

                        $deposit->status = 'pending';
                        $deposit->save();

                        ProcessDeposit::dispatch($deposit);
                        Log::info("BridgeDeposit processed for nonce: {$eventNonce}");
                    }

                // --- PoolAddressUpdated ---
                } elseif (($decoded['event'] ?? null) === 'PoolAddressUpdated') {
                    $oldAddress = $decoded['decoded']['oldPool'] ?? null;
                    $newAddress = $decoded['decoded']['newPool'] ?? null;

                    if ($newAddress) {
                        // Update pool address in your system
                        pool_address_evm($newAddress);
                        Log::info("Pool address updated from {$oldAddress} to {$newAddress}");
                    }
                }
            }

            return response()->json([
                'status' => 'EVM logs processed successfully',
                'decoded_logs_count' => count($logs)
            ], Response::HTTP_OK);
        }

        // ----------------------------
        // 2. Handle Hedera events
        // ----------------------------
        $hederaSecret = $request->header('X-Bridge-Secret');

        if ($hederaSecret !== env('BRIDGE_INDEXER_KEY')) {
            return response()->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $eventNonce = $request->input('nonceHash');
        if (!$eventNonce) {
            return response()->json([
                'success' => false,
                'message' => 'Nonce hash is required'
            ], Response::HTTP_BAD_REQUEST);
        }

        $deposit = Deposit::where('nonce_hash', $eventNonce)
                          ->where('status', 'none')
                          ->first();

        if ($deposit) {
            $deposit->status = 'pending';
            $deposit->save();

            ProcessDeposit::dispatch($deposit);
            Log::info("Hedera deposit processed for nonce: {$eventNonce}");
        }
        return response()->json([
            'status' => 'Hedera event processed successfully',
        ], Response::HTTP_OK);
    }

    public function get_bridge_status(Request $request)
    {
        $nonce = $request->input('nonce');
        $deposit = Deposit::where('nonce', $nonce)->first();
        if (!$deposit) {
            return response()->json(['success' => false, 'message' => 'Deposit not found or already processed.', 'nonce'=>$nonce], 404);
        }

        return [
            'status'=>$deposit->status, 
            'withdrawHash'=>$deposit->release_tx_hash
        ]; 
        return response()->json(['success' => true]);
    }

    public function mint($nonce)
    {
        $deposit = Deposit::where('nonce', $nonce)->first();

        if (!$deposit) {
            return response()->json(['error' => 'Deposit not found'], 404);
        }

        // Determine Hedera account
        $userAccountId = null;
        if ($deposit->source_chain === "hedera") {
            $userAccountId = $deposit->depositor;
        } else if ($deposit->destination_chain === "hedera") {
            $userAccountId = $deposit->to;
        }

        if (!$userAccountId) {
            return response()->json(['error' => 'No Hedera account found for this deposit'], 400);
        }

        // Prepare data for mint
        $fromToken = $deposit->token_from;
        $fromNetwork = $deposit->source_chain;
        $toToken = $deposit->token_to;
        $toNetwork = $deposit->destination_chain;
        $fromAmountText = number_format($deposit->amount_in, 6) . ' ' . $fromToken;
        $toAmountText = number_format($deposit->amount_out, 6) . ' ' . $toToken;
        $timestampLeft = $deposit->created_at->isoFormat('lll');
        $timestampRight = $deposit->updated_at->isoFormat('lll');
        $transactionHash = $deposit->release_tx_hash;
        $bigAmountText = number_format((float)get_token_price($fromToken) * (float)$deposit->amount_in, 3) . ' USDT';
        $sessionId = $nonce;

        // Build payload for Node /mint
        $payload = [
            'userAccountId' => $userAccountId,
            'fromToken' => $fromToken,
            'fromNetwork' => $fromNetwork,
            'toToken' => $toToken,
            'toNetwork' => $toNetwork,
            'fromAmountText' => $fromAmountText,
            'toAmountText' => $toAmountText,
            'timestampLeft' => $timestampLeft,
            'timestampRight' => $timestampRight,
            'transactionHash' => $transactionHash,
            'bigAmountText' => $bigAmountText,
            'sessionId' => $sessionId,
        ];

        try {
            $response = Http::timeout(20)
                ->post(env('LOCALHOST_URL', 'http://localhost:7000') . '/mint', $payload);

            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Node bridge mint failed.',
                    'details' => $response->body(),
                ], 500);
            }

            $nodeResponse = $response->json();

            return response()->json([
                'success' => true,
                'nodeResponse' => $nodeResponse
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Mint request error: ' . $e->getMessage(),
            ], 500);
        }
    }


}
