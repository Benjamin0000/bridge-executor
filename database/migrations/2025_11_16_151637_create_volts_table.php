<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Volt; 

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('volts', function (Blueprint $table) {
            $table->id();
            $table->string('network');
            $table->string('network_slug');
            $table->decimal('tvl', 65, 8)->default(0);
            $table->decimal('fees_generated', 65, 8)->default(0);
            $table->decimal('total', 65, 8)->default(0);
            $table->decimal('profit', 65, 8)->default(0); 
            $table->decimal('total_withdrawn', 65, 8)->default(0);
            $table->timestamps();
        });

        Volt::create([
            'network'=>'Binance',
            'network_slug'=>'binance'
        ]);

        Volt::create([
            'network'=>'Ethereum', 
            'network_slug'=>'ethereum'
        ]);  

        Volt::create([
            'network'=>'Hedera', 
            'network_slug'=>'hedera'
        ]);  

        Volt::create([
            'network'=>'Arbitrum', 
            'network_slug'=>'arbitrum'
        ]);  

        Volt::create([
            'network'=>'Base', 
            'network_slug'=>'base'
        ]);  

        Volt::create([
            'network'=>'Optimism', 
            'network_slug'=>'optimism'
        ]);  
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('volts');
    }
};
