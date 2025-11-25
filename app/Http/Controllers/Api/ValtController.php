<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Valt;
use App\Models\Lp;
use App\Models\DepHash;
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

    // Monitored deposit addresses per network
    $monitoredAddresses = [
        'ethereum' => '0xf10ee4cf289d2f6b53a90229ce16b8646e724418',
        'binance'  => '0xf10ee4cf289d2f6b53a90229ce16b8646e724418',
        'base'     => '0xf10ee4cf289d2f6b53a90229ce16b8646e724418',
        'arbitrum' => '0xf10ee4cf289d2f6b53a90229ce16b8646e724418',
        'optimism' => '0xf10ee4cf289d2f6b53a90229ce16b8646e724418',
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
            $from   = strtolower($tx['fromAddress']);
            $to     = strtolower($tx['toAddress']);
            $txId   = $tx['hash'];
            $asset  = strtoupper($tx['asset'] ?? 'ETH');
            $category = strtolower($tx['category'] ?? ''); // coin/token

            // Skip duplicate transactions
            if (DepHash::where('value', $txId)->exists()) {
                continue;
            }

            // Only process incoming deposits (to the monitored address)
            if ($to !== $monitoredAddress) {
                \Log::info("Ignored outgoing/other transaction", [
                    'network' => $network,
                    'txId'    => $txId,
                    'from'    => $from,
                    'to'      => $to,
                ]);
                continue;
            }

            // Only process native coin deposits (ignore tokens)
            if ($category !== 'coin') {
                \Log::info("Ignored non-native token transfer", [
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

            $valt->tvl   += $amount;
            $valt->total += $amount;
            $valt->save();

            // Mark transaction as processed
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
}
