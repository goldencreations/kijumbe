<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table): void {
            $table->index(['name', 'status'], 'groups_name_status_index');
            $table->index(['created_by', 'status'], 'groups_creator_status_index');
            $table->index(['group_type', 'status'], 'groups_type_status_index');
            $table->index('cycle_starts_at', 'groups_cycle_starts_at_index');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->index(['name', 'is_active'], 'users_name_active_index');
            $table->index('phone', 'users_phone_index');
        });

        Schema::table('rounds', function (Blueprint $table): void {
            $table->index(['due_at', 'status'], 'rounds_due_status_index');
            $table->index('recipient_cycle_member_id', 'rounds_recipient_index');
        });

        Schema::table('member_payment_obligations', function (Blueprint $table): void {
            $table->index(['status', 'due_at'], 'obligations_status_due_index');
        });
    }

    public function down(): void
    {
        Schema::table('member_payment_obligations', function (Blueprint $table): void {
            $table->dropIndex('obligations_status_due_index');
        });
        Schema::table('rounds', function (Blueprint $table): void {
            $table->dropIndex('rounds_due_status_index');
            $table->dropIndex('rounds_recipient_index');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_name_active_index');
            $table->dropIndex('users_phone_index');
        });
        Schema::table('groups', function (Blueprint $table): void {
            $table->dropIndex('groups_name_status_index');
            $table->dropIndex('groups_creator_status_index');
            $table->dropIndex('groups_type_status_index');
            $table->dropIndex('groups_cycle_starts_at_index');
        });
    }
};
