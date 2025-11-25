<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use App\Models\Deposit;
use Exception;
use App\Models\Lp;
use App\Models\Valt;

class ProcessDeposit implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $deposit;

    /**
     * Create a new job instance.
     *
     * @param Deposit $deposit
     */
    public function __construct(Deposit $deposit)
    {
        $this->deposit = $deposit;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $deposit = $this->deposit;

        // Skip if already processed
        if ($deposit->status !== 'pending') {
            return;
        }

        // Prepare payload for NodeJS endpoint
        $payload = [
            "network"      => $deposit->destination_chain,
            "token"        => $deposit->token_to,
            "amount"       => $deposit->amount_out,
            "nativeAmount" => $deposit->dest_native_amt ?? 0,
            "recipient"    => $deposit->to,
        ];

        try {
            $response = Http::withOptions([
                'verify' => false,
            ])->post(env('NODE_BRIDGE_URL') . "/bridge/execute", $payload);

            $result = $response->json();

            if ($response->successful() && isset($result['txHash'])) {
                $this->info("✅ Execution complete. TX Hash: {$result['txHash']}"); 
                // Update database
                $deposit->status = "completed";
                $deposit->release_tx_hash = $result['txHash'];
                $deposit->save();


                $feePct = (float)get_register('fee_pct') / 100;
                $lpPct = (float)get_register('lp_fee_pct') / 100;
                $adminPct = 1 - $lpPct; 

                $netAmount = $deposit->dest_native_amt;
                // Calculate the fee amount from net_amount
                $feeAmount = ($netAmount * $feePct) / (1 - $feePct);

                // Store admin fee in register
                $adminFee = (float)get_register('total_fee'); 
                $adminFee +=  $feeAmount * $adminPct;

                $activeLps = Lp::where([ ['active', true], ['network', $deposit->destination_chain] ])->get();

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
                $valt = Valt::where('network_slug', $deposit->destination_chain)->first(); 
                if($valt){
                    $valt->fees_generated += $feeAmount;
                    $valt->profit += $feeAmount;
                    $valt->save();
                }
            }
            else {
                throw new Exception("NodeJS execution failed: " . json_encode($result));
            }
        } catch (Exception $e) {
            // Laravel queue will retry automatically if configured
            \Log::error("Deposit {$deposit->id} failed: " . $e->getMessage());
            throw $e; // Rethrow to trigger retry
        }
    }
}
