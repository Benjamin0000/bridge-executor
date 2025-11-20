<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Models\Valt;

class UpdateLiquidityBalance extends Command
{
    protected $signature = 'app:update-liquidity-balance';
    protected $description = 'Fetch and update the liquidity (TVL) for each network';

    public function handle()
    {
        $this->info("🔄 Updating network liquidity...");

        $nodeEndpoint = env('NODE_BRIDGE_URL') . "/balance";

        $evmAddress    = env('EVM_OPERATOR_ADDRESS');
        $hederaAddress = env('HEDERA_OPERATOR_ADDRESS');

        $NETWORKS = config('networks');

        foreach ($NETWORKS as $networkName => $data) {

            // Determine correct address
            $wallet = $networkName === 'hedera' ? $hederaAddress : $evmAddress;

            if (!$wallet) {
                $this->error("⚠ Missing address for {$networkName}");
                continue;
            }

            $this->line("🔍 Fetching balance for {$networkName} using {$wallet}...");

            try {
                // Call NodeJS balance endpoint
                $response = Http::get($nodeEndpoint, [
                    'network' => $networkName,
                    'address' => $wallet
                ]);

                if (!$response->successful()) {
                    $this->error("❌ NodeJS request failed for {$networkName}");
                    continue;
                }

                $balance = $response->json('balance') ?? 0;

                // Find or create Valt entry
                $valt = Valt::firstOrCreate(
                    ['network_slug' => $networkName],
                    [
                        'network' => $networkName,
                        'tvl'     => 0
                    ]
                );

                // Update the TVL value
                $valt->tvl = $balance;
                $valt->save();

                $this->info("✅ Updated {$networkName}: TVL = {$balance}");

            } catch (\Exception $e) {
                $this->error("❌ Error fetching {$networkName}: " . $e->getMessage());
            }
        }

        $this->info("🎉 Liquidity update completed.");
    }
}
