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

    /**
     * Add liquidity into LP + update the valt accordingly
     */
    public function add_liquidity(Request $request)
    {
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

        return response()->json([
            'success' => true,
            'message' => 'Liquidity added successfully.',
            'lp' => $lp,
            'valt' => $valt
        ]);
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
