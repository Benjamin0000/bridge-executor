<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;


class TokenPrice extends Model
{
    protected $fillable = ['token', 'price']; 
    protected $baseUrl = 'https://api.coingecko.com/api/v3';

    /**
     * Get token price in USD
     *
     * @param array $tokens Array of token ids as recognized by CoinGecko (e.g., ['bitcoin', 'ethereum'])
     * @return array
     */
    public function getPrices(array $tokens): array
    {
        $ids = implode(',', $tokens);
        $response = Http::get("{$this->baseUrl}/simple/price", [
            'ids' => $ids,
            'vs_currencies' => 'usd',
        ]);

        if ($response->successful()) {
            return $response->json(); // returns ['bitcoin' => ['usd' => 12345], ...]
        }

        return [];
    }
}
