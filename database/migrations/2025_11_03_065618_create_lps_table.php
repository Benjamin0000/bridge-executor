<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lps', function (Blueprint $table) {
            $table->id();
            $table->string('network');
            $table->string('wallet_address')->unique();
            $table->decimal('amount', 36, 8)->default(0);
            $table->decimal('profit', 36, 8)->default(0);
            $table->decimal('total_profit', 36, 8)->default(0);
            $table->decimal('total_withdrawn', 36, 8)->default(0);
            $table->boolean('active')->default(true);
            $table->json('hashes')->nullable(); 
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lps');
    }
};
