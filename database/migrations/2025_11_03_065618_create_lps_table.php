<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lps', function (Blueprint $table) {
            $table->id();
            $table->string('wallet_address')->unique();
            $table->decimal('amount', 36, 18)->default(0); // stores user's liquidity amount
            $table->decimal('profit', 36, 18)->default(0); // accrued profits
            $table->boolean('active')->default(true); // 1 = active, 0 = inactive
            $table->timestamps();
        });
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lps');
    }
};
