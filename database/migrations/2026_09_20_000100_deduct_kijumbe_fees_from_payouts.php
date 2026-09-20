<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rounds', function (Blueprint $table): void {
            $table->unsignedBigInteger('gross_payout_amount')->default(0)->after('payout_amount');
            $table->unsignedBigInteger('kijumbe_fee_amount')->default(0)->after('gross_payout_amount');
            $table->unsignedBigInteger('platform_fee_amount')->default(0)->after('kijumbe_fee_amount');
            $table->unsignedBigInteger('coordinator_fee_amount')->default(0)->after('platform_fee_amount');
        });

        Schema::table('payouts', function (Blueprint $table): void {
            $table->unsignedBigInteger('gross_amount')->default(0)->after('amount');
            $table->unsignedBigInteger('kijumbe_fee_amount')->default(0)->after('gross_amount');
            $table->unsignedBigInteger('platform_fee_amount')->default(0)->after('kijumbe_fee_amount');
            $table->unsignedBigInteger('coordinator_fee_amount')->default(0)->after('platform_fee_amount');
        });

        DB::table('rounds')->update(['gross_payout_amount' => DB::raw('payout_amount')]);
        DB::table('payouts')->update(['gross_amount' => DB::raw('amount')]);
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table): void {
            $table->dropColumn([
                'gross_amount',
                'kijumbe_fee_amount',
                'platform_fee_amount',
                'coordinator_fee_amount',
            ]);
        });

        Schema::table('rounds', function (Blueprint $table): void {
            $table->dropColumn([
                'gross_payout_amount',
                'kijumbe_fee_amount',
                'platform_fee_amount',
                'coordinator_fee_amount',
            ]);
        });
    }
};
