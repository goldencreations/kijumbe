<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->string('created_by_phone', 32)->nullable()->index()->after('created_by');
            $table->string('created_by_wa_id', 32)->nullable()->index()->after('created_by_phone');
            $table->string('language', 12)->default('sw')->after('created_by_wa_id');
            $table->unsignedBigInteger('contribution_amount')->nullable()->after('language');
            $table->char('currency', 3)->default('TZS')->after('contribution_amount');
            $table->unsignedSmallInteger('period_days')->nullable()->after('currency');
            $table->unsignedSmallInteger('member_slots')->nullable()->after('period_days');
            $table->timestamp('cycle_starts_at')->nullable()->after('member_slots');
            $table->string('payout_order', 32)->nullable()->after('cycle_starts_at');
            $table->unsignedSmallInteger('grace_period_hours')->nullable()->after('payout_order');
            $table->unsignedBigInteger('platform_fee_amount')->nullable()->after('grace_period_hours');
            $table->unsignedTinyInteger('resolution_threshold_percent')->nullable()->after('platform_fee_amount');
            $table->json('flow_payload')->nullable()->after('resolution_threshold_percent');

            $table->index(['status', 'language']);
            $table->index(['created_by', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropIndex(['status', 'language']);
            $table->dropIndex(['created_by', 'status']);
            $table->dropColumn([
                'created_by_phone',
                'created_by_wa_id',
                'language',
                'contribution_amount',
                'currency',
                'period_days',
                'member_slots',
                'cycle_starts_at',
                'payout_order',
                'grace_period_hours',
                'platform_fee_amount',
                'resolution_threshold_percent',
                'flow_payload',
            ]);
        });
    }
};
