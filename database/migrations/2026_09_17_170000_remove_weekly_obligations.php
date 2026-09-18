<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('advances', 'weekly_period_id')) {
            Schema::table('advances', function (Blueprint $table) {
                $table->dropConstrainedForeignId('weekly_period_id');
            });
        }

        Schema::dropIfExists('weekly_obligations');
        Schema::dropIfExists('weekly_batches');
        Schema::dropIfExists('weekly_periods');
    }

    public function down(): void
    {
        // The empty weekly feature is intentionally removed without restoration.
    }
};
