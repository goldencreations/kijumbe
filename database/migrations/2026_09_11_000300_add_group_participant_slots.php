<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('group_members', 'member_name')) {
            Schema::table('group_members', function (Blueprint $table): void {
                $table->string('member_name')->nullable()->after('user_id');
            });
        }

        if (! Schema::hasColumn('group_members', 'majina_count')) {
            Schema::table('group_members', function (Blueprint $table): void {
                $table->unsignedSmallInteger('majina_count')->default(1)->after('member_name');
            });
        }

        if (! Schema::hasColumn('group_members', 'terms_accepted_at')) {
            Schema::table('group_members', function (Blueprint $table): void {
                $table->timestamp('terms_accepted_at')->nullable()->after('majina_count');
            });
        }

        if (! Schema::hasTable('group_member_slots')) {
            Schema::create('group_member_slots', function (Blueprint $table): void {
                $table->id();
                $table->foreignUuid('group_id')->constrained('groups')->cascadeOnDelete();
                $table->foreignId('group_member_id')->constrained('group_members')->cascadeOnDelete();
                $table->unsignedSmallInteger('slot_number');
                $table->unsignedSmallInteger('payout_position')->nullable();
                $table->string('status', 24)->default('active')->index();
                $table->timestamps();

                $table->unique(['group_member_id', 'slot_number']);
                $table->unique(['group_id', 'payout_position']);
                $table->index(['group_id', 'status']);
            });
        }

        DB::table('group_members')->orderBy('id')->each(function (object $member): void {
            $name = $member->member_name ?: DB::table('users')->where('id', $member->user_id)->value('name');
            DB::table('group_members')->where('id', $member->id)->update([
                'member_name' => $name,
                'majina_count' => max((int) ($member->majina_count ?? 1), 1),
            ]);

            if (! DB::table('group_member_slots')->where('group_member_id', $member->id)->exists()) {
                DB::table('group_member_slots')->insert([
                    'group_id' => $member->group_id,
                    'group_member_id' => $member->id,
                    'slot_number' => 1,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_member_slots');

        $columns = array_values(array_filter([
            Schema::hasColumn('group_members', 'member_name') ? 'member_name' : null,
            Schema::hasColumn('group_members', 'majina_count') ? 'majina_count' : null,
            Schema::hasColumn('group_members', 'terms_accepted_at') ? 'terms_accepted_at' : null,
        ]));

        if ($columns !== []) {
            Schema::table('group_members', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
