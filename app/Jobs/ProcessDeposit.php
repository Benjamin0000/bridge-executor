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
                // Update deposit
                $deposit->status = "completed";
                $deposit->release_tx_hash = $result['txHash'];
                $deposit->save();
            } else {
                throw new Exception("NodeJS execution failed: " . json_encode($result));
            }
        } catch (Exception $e) {
            // Laravel queue will retry automatically if configured
            \Log::error("Deposit {$deposit->id} failed: " . $e->getMessage());
            throw $e; // Rethrow to trigger retry
        }
    }
}
