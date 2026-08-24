<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 24)->default('active')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role', 24)->default('member')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['group_id', 'user_id']);
        });

        Schema::create('cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedBigInteger('contribution_amount');
            $table->char('currency', 3)->default('TZS');
            $table->unsignedSmallInteger('period_days')->default(7);
            $table->unsignedSmallInteger('grace_period_hours')->default(24);
            $table->timestamp('starts_at');
            $table->unsignedSmallInteger('member_count')->default(0);
            $table->string('status', 32)->default('draft')->index();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('cycle_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_member_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('payout_position');
            $table->string('status', 24)->default('active')->index();
            $table->timestamps();

            $table->unique(['cycle_id', 'group_member_id']);
            $table->unique(['cycle_id', 'payout_position']);
        });

        Schema::create('rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->foreignId('recipient_cycle_member_id')->constrained('cycle_members')->restrictOnDelete();
            $table->timestamp('due_at');
            $table->timestamp('grace_ends_at');
            $table->unsignedBigInteger('required_amount');
            $table->unsignedBigInteger('collected_amount')->default(0);
            $table->unsignedBigInteger('payout_amount')->default(0);
            $table->string('status', 32)->default('scheduled')->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['cycle_id', 'number']);
            $table->index(['cycle_id', 'status']);
        });

        Schema::create('contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cycle_member_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('status', 24)->default('pending')->index();
            $table->timestamp('due_at');
            $table->timestamp('grace_ends_at');
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference', 100)->nullable()->index();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['round_id', 'cycle_member_id']);
            $table->index(['round_id', 'status']);
        });

        Schema::create('round_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32)->index();
            $table->json('payload')->nullable();
            $table->text('reason');
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at');
            $table->timestamps();
        });

        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('recipient_cycle_member_id')->constrained('cycle_members')->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('status', 24)->default('pending')->index();
            $table->string('payment_reference', 100)->nullable()->index();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 80)->index();
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('round_decisions');
        Schema::dropIfExists('contributions');
        Schema::dropIfExists('rounds');
        Schema::dropIfExists('cycle_members');
        Schema::dropIfExists('cycles');
        Schema::dropIfExists('group_members');
        Schema::dropIfExists('groups');
    }
};
