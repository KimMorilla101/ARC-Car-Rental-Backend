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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            // Set right after insert from the id, e.g. BK-2026-0007.
            $table->string('reference', 30)->nullable()->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            // The specific physical car reserved for this booking.
            $table->foreignId('vehicle_unit_id')->constrained()->restrictOnDelete();

            // Stored in UTC as plain datetimes (no MySQL session-timezone conversion).
            $table->dateTime('pickup_at');
            $table->dateTime('return_at');
            $table->unsignedInteger('rental_days');

            $table->string('delivery_method', 20);
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('delivery_zone_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('delivery_address')->nullable();
            $table->string('destination');

            $table->string('status', 30)->default('pending')->index();
            $table->string('payment_method', 20);
            // Snapshot of the payment option at booking time.
            $table->boolean('payment_requires_proof');
            $table->string('payment_status', 30)->default('awaiting_payment');

            // Price snapshot computed by the server when the booking was made.
            $table->decimal('daily_rate', 10, 2);
            $table->decimal('monthly_rate', 10, 2);
            $table->decimal('rental_fee', 10, 2);
            $table->decimal('delivery_fee', 10, 2);
            $table->decimal('car_wash_fee', 10, 2);
            $table->decimal('late_return_fee', 10, 2)->default(0);
            $table->decimal('extension_fee', 10, 2)->default(0);
            $table->decimal('down_payment', 10, 2);
            $table->decimal('total', 10, 2);

            $table->string('agreement_version', 40);
            $table->dateTime('agreement_accepted_at');

            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('activated_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('status_changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamps();

            // Overlap checks look up a unit's bookings by time window.
            $table->index(['vehicle_unit_id', 'pickup_at', 'return_at']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
