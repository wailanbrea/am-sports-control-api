<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_boxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->decimal('balance', 18, 2)->default(0);
            $table->string('currency_code', 10)->default('USD');
            $table->boolean('is_default')->default(false);
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->foreignId('cash_box_id')->nullable()->after('company_id')->constrained('cash_boxes')->nullOnDelete();
        });

        // Crear automáticamente la caja principal para empresas existentes
        $companies = DB::table('companies')->get();
        foreach ($companies as $company) {
            $boxId = DB::table('cash_boxes')->insertGetId([
                'company_id' => $company->id,
                'name' => 'Caja Chica (Oficina)',
                'description' => 'Caja chica principal de la oficina',
                'balance' => $company->cash_balance ?? 0.00,
                'currency_code' => $company->currency_code ?: 'USD',
                'is_default' => true,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('cash_movements')
                ->where('company_id', $company->id)
                ->whereNull('cash_box_id')
                ->update(['cash_box_id' => $boxId]);
        }
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropForeign(['cash_box_id']);
            $table->dropColumn('cash_box_id');
        });

        Schema::dropIfExists('cash_boxes');
    }
};
