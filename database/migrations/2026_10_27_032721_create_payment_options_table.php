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
        Schema::create('payment_options', function (Blueprint $table) {
            $table->id();
            // One option per method: online, bank_transfer or cash.
            $table->string('method', 20)->unique();
            $table->string('label', 60);
            $table->string('description', 500)->nullable();
            $table->boolean('requires_proof')->default(false);
            // Where renters send money: [{provider, account_name, account_number}]. Empty for cash.
            $table->json('accounts')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_options');
    }
};
