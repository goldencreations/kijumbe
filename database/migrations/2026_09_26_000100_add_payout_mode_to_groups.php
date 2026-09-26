<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table): void {
            $table->string('payout_mode', 32)->default('rotating')->after('payout_interval_days')->index();
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table): void {
            $table->dropIndex(['payout_mode']);
            $table->dropColumn('payout_mode');
        });
    }
};
