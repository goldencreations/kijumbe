<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->string('phone_e164', 32)->nullable()->change();
            $table->string('method', 24)->default('mobile_money')->after('provider');
            $table->string('provider_payment_id', 191)->nullable()->unique()->after('provider_reference');
            $table->unsignedBigInteger('fee')->nullable()->after('amount');
            $table->unsignedBigInteger('net')->nullable()->after('fee');
            $table->timestamp('expires_at')->nullable()->after('last_queried_at');
            $table->timestamp('available_at')->nullable()->after('expires_at');
            $table->json('metadata')->nullable()->after('response_payload');
        });
    }

    public function down(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->dropUnique(['provider_payment_id']);
            $table->dropColumn([
                'method', 'provider_payment_id', 'fee', 'net', 'expires_at', 'available_at', 'metadata',
            ]);
            $table->string('phone_e164', 32)->nullable(false)->change();
        });
    }
};
