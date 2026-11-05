<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider-agnostic record of a payment attempt, so a second provider does not
 * need its own table. Stripe history stays in stripe_payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('provider_reference', 191);
            $table->string('status', 20)->default('PENDING');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3);
            $table->jsonb('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_reference']);
            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payments');
    }
};
