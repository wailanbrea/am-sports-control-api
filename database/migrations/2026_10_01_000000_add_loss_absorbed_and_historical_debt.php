<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('weekly_settlements')) {
            Schema::table('weekly_settlements', function (Blueprint $table) {
                if (! Schema::hasColumn('weekly_settlements', 'loss_absorbed_amount')) {
                    $table->decimal('loss_absorbed_amount', 18, 2)->default(0)->after('cash_delivered_amount');
                }
                if (! Schema::hasColumn('weekly_settlements', 'settlement_type')) {
                    $table->string('settlement_type', 30)->default('standard')->after('status');
                }
            });
        }

        if (Schema::hasTable('branches')) {
            Schema::table('branches', function (Blueprint $table) {
                if (! Schema::hasColumn('branches', 'historical_debt')) {
                    $table->decimal('historical_debt', 18, 2)->default(0)->after('current_balance');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('weekly_settlements')) {
            Schema::table('weekly_settlements', function (Blueprint $table) {
                if (Schema::hasColumn('weekly_settlements', 'loss_absorbed_amount')) {
                    $table->dropColumn('loss_absorbed_amount');
                }
                if (Schema::hasColumn('weekly_settlements', 'settlement_type')) {
                    $table->dropColumn('settlement_type');
                }
            });
        }

        if (Schema::hasTable('branches')) {
            Schema::table('branches', function (Blueprint $table) {
                if (Schema::hasColumn('branches', 'historical_debt')) {
                    $table->dropColumn('historical_debt');
                }
            });
        }
    }
};
