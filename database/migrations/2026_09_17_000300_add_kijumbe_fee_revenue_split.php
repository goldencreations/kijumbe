<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table): void {
            $table->unsignedTinyInteger('platform_fee_percent')->default(30)->after('kijumbe_fee');
            $table->unsignedBigInteger('coordinator_fee_amount')->default(0)->after('platform_fee_percent');
        });

        Schema::table('member_payment_obligations', function (Blueprint $table): void {
            $table->unsignedTinyInteger('platform_fee_percent')->default(30)->after('kijumbe_fee_amount');
            $table->unsignedBigInteger('platform_fee_amount')->default(0)->after('platform_fee_percent');
            $table->unsignedBigInteger('coordinator_fee_amount')->default(0)->after('platform_fee_amount');
        });

        Schema::table('payment_allocations', function (Blueprint $table): void {
            $table->unsignedBigInteger('platform_fee_amount')->default(0)->after('kijumbe_fee_amount');
            $table->unsignedBigInteger('coordinator_fee_amount')->default(0)->after('platform_fee_amount');
        });

        $defaultPercent = max(0, min(100, (int) config('kijumbe.platform_fee_percent', 30)));
        $split = static function (int $fee, int $percent): array {
            $platform = intdiv(($fee * $percent) + 50, 100);

            return [$platform, $fee - $platform];
        };

        DB::table('groups')->orderBy('id')->each(function (object $group) use ($defaultPercent, $split): void {
            $fee = max((int) ($group->kijumbe_fee ?? $group->platform_fee_amount ?? 0), 0);
            [$platform, $coordinator] = $split($fee, $defaultPercent);
            DB::table('groups')->where('id', $group->id)->update([
                'kijumbe_fee' => $fee,
                'platform_fee_percent' => $defaultPercent,
                'platform_fee_amount' => $platform,
                'coordinator_fee_amount' => $coordinator,
            ]);
        });

        DB::table('member_payment_obligations')->orderBy('id')->each(function (object $obligation) use ($defaultPercent, $split): void {
            $percent = (int) (DB::table('groups')->where('id', $obligation->group_id)->value('platform_fee_percent') ?? $defaultPercent);
            [$platform, $coordinator] = $split((int) $obligation->kijumbe_fee_amount, $percent);
            DB::table('member_payment_obligations')->where('id', $obligation->id)->update([
                'platform_fee_percent' => $percent,
                'platform_fee_amount' => $platform,
                'coordinator_fee_amount' => $coordinator,
            ]);
        });

        DB::table('payment_allocations')->orderBy('id')->each(function (object $allocation) use ($defaultPercent, $split): void {
            $percent = (int) (DB::table('member_payment_obligations')
                ->where('id', $allocation->member_payment_obligation_id)
                ->value('platform_fee_percent') ?? $defaultPercent);
            [$platform, $coordinator] = $split((int) $allocation->kijumbe_fee_amount, $percent);
            DB::table('payment_allocations')->where('id', $allocation->id)->update([
                'platform_fee_amount' => $platform,
                'coordinator_fee_amount' => $coordinator,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table): void {
            $table->dropColumn(['platform_fee_amount', 'coordinator_fee_amount']);
        });
        Schema::table('member_payment_obligations', function (Blueprint $table): void {
            $table->dropColumn(['platform_fee_percent', 'platform_fee_amount', 'coordinator_fee_amount']);
        });
        Schema::table('groups', function (Blueprint $table): void {
            $table->dropColumn(['platform_fee_percent', 'coordinator_fee_amount']);
        });
    }
};
