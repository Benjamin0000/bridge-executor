<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Valt;
use App\Models\Lp;
use Illuminate\Support\Facades\Artisan;

class ValtController extends Controller
{

    public function valts()
    {
        // Artisan::call('app:update-liquidity-balance');

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
        // Artisan::call('app:update-liquidity-balance');

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
        // Map Alchemy network names to your internal network names
        $alchemyNetworkMap = [
            'ETH_MAINNET'      => 'ethereum',
            'BSC_MAINNET'      => 'binance',
            'BASE_MAINNET'     => 'base',
            'ARBITRUM_MAINNET' => 'arbitrum',
            'OPTIMISM_MAINNET' => 'optimism',
        ];

        $alchemyNetwork = $request->input('event.network');

        if (!isset($alchemyNetworkMap[$alchemyNetwork])) {
            \Log::warning("Unknown network from Alchemy: $alchemyNetwork", $request->all());
            return response()->json(['success'=>false, 'message'=>'Unknown network'], 400);
        }

        $network = $alchemyNetworkMap[$alchemyNetwork];

        foreach ($request->input('event.activity', []) as $tx) {
            try {
                $wallet = $tx['fromAddress'];
                $txId = $tx['hash'];
                $asset = strtoupper($tx['asset'] ?? 'ETH');

                // Only process native coin deposits
                if (
                    ($network === 'ethereum' && $asset !== 'ETH') ||
                    ($network === 'binance'  && $asset !== 'BNB') ||
                    ($network === 'base'     && $asset !== 'ETH') ||
                    ($network === 'arbitrum' && $asset !== 'ETH') ||
                    ($network === 'optimism' && $asset !== 'ETH')
                ) {
                    \Log::info("Ignored non-native token deposit", [
                        'network' => $network,
                        'asset' => $asset,
                        'txId' => $txId
                    ]);
                    continue;
                }

                $rawValue = $tx['value'] ?? 0;
                $decimals = $tx['rawContract']['decimals'] ?? 18;
                $amount = floatval($rawValue) / (10 ** $decimals);

                if ($amount <= 0) continue;

                $valt = Valt::where('network', $network)->first();
                if (!$valt) {
                    \Log::warning("Valt not found for network: $network");
                    continue;
                }

                $lp = Lp::where('wallet_address', $wallet)
                        ->where('network', $network)
                        ->where('active', true)
                        ->first();

                if ($lp) {
                    $lp->amount += $amount;

                    $hashes = $lp->hashes ?? [];
                    $hashes[] = [
                        'amount' => $amount,
                        'hash'   => $txId,
                        'asset'  => $asset,
                        'timestamp' => now()->timestamp
                    ];
                    $lp->hashes = $hashes;

                    $lp->save();
                } else {
                    $lp = Lp::create([
                        'wallet_address' => $wallet,
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

                $valt->tvl += $amount;
                $valt->total += $amount;
                $valt->save();

                \Log::info("Native token deposit recorded", [
                    'wallet' => $wallet,
                    'network'=> $network,
                    'amount' => $amount,
                    'txId'   => $txId,
                    'asset'  => $asset
                ]);

            } catch (\Exception $e) {
                \Log::error("Failed to process Alchemy deposit", [
                    'error' => $e->getMessage(),
                    'tx'    => $tx
                ]);
            }
        }

        return response()->json(['success'=>true, 'message'=>'Processed native deposits from Alchemy webhook']);
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
}
