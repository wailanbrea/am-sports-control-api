<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('owner_name')->nullable()->after('name');
            $table->string('owner_phone')->nullable()->after('owner_name');
            $table->string('owner_whatsapp')->nullable()->after('owner_phone');
            $table->string('owner_email')->nullable()->after('owner_whatsapp');
            $table->string('owner_document')->nullable()->after('owner_email');
            $table->string('manager_name')->nullable()->after('owner_document');
            $table->string('manager_phone')->nullable()->after('manager_name');
            $table->string('manager_whatsapp')->nullable()->after('manager_phone');
            $table->string('address')->nullable()->after('manager_whatsapp');
            $table->string('sector')->nullable()->after('address');
            $table->string('city')->nullable()->after('sector');
            $table->string('province')->nullable()->after('city');
            $table->string('location_reference')->nullable()->after('province');
            $table->decimal('latitude', 10, 7)->nullable()->after('location_reference');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('collection_day')->default('monday')->after('longitude');
        });

        Schema::create('manual_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->string('classification'); // positive, negative, zero
            $table->date('business_date');
            $table->text('notes')->nullable();
            $table->string('status')->default('confirmed');
            $table->uuid('idempotency_key');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'branch_id', 'business_date']);
        });

        Schema::create('money_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('manual_result_id')->nullable()->constrained('manual_results')->nullOnDelete();
            $table->decimal('suggested_amount', 18, 2)->default(0);
            $table->decimal('delivered_amount', 18, 2);
            $table->date('business_date');
            $table->string('reason');
            $table->text('notes')->nullable();
            $table->string('status')->default('confirmed');
            $table->uuid('idempotency_key');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'branch_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('money_deliveries');
        Schema::dropIfExists('manual_results');

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn([
                'owner_name',
                'owner_phone',
                'owner_whatsapp',
                'owner_email',
                'owner_document',
                'manager_name',
                'manager_phone',
                'manager_whatsapp',
                'address',
                'sector',
                'city',
                'province',
                'location_reference',
                'latitude',
                'longitude',
                'collection_day',
            ]);
        });
    }
};
