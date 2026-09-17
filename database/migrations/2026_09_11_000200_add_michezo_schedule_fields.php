<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('groups', 'leader_phone')) {
            Schema::table('groups', function (Blueprint $table): void {
                $table->string('leader_phone', 32)->nullable()->after('leader_name');
            });
        }

        if (! Schema::hasColumn('groups', 'contribution_interval_days')) {
            Schema::table('groups', function (Blueprint $table): void {
                $table->unsignedSmallInteger('contribution_interval_days')->nullable()->after('amount_per_day');
            });
        }

        if (! Schema::hasColumn('groups', 'payout_interval_days')) {
            Schema::table('groups', function (Blueprint $table): void {
                $table->unsignedSmallInteger('payout_interval_days')->nullable()->after('payment_frequency');
            });
        }

        DB::table('groups')->orderBy('id')->each(function (object $group): void {
            DB::table('groups')->where('id', $group->id)->update([
                'leader_phone' => $group->created_by_phone,
                'contribution_interval_days' => $group->period_days,
                'payout_interval_days' => $group->period_days,
            ]);
        });
    }

    public function down(): void
    {
        $columns = array_values(array_filter([
            Schema::hasColumn('groups', 'leader_phone') ? 'leader_phone' : null,
            Schema::hasColumn('groups', 'contribution_interval_days') ? 'contribution_interval_days' : null,
            Schema::hasColumn('groups', 'payout_interval_days') ? 'payout_interval_days' : null,
        ]));

        if ($columns !== []) {
            Schema::table('groups', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
