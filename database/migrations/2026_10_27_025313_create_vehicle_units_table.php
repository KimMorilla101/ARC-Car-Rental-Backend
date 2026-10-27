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
        Schema::create('vehicle_units', function (Blueprint $table) {
            $table->id();
            // Restrict: a vehicle with physical units can only be soft-deleted, never erased.
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->string('label', 100);
            $table->string('plate_number', 20)->unique();
            $table->string('status', 20)->default('available');
            $table->timestamps();

            $table->index(['vehicle_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_units');
    }
};
