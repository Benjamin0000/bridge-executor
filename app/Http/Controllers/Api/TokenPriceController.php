<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TokenPrice;
use Illuminate\Http\JsonResponse;

class TokenPriceController extends Controller
{
    private const SYMBOL_TO_ID = [
        'ETH' => 'ethereum',
        'WETH' => 'ethereum',
        'BNB' => 'binancecoin',
        'HBAR' => 'hedera-hashgraph',
        'PACK' => 'hashpack',
        'SAUCE' => 'saucerswap',
        'USDC' => 'usd-coin',
        'USDT' => 'tether',
        'WBTC' => 'bitcoin', 
        'BTCB' => 'bitcoin'
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
                $prices[$symbol] = $pricesFromDb[$id];
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
