<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TokenPrice;
use Illuminate\Http\JsonResponse;

class TokenPriceController extends Controller
{
    // Map frontend symbols to the CoinGecko IDs stored in DB
    private const SYMBOL_TO_ID = [
        'ETH' => 'ethereum',
        'BNB' => 'binancecoin',
        'HBAR' => 'hedera-hashgraph',
        'CLXY' => 'calaxy',
        'SAUCE' => 'saucerswap',
        'DAI' => 'dai',
        'USDCt' => 'usdc', // assuming 'usdc' is stored in DB
        'USDC' => 'usdc', // assuming 'usdc' is stored in DB
    ];

    /**
     * Return token prices from database with frontend-friendly symbols
     */
    public function get_price(): JsonResponse
    {
        $safeZeroPrices = array_fill_keys(array_keys(self::SYMBOL_TO_ID), 0);
        try {
            // Get all prices from DB
            $pricesFromDb = TokenPrice::whereIn('token', array_values(self::SYMBOL_TO_ID))
                ->pluck('price', 'token') // ['ethereum' => 1234.56, ...]
                ->toArray();

            $prices = [];
            foreach (self::SYMBOL_TO_ID as $symbol => $id) {
                if (isset($pricesFromDb[$id])) {
                    $prices[$symbol] = $pricesFromDb[$id];
                } else {
                    // fallback values for missing tokens
                    $prices[$symbol] = match($symbol) {
                        'SAUCE' => 0.00029578,
                        'DAI' => 2.18,
                        'USDCt' => 1.00,
                        default => 1.00,
                    };
                }
            }

            return response()->json($prices, 200);

        } catch (\Exception $e) {
            \Log::error('Failed to fetch token prices: '.$e->getMessage());

            return response()->json([
                'message' => 'Internal server error while fetching prices.',
                'prices' => $safeZeroPrices
            ], 500);
        }
    }
}
