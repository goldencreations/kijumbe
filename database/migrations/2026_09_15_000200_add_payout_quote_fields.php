<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payout_verifications', function (Blueprint $table): void {
            $table->string('payment_quote_id')->nullable()->after('payment_phone');
            $table->string('payment_quote_name')->nullable()->after('payment_quote_id');
            $table->unsignedBigInteger('payment_quote_fee')->nullable()->after('payment_quote_name');
            $table->unsignedBigInteger('payment_quote_total')->nullable()->after('payment_quote_fee');
            $table->timestamp('payment_quote_expires_at')->nullable()->after('payment_quote_total');
        });
    }

    public function down(): void
    {
        Schema::table('payout_verifications', function (Blueprint $table): void {
            $table->dropColumn([
                'payment_quote_id', 'payment_quote_name', 'payment_quote_fee',
                'payment_quote_total', 'payment_quote_expires_at',
            ]);
        });
    }
};
