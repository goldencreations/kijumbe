<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('payout_pin_hash')->nullable()->after('password');
            $table->timestamp('payout_pin_set_at')->nullable()->after('payout_pin_hash');
        });

        Schema::create('payout_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payout_id')->unique()->constrained('payouts')->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->string('payment_phone', 32);
            $table->string('otp_hash');
            $table->timestamp('otp_expires_at');
            $table->unsignedTinyInteger('otp_attempts')->default(0);
            $table->timestamp('otp_verified_at')->nullable();
            $table->timestamp('pin_verified_at')->nullable();
            $table->string('status', 24)->index();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_verifications');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['payout_pin_hash', 'payout_pin_set_at']);
        });
    }
};
