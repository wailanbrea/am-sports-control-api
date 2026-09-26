<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->decimal('commission_rate', 5, 2)->default(0)->after('current_balance');
        });

        Schema::table('money_deliveries', function (Blueprint $table) {
            $table->decimal('gross_amount', 18, 2)->default(0)->after('suggested_amount');
            $table->decimal('commission_rate', 5, 2)->default(0)->after('gross_amount');
            $table->decimal('commission_amount', 18, 2)->default(0)->after('commission_rate');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['commission_rate']);
        });

        Schema::table('money_deliveries', function (Blueprint $table) {
            $table->dropColumn(['gross_amount', 'commission_rate', 'commission_amount']);
        });
    }
};
