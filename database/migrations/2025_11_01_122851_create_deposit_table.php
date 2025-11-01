<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposits', function (Blueprint $table) {
            $table->id();
            // Nouns ID from smart contract event
            $table->string('nouns')->unique();
            // Original depositor on the source chain
            $table->string('depositor')->index();

            // The token being sent (on source chain)
            $table->string('token_from')->nullable()->index(); // address(0) for ETH

            // The token expected on the destination chain
            $table->string('token_to')->nullable()->index();
            $table->integer('token_decimal'); 
            // Pool address where the funds were sent
            $table->string('pool_address')->nullable()->index();

            // The destination wallet on the other chain
            $table->string('to')->index();

            // Amount in base units (no decimals applied yet)
            $table->decimal('amount', 65, 0);
            

            // Original timestamp from blockchain (block.timestamp)
            $table->unsignedBigInteger('timestamp')->index();

            // Chain metadata for tracking multi-chain setups
            $table->string('source_chain')->nullable();
            $table->string('destination_chain')->nullable();

            // Status tracking for backend processing
            $table->enum('status', [
                'pending',      // seen on source chain, waiting to process
                'processing',   // currently being bridged to dest chain
                'completed',    // successfully bridged
                'failed',       // error while processing
            ])->default('pending')->index();

            // Hashes for traceability
            $table->string('tx_hash')->nullable()->index();          // source chain tx hash
            $table->string('release_tx_hash')->nullable()->index();  // dest chain execution tx hash
            // Optional metadata
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposits');
    }
};
