<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->foreignId('goods_aggregate_payout_id')
                ->nullable()
                ->after('cycle_id')
                ->constrained('payouts')
                ->nullOnDelete();
            $table->index(['purpose', 'recipient_user_id', 'status'], 'payouts_goods_recipient_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropIndex('payouts_goods_recipient_status_index');
            $table->dropConstrainedForeignId('goods_aggregate_payout_id');
        });
    }
};
