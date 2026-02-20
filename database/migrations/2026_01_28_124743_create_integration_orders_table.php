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
        Schema::create('integration_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shopify_order_id')->unique();
            $table->string('turum_reservation_id')->nullable()->index();
            $table->string('status')->default('new'); // new, reserved, fulfilled, failed
            $table->string('tracking_number')->nullable();
            $table->text('tracking_url')->nullable();
            $table->string('carrier')->nullable();
            $table->json('payload')->nullable(); // Original webhook payload for debugging
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('integration_orders');
    }
};
