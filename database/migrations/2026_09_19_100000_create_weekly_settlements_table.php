<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->date('week_start');
            $table->date('week_end');
            $table->decimal('sales_amount', 18, 2)->default(0);
            $table->decimal('prizes_amount', 18, 2)->default(0);
            $table->decimal('cash_delivered_amount', 18, 2)->default(0);
            $table->decimal('weekly_balance', 18, 2);
            $table->decimal('balance_before', 18, 2);
            $table->decimal('balance_after', 18, 2);
            $table->text('notes')->nullable();
            $table->string('status')->default('confirmed');
            $table->uuid('idempotency_key');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'branch_id', 'week_start', 'week_end']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'week_start', 'week_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_settlements');
    }
};
