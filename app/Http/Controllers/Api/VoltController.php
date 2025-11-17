<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Volt;
use App\Models\Lp;

class VoltController extends Controller
{
    /**
     * Return all volts unchanged.
     */
    public function volts()
    {
        return response()->json(Volt::all());
    }
    /**
     * Get vault metrics for frontend
     */
    public function get_volt($network)
    {
        $volt = Volt::where('network', $network)->first();

        if (!$volt) {
            return response()->json([
                'error' => 'Volt not found',
            ], 404);
        }

        return response()->json([
            'totalDeposits'  => $volt->tvl,
            'feesGenerated'  => $volt->fees_generated,
            'total'          => $volt->total,
            'profit'         => $volt->profit,
            'totalWithdrawn' => $volt->total_withdrawn,
            'apy'            => $this->calculateApy($volt),
        ]);
    }

    /**
     * Add liquidity into LP + update the volt accordingly
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

        // Find the volt corresponding to the network
        $volt = Volt::where('network', $network)->first();

        if (!$volt) {
            return response()->json([
                'success' => false,
                'message' => 'Volt does not exist for this network.'
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
         * UPDATE THE VOLT VALUES
         */
        $volt->tvl += $amount;     // Increase total liquidity inside volt
        $volt->total += $amount;   // Increase total deposit tracking
        $volt->save();

        return response()->json([
            'success' => true,
            'message' => 'Liquidity added successfully.',
            'lp' => $lp,
            'volt' => $volt
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
    private function calculateApy($volt)
    {
        if ($volt->tvl <= 0 || $volt->profit <= 0) {
            return 0;
        }

        $daysLive = now()->diffInDays($volt->created_at);

        if ($daysLive < 1) $daysLive = 1;

        return ($volt->profit / $volt->tvl) * (365 / $daysLive) * 100;
    }
}
