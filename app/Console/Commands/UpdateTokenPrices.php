<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\TokenPrice; 

class UpdateTokenPrices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tokens:update-prices';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update token prices from CoinGecko';

    protected $coingecko;

    public function __construct(TokenPrice $coingecko)
    {
        parent::__construct();
        $this->coingecko = $coingecko;
    }

    public function handle()
    {
        $tokens = ['ethereum', 'binancecoin', 'hedera-hashgraph', 'calaxy', 'saucerswap']; 
        $prices = $this->coingecko->getPrices($tokens);

        foreach ($prices as $token => $data) {
            TokenPrice::updateOrCreate(
                ['token' => $token],
                ['price' => $data['usd']]
            );
        }

        $this->info('Token prices updated successfully.');
    }
}