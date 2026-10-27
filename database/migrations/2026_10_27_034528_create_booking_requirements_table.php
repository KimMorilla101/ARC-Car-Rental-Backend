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
        Schema::create('booking_requirements', function (Blueprint $table) {
            $table->id();
            // Restrict: a booking with documents cannot be erased by accident; see the retention policy.
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            // drivers_license, valid_id, proof_of_billing or down_payment.
            $table->string('type', 30);
            $table->string('status', 30)->default('missing');
            // Path on the PRIVATE disk (storage/app/private); never publicly reachable.
            $table->string('file_path')->nullable();
            $table->dateTime('uploaded_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['booking_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_requirements');
    }
};
