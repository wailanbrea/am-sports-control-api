<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('currency_code', 3)->default('DOP');
            $table->string('timezone')->default('America/Santo_Domingo');
            $table->unsignedTinyInteger('week_starts_on')->default(1);
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('company_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['company_id', 'user_id']);
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->decimal('current_balance', 18, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        foreach (['collections', 'advances'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) use ($tableName) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('branch_id')->constrained()->restrictOnDelete();
                $table->decimal('amount', 18, 2);
                $table->date('business_date');
                if ($tableName === 'collections') {
                    $table->string('payment_method');
                } else {
                    $table->string('reason');
                    $table->string('payment_method')->nullable();
                }
                $table->string('reference')->nullable();
                $table->text('notes')->nullable();
                $table->string('status')->default('confirmed');
                $table->uuid('idempotency_key');
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamp('reversed_at')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'idempotency_key']);
            });
        }

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('entry_type');
            $table->decimal('signed_amount', 18, 2);
            $table->decimal('balance_before', 18, 2);
            $table->decimal('balance_after', 18, 2);
            $table->date('business_date');
            $table->text('description');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reversal_of_entry_id')->nullable()->constrained('ledger_entries')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
            $table->unique('reversal_of_entry_id');
            $table->index(['company_id', 'branch_id', 'business_date']);
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('key');
            $table->string('operation');
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'key']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('reason')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('advances');
        Schema::dropIfExists('collections');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('company_user');
        Schema::dropIfExists('companies');
    }
};
