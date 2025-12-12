<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Models\Deposit;
use App\Models\Lp;
use App\Models\Valt;

class Bridge extends Command
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


                    $feePct = (float)get_register('fee_pct') / 100;
                    $lpPct = (float)get_register('lp_fee_pct') / 100;
                    $adminPct = 1 - $lpPct; 

                    $netAmount = $pending->dest_native_amt;
                    // Calculate the fee amount from net_amount
                    $feeAmount = ($netAmount * $feePct) / (1 - $feePct);

                    // Store admin fee in register
                    $adminFee = (float)get_register('total_fee'); 
                    $adminFee +=  $feeAmount * $adminPct;

                    $activeLps = Lp::where([ ['active', true], ['network', $pending->destination_chain] ])->get();

                    if( !$activeLps->isEmpty() ){
                        // lp_fee_pct% of the total fee will be distributed among active LPs
                        $distributableFee = $feeAmount * $lpPct;
                        // Total active liquidity
                        $totalLiquidity = $activeLps->sum('amount');

                        if ($totalLiquidity > 0) {
                            foreach ($activeLps as $lp) {
                                // proportional profit = (lp.amount / totalLiquidity) * distributableFee
                                $profitShare = ($lp->amount / $totalLiquidity) * $distributableFee;
                                $lp->profit += $profitShare;
                                $lp->save();
                            }
                        }
                    }
                    $valt = Valt::where('network_slug', $pending->destination_chain)->first(); 
                    if($valt){
                        $valt->fees_generated += $feeAmount;
                        $valt->profit += $feeAmount;
                        $valt->save();
                    }
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
