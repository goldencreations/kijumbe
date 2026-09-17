<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table): void {
            $table->string('group_type', 16)->nullable()->after('name')->index();
            $table->string('leader_name')->nullable()->after('created_by_wa_id');
            $table->unsignedBigInteger('amount_per_day')->nullable()->after('contribution_amount');
            $table->string('payment_frequency', 24)->nullable()->after('period_days')->index();
            $table->unsignedBigInteger('kijumbe_fee')->nullable()->after('platform_fee_amount');
            $table->unsignedBigInteger('penalty_amount')->nullable()->after('kijumbe_fee');
            $table->unsignedSmallInteger('penalty_timing_hours')->nullable()->after('penalty_amount');
            $table->string('group_code', 100)->nullable()->unique()->after('penalty_timing_hours');
        });

        DB::table('groups')->orderBy('created_at')->orderBy('id')->each(function (object $group): void {
            $base = Str::upper(Str::slug((string) $group->name));
            $base = $base !== '' ? $base : 'KIKUNDI';

            do {
                $code = $base.'-'.random_int(1000, 9999);
            } while (DB::table('groups')->where('group_code', $code)->exists());

            $frequency = match ((int) ($group->period_days ?? 0)) {
                1 => 'daily',
                7 => 'weekly',
                30 => 'monthly',
                default => null,
            };

            DB::table('groups')->where('id', $group->id)->update([
                'leader_name' => $group->created_by_phone ?: 'Kijumbe',
                'amount_per_day' => $group->contribution_amount,
                'payment_frequency' => $frequency,
                'kijumbe_fee' => $group->platform_fee_amount,
                'penalty_amount' => 0,
                'penalty_timing_hours' => $group->grace_period_hours,
                'group_code' => $code,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table): void {
            $table->dropUnique(['group_code']);
            $table->dropColumn([
                'group_type', 'leader_name', 'amount_per_day', 'payment_frequency',
                'kijumbe_fee', 'penalty_amount', 'penalty_timing_hours', 'group_code',
            ]);
        });
    }
};
