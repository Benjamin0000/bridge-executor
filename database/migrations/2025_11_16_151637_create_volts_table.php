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
        Schema::create('volts', function (Blueprint $table) {
            $table->id();
            $table->string('network');
            $table->decimal('tvl', 65, 8)->default(0);
            $table->decimal('fees_generated', 65, 8)->default(0);
            $table->decimal('total', 65, 8)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('volts');
    }
};
