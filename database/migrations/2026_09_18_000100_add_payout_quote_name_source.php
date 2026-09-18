<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payout_verifications', function (Blueprint $table): void {
            $table->string('payment_quote_name_source', 24)->nullable()->after('payment_quote_name');
        });
    }

    public function down(): void
    {
        Schema::table('payout_verifications', function (Blueprint $table): void {
            $table->dropColumn('payment_quote_name_source');
        });
    }
};
