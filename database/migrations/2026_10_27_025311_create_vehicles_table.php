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
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('category', 20);
            $table->string('transmission', 20);
            $table->string('fuel', 20);
            $table->unsignedTinyInteger('seats');
            $table->unsignedTinyInteger('doors')->nullable();
            $table->text('description')->nullable();
            $table->json('features')->nullable();
            // Nullable until ARC confirms its prices; a vehicle cannot be activated without all three.
            $table->decimal('hourly_rate', 10, 2)->nullable();
            $table->decimal('daily_rate', 10, 2)->nullable();
            $table->decimal('monthly_rate', 10, 2)->nullable();
            $table->boolean('is_popular')->default(false);
            $table->string('status', 20)->default('draft')->index();
            $table->timestamps();
            // Soft deletes keep the vehicle for future booking history.
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
