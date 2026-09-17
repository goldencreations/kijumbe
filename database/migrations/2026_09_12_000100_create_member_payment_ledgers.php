<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_payment_obligations', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('group_id')->constrained('groups')->cascadeOnDelete();
            $table->foreignId('group_member_slot_id')->constrained('group_member_slots')->cascadeOnDelete();
            $table->unsignedInteger('round_number');
            $table->timestamp('due_at');
            $table->unsignedBigInteger('principal_amount');
            $table->unsignedBigInteger('kijumbe_fee_amount')->default(0);
            $table->unsignedBigInteger('penalty_amount')->default(0);
            $table->unsignedBigInteger('paid_amount')->default(0);
            $table->string('status', 24)->default('pending')->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['group_member_slot_id', 'round_number'], 'mpo_slot_round_unique');
            $table->index(['group_id', 'due_at', 'status']);
        });

        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_attempt_id')->constrained('payment_attempts')->cascadeOnDelete();
            $table->foreignId('member_payment_obligation_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('principal_amount')->default(0);
            $table->unsignedBigInteger('kijumbe_fee_amount')->default(0);
            $table->unsignedBigInteger('penalty_amount')->default(0);
            $table->timestamps();

            $table->unique(['payment_attempt_id', 'member_payment_obligation_id'], 'payment_allocations_unique');
        });

        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropForeign(['contribution_id']);
            $table->foreignId('contribution_id')->nullable()->change();
            $table->string('payment_scope', 24)->default('contribution')->after('contribution_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropColumn('payment_scope');
            $table->foreignId('contribution_id')->nullable(false)->change();
            $table->foreign('contribution_id')->references('id')->on('contributions')->restrictOnDelete();
        });

        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('member_payment_obligations');
    }
};
