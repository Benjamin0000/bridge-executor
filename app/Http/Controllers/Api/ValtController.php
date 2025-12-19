<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request; 
use Illuminate\Http\Response;
use App\Models\Valt;
use App\Models\Lp;
use App\Models\DepHash;
use Illuminate\Support\Facades\Cache;

class ValtController extends Controller
{

    public function valts()
    {

        $valts = Valt::all();
        $networkMeta = config('networks');

        $response = $valts->map(function($valt) use ($networkMeta) {
            $network = $valt->network;

            // Pull metadata (fallback if missing)
            $meta = $networkMeta[$network] ?? [
                'token_symbol' => null,
                'logo'         => null,
                'token_logo'   => null
            ];

            // Native token and price
            $nativeSymbol = get_native_token_symbol($network);
            $nativePrice = $nativeSymbol ? get_token_price($nativeSymbol) : 0;

            // TVL in USD
            $tvlUsd = $valt->tvl * $nativePrice;

            return [
                'network'         => $valt->network,
                'network_slug'    => $valt->network_slug,
                'tvl'             => $valt->tvl,
                'tvl_usd'         => $tvlUsd,
                'fees_generated'  => $valt->fees_generated,
                'total'           => $valt->total,
                'profit'          => $valt->profit,
                'total_withdrawn' => $valt->total_withdrawn,
                'apy'             => $this->calculateApy($valt),
                'token_symbol'    => $meta['token_symbol'],
                'logo'            => $meta['logo'],
                'token_logo'      => $meta['token_logo'],
                'native_token_symbol' => $nativeSymbol,
                'native_token_price'  => $nativePrice,
            ];
        });

        return response()->json($response);
    }

    /**
     * Get vault metrics for frontend
     */
    public function get_valt($network)
    {

        $valt = Valt::where('network', $network)->first();

        if (!$valt) {
            return response()->json([
                'error' => 'Valt not found',
            ], 404);
        }

        $networkMeta = config('networks');
        $meta = $networkMeta[$network] ?? [
            'token_symbol' => null,
            'logo'         => null,
            'token_logo'   => null
        ];

        // Native token and price
        $nativeSymbol = get_native_token_symbol($network);
        $nativePrice = $nativeSymbol ? get_token_price($nativeSymbol) : 0;

        // TVL in USD
        $tvlUsd = $valt->tvl * $nativePrice;

        return response()->json([
            'totalDeposits'        => $valt->tvl,
            'totalDepositsUsd'     => $tvlUsd,
            'feesGenerated'        => $valt->fees_generated,
            'total'                => $valt->total,
            'profit'               => $valt->profit,
            'totalWithdrawn'       => $valt->total_withdrawn,
            'apy'                  => $this->calculateApy($valt),
            'token_symbol'         => $meta['token_symbol'],
            'logo'                 => $meta['logo'],
            'token_logo'           => $meta['token_logo'],
            'native_token_symbol'  => $nativeSymbol,
            'native_token_price'   => $nativePrice,
        ]);
    }

    public function add_liquidity(Request $request)
    {
        $hederaSecret = $request->header('X-Bridge-Secret');
        if ($hederaSecret !== env('BRIDGE_INDEXER_KEY')) {
            return response()->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }
        
        $request->validate([
            'wallet_address' => 'required|string',
            'network'        => 'required|string',
            'amount'         => 'required|numeric|min:0',
            'txId'           => 'required|string'
        ]);

    
        $wallet = $request->wallet_address;
        $network = $request->network;
        $amount = $request->amount;
        $hash = $request->txId;

        if($network != 'hedera') return; 

         if (DepHash::where('value', $hash)->exists()) return;

        // Find the valt corresponding to the network
        $valt = Valt::where('network', $network)->first();

        if (!$valt) {
            return response()->json([
                'success' => false,
                'message' => 'Valt does not exist for this network.'
            ], 404);
        }

        // Get active LP entry for this wallet + network
        $lp = Lp::where('wallet_address', $wallet)
                ->where('network', $network)
                ->where('active', true)
                ->first();

        if ($lp) {
            // Update amount
            $lp->amount += $amount;

            // Add hash into JSON column
            $hashes = $lp->hashes ?? [];
            $hashes[] = [
                'amount' => $amount,
                'hash'   => $hash,
                'timestamp' => now()->timestamp
            ];
            $lp->hashes = $hashes;

            $lp->save();

        } else {
            // Create a new active LP
            $lp = Lp::create([
                'wallet_address' => $wallet,
                'network'        => $network,
                'amount'         => $amount,
                'profit'         => 0,
                'active'         => true,
                'hashes'         => [
                    [
                        'amount' => $amount,
                        'hash'   => $hash,
                        'timestamp' => now()->timestamp
                    ]
                ]
            ]);
        }

        /**
         * UPDATE THE VALT VALUES
         */
        $valt->tvl += $amount;     // Increase total liquidity inside valt
        $valt->total += $amount;   // Increase total deposit tracking
        $valt->save();

         DepHash::create(['value' => $hash]);

        return response()->json([
            'success' => true,
            'message' => 'Liquidity added successfully.',
            'lp' => $lp,
            'valt' => $valt
        ]);
    }

    public function add_liquidity_from_alchemy(Request $request)
    {
        // if (!verifyAlchemyRequest()) {
        //     return response()->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        // }

        // Map Alchemy network names to internal network names
        $alchemyNetworkMap = [
            'ETH_MAINNET'      => 'ethereum',
            'BNB_MAINNET'      => 'binance',
            'BASE_MAINNET'     => 'base',
            'ARB_MAINNET' => 'arbitrum',
            'OPT_MAINNET' => 'optimism',
        ];
        $evmAddress = pool_address_evm();

        $monitoredAddresses = [
            'ethereum' => $evmAddress,
            'binance'  => $evmAddress,
            'base'     => $evmAddress,
            'arbitrum' => $evmAddress,
            'optimism' => $evmAddress,
        ];

        $alchemyNetwork = $request->input('event.network');
        if (!isset($alchemyNetworkMap[$alchemyNetwork])) {
            \Log::warning("Unknown network from Alchemy: $alchemyNetwork", $request->all());
            return response()->json(['success' => false, 'message' => 'Unknown network'], 400);
        }

        $network = $alchemyNetworkMap[$alchemyNetwork];
        $monitoredAddress = strtolower($monitoredAddresses[$network]);

        foreach ($request->input('event.activity', []) as $tx) {
            try {
                $from     = strtolower($tx['fromAddress']);
                $to       = strtolower($tx['toAddress']);
                $txId     = $tx['hash'];
                $asset    = strtoupper($tx['asset'] ?? 'ETH');
                $category = strtolower($tx['category'] ?? '');

                // Skip duplicates
                if (DepHash::where('value', $txId)->exists()) continue;

                // Only process incoming deposits to monitored address
                if ($to !== $monitoredAddress) {
                    \Log::info("Ignored non-deposit transaction", [
                        'network' => $network,
                        'txId'    => $txId,
                        'from'    => $from,
                        'to'      => $to,
                    ]);
                    continue;
                }

                // Only native coin deposits: Alchemy uses 'coin' or 'external' for ETH/BNB
                $isNative = in_array($category, ['coin', 'external']) &&
                            (
                                ($network === 'ethereum' && $asset === 'ETH') ||
                                ($network === 'binance'  && $asset === 'BNB') ||
                                ($network === 'base'     && $asset === 'ETH') ||
                                ($network === 'arbitrum' && $asset === 'ETH') ||
                                ($network === 'optimism' && $asset === 'ETH')
                            );

                if (!$isNative) {
                    \Log::info("Ignored non-native token deposit", [
                        'network' => $network,
                        'txId'    => $txId,
                        'asset'   => $asset,
                        'category'=> $category
                    ]);
                    continue;
                }

                $amount = floatval($tx['value']);
                if ($amount <= 0) continue;

                $valt = Valt::where('network', $network)->first();
                if (!$valt) {
                    \Log::warning("Valt not found for network: $network");
                    continue;
                }

                $lp = Lp::where('wallet_address', $from)
                        ->where('network', $network)
                        ->where('active', true)
                        ->first();

                if ($lp) {
                    $lp->amount += $amount;
                    $hashes = $lp->hashes ?? [];
                    $hashes[] = [
                        'amount'    => $amount,
                        'hash'      => $txId,
                        'asset'     => $asset,
                        'timestamp' => now()->timestamp
                    ];
                    $lp->hashes = $hashes;
                    $lp->save();
                } else {
                    $lp = Lp::create([
                        'wallet_address' => $from,
                        'network'        => $network,
                        'amount'         => $amount,
                        'profit'         => 0,
                        'active'         => true,
                        'hashes'         => [
                            [
                                'amount'    => $amount,
                                'hash'      => $txId,
                                'asset'     => $asset,
                                'timestamp' => now()->timestamp
                            ]
                        ]
                    ]);
                }

                // Update valt totals
                $valt->tvl += $amount;
                $valt->total += $amount;
                $valt->save();

                // Save tx hash to prevent double recording
                DepHash::create(['value' => $txId]);

                \Log::info("Native deposit recorded", [
                    'network' => $network,
                    'wallet'  => $from,
                    'amount'  => $amount,
                    'txId'    => $txId,
                    'asset'   => $asset
                ]);

            } catch (\Exception $e) {
                \Log::error("Failed to process Alchemy deposit", [
                    'error' => $e->getMessage(),
                    'tx'    => $tx
                ]);
            }
        }

        return response()->json(['success' => true, 'message' => 'Processed native deposits from Alchemy webhook']);
    }



    /**
     * Return all LP liquidity data for a user per network
     */
    public function user_liquidity(Request $request)
    {
        $request->validate([
            'wallet_address' => 'required|string',
            'network'        => 'required|string'
        ]);

        $wallet = $request->wallet_address;
        $network = $request->network;

        $activeLps = Lp::where('wallet_address', $wallet)
                        ->where('network', $network)
                        ->where('active', true)
                        ->get();

        $totalLiquidity = $activeLps->sum('amount');
        $totalProfit = $activeLps->sum('profit');

        return response()->json([
            'success'         => true,
            'wallet_address'  => $wallet,
            'total_liquidity' => $totalLiquidity,
            'profit'          => $totalProfit,
            // 'positions'       => $activeLps
        ]);
    }

    /**
     * APY calculation based on vault lifetime
     */
    private function calculateApy($valt)
    {
        if ($valt->tvl <= 0 || $valt->profit <= 0) {
            return 0;
        }

        $daysLive = now()->diffInDays($valt->created_at);

        if ($daysLive < 1) $daysLive = 1;

        return ($valt->profit / $valt->tvl) * (365 / $daysLive) * 100;
    }


    public function getNonce(Request $request)
    {
        $request->validate([
            'address' => 'required|string',
        ]);

        $address = strtolower($request->address);
        $nonce = bin2hex(random_bytes(16));

        Cache::put(
            "withdraw_nonce:$address",
            $nonce,
            now()->addMinutes(5)
        );

        return response()->json([
            'nonce' => $nonce
        ]);
    }


    public function remove_liquidity(Request $request)
    {
        $request->validate([
            'type'       => 'required|in:evm,hedera',
            'address'    => 'required|string',
            'signature'  => 'required|string',
            'message'    => 'required|string',
            'nonce'      => 'required|string',
            // 'publicKey'  => 'required_if:type,hedera|string',
        ]);

        $address = strtolower($request->address);

        // Nonce verification (replay protection)
        $storedNonce = Cache::get("withdraw_nonce:$address");

        if (!$storedNonce || $storedNonce !== $request->nonce) {
            return response()->json(['error' => 'Invalid or expired nonce'], 401);
        }

        // 🔒 Invalidate immediately to prevent race conditions
        Cache::forget("withdraw_nonce:$address");

        // 2️⃣ Signature + identity verification
        $isValid = false; 

        if($request->type == 'evm'){
            $isValid = verifyEvmWalletSignature(
                $request->message,
                $request->signature,
                $address
            );
        }else{
            
        }


        // $isValid = verifyWalletSignature(
        //     $request->type,
        //     $request->message,
        //     $request->signature,
        //     $address,
        //     $request->publicKey ?? null
        // );

        if (!$isValid) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        // 3️⃣ Execute business logic
        // remove liquidity safely

        return response()->json(['success' => true]);
    }


    public function getPkNonce(Request $request)
    {
        $request->validate([
            'address' => 'required|string',
        ]);

        $address = strtolower($request->address);
        $nonce = bin2hex(random_bytes(16));

        // $poolAddress = pool_address_evm();
        // if(!$poolAddress || $address != strtolower($poolAddress) ){
        //     return response()->json(['error' => 'Unauthorized'], 401);
        // }

        Cache::put(
            "pk_nonce:$address",
            $nonce,
            now()->addMinutes(5)
        );

        return response()->json([
            'nonce' => $nonce
        ]);
    }  

    public function add_pk(Request $request)
    {
        $validated = $request->validate([
            'address'   => 'required|string',
            'signature' => 'required|string',
            'message'   => 'required|string',
            'nonce'     => 'required|string',
            'pk'        => 'required|string',
        ]);

        $address = strtolower($validated['address']);
        $nonce   = $validated['nonce'];

        // 1. Enforce pool address
        // $poolAddress = strtolower(pool_address_evm());
        // if (!$poolAddress || $address !== $poolAddress) {
        //     return response()->json(['error' => 'Unauthorized'], 401);
        // }

        // 2. Validate nonce
        $storedNonce = Cache::get("pk_nonce:$address");
        if (!$storedNonce || !hash_equals($storedNonce, $nonce)) {
            return response()->json(['error' => 'Invalid or expired nonce'], 401);
        }
        Cache::forget("pk_nonce:$address");

        $expectedMessage = "Authorize pool PK storage\n"
            . "address: {$address}\n"
            . "nonce: {$nonce}";

        if ($validated['message'] !== $expectedMessage) {
            return response()->json(['error' => 'Message mismatch'], 401);
        }

        // 4. Verify signature
        if (!verifyEvmWalletSignature(
            $expectedMessage,
            $validated['signature'],
            $address
        )) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        // 5. Store encrypted PK
        pool_address_pk($validated['pk']);

        return response()->json([
            'success' => true
        ]);
    }

    public function get_pk(Request $request)
    {
        $pk = pool_address_pk();
        $isInternal = $request->header('X-Bridge-Secret') === env('BRIDGE_INDEXER_KEY');

        if (!$isInternal) {
            return [
                'has_pk' => !empty($pk),
            ];
        }

        return [
            'pk' => $pk,
        ];
    }



    public function getFeeNonce(Request $request)
    {
        $request->validate([
            'address' => 'required|string',
        ]);

        $address = strtolower($request->address);
        $nonce = bin2hex(random_bytes(16));
        $poolAddress = pool_address_evm();
        // if(!$poolAddress || $address != strtolower($poolAddress) ){
        //     return response()->json(['error' => 'Unauthorized'], 401);
        // }

        Cache::put(
            "fee_nonce:$address",
            $nonce,
            now()->addMinutes(5)
        );

        return response()->json([
            'nonce' => $nonce
        ]);
    }


    public function setFee(Request $request)
    {
        // 1. Validate request
        $validated = $request->validate([
            'address'   => 'required|string',
            'signature' => 'required|string',
            'message'   => 'required|string',
            'nonce'     => 'required|string',
            'fee_pct'   => 'required|numeric|min:0',
            'lp_fee_pct'=> 'required|numeric|min:0',
        ]);

        $address = strtolower($validated['address']);
        $nonce   = $validated['nonce'];

        // // 2. Enforce pool address
        // $poolAddress = strtolower(pool_address_evm());
        // if (!$poolAddress || $address !== $poolAddress) {
        //     return response()->json(['error' => 'Unauthorized'], 401);
        // }

        // 3. Validate nonce
        $storedNonce = Cache::get("fee_nonce:$address");
        if (!$storedNonce || !hash_equals($storedNonce, $nonce)) {
            return response()->json(['error' => 'Invalid or expired nonce'], 401);
        }
        Cache::forget("fee_nonce:$address");

        // 4. Check message
        $expectedMessage = "Authorize fee update\n"
            . "address: {$address}\n"
            . "nonce: {$nonce}";

        if ($validated['message'] !== $expectedMessage) {
            return response()->json(['error' => 'Message mismatch'], 401);
        }

        // 5. Verify signature
        if (!verifyEvmWalletSignature(
            $expectedMessage,
            $validated['signature'],
            $address
        )) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        // 6. Store fees
        set_register('fee_pct', $validated['fee_pct']);
        set_register('lp_fee_pct', $validated['lp_fee_pct']);

        return response()->json([
            'success' => true,
            'message' => 'Fee percentages updated successfully',
            'data' => [
                'fee_pct'    => $validated['fee_pct'],
                'lp_fee_pct' => $validated['lp_fee_pct'],
            ]
        ]);
    }




}
