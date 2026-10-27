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
        // Evidence of a booking payment. Kept separate from the down-payment requirement, and a new
        // row per upload so earlier (e.g. rejected) proofs remain on record.
        Schema::create('payment_proofs', function (Blueprint $table) {
            $table->id();
            // Restrict: a booking with payment proofs cannot be erased by accident; see the retention policy.
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            // Path on the PRIVATE disk (storage/app/private); never publicly reachable.
            $table->string('file_path');
            $table->string('status', 30)->default('pending_verification');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_proofs');
    }
};
