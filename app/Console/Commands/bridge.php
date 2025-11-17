<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Models\Deposit;

class bridge extends Command
{
    protected $signature = 'app:bridge';
    protected $description = 'Execute token bridge operations';

    public function handle()
    {
        $this->info("🔁 Bridge relayer started...");
        
        while (true) {
            // Find pending deposits
            $pending = Deposit::where('status', 'pending')->first();

            if (!$pending) {
                // $this->line("⏳ No pending bridge requests...");
                sleep(2);
                continue;
            }

            $this->info("🚀 Processing deposit nonce: {$pending->nouns}");

            try {
                // Prepare payload for NodeJS execution endpoint
                $payload = [
                    "network"      => $pending->destination_chain,
                    "token"        => $pending->token_to,
                    "amount"       => $pending->amount_out,
                    "nativeAmount" => $pending->dest_native_amt ?? 0,
                    "recipient"    => $pending->to,
                ];

                // 🌐 POST to NodeJS (your bridge executor)
                $response = Http::withOptions([
                    'verify' => false, // allow localhost https
                ])->post(env('NODE_BRIDGE_URL') . "/bridge/execute", $payload);

                $result = $response->json();

                if ($response->successful() && isset($result['txHash'])) {
                    $this->info("✅ Execution complete. TX Hash: {$result['txHash']}"); 
                    // Update database
                    $pending->status = "completed";
                    $pending->release_tx_hash = $result['txHash'];
                    $pending->save();
                } else {
                    $this->error("❌ NodeJS execution failed");
                    $this->error(json_encode($result));
                }

            } catch (\Exception $e) {
                $this->error("❌ Error: " . $e->getMessage());
            }

            sleep(2);
        }
    }
}
