<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table): void {
            $table->string('purpose', 32)->default('member_payout')->after('id')->index();
            $table->foreignId('cycle_id')->nullable()->after('round_id')->constrained('cycles')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->nullable()->after('recipient_cycle_member_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('ready_notified_at')->nullable()->after('initiated_at');
            $table->unique(['cycle_id', 'purpose'], 'payouts_cycle_purpose_unique');
        });

        Schema::table('payouts', function (Blueprint $table): void {
            $table->unsignedBigInteger('round_id')->nullable()->change();
            $table->unsignedBigInteger('recipient_cycle_member_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('payouts')->where('purpose', 'coordinator_fee')->delete();

        Schema::table('payouts', function (Blueprint $table): void {
            $table->dropUnique('payouts_cycle_purpose_unique');
            $table->dropConstrainedForeignId('recipient_user_id');
            $table->dropConstrainedForeignId('cycle_id');
            $table->dropColumn(['purpose', 'ready_notified_at']);
        });

        Schema::table('payouts', function (Blueprint $table): void {
            $table->unsignedBigInteger('round_id')->nullable(false)->change();
            $table->unsignedBigInteger('recipient_cycle_member_id')->nullable(false)->change();
        });
    }
};
