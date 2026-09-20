<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weekly_settlements', function (Blueprint $table) {
            $table->decimal('commission_rate', 5, 2)->default(0)->after('prizes_amount');
            $table->decimal('commission_amount', 18, 2)->default(0)->after('commission_rate');
        });
    }

    public function down(): void
    {
        Schema::table('weekly_settlements', function (Blueprint $table) {
            $table->dropColumn(['commission_rate', 'commission_amount']);
        });
    }
};
