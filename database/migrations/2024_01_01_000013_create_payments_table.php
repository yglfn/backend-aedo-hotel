<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->onDelete('restrict');
            $table->foreignId('invoice_id')->nullable()->constrained()->onDelete('restrict');
            $table->foreignId('shift_id')->nullable()->constrained()->onDelete('restrict');
            $table->foreignId('created_by')->constrained('users')->onDelete('restrict');
            $table->foreignId('verified_by')->nullable()->constrained('users')->onDelete('restrict');
            $table->string('method'); // cash|transfer|qris|payment_gateway
            $table->string('type'); // dp|final|refund
            $table->string('status')->default('pending'); // pending|verified|failed|expired
            $table->decimal('amount', 12, 2);
            $table->string('reference_number')->nullable();
            $table->string('proof_of_payment')->nullable();
            $table->string('provider')->nullable();
            $table->string('gateway_transaction_id')->nullable();
            $table->string('channel')->nullable(); // qris/va/ewallet
            $table->json('gateway_payload')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'booking_id']);
            $table->index('gateway_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
