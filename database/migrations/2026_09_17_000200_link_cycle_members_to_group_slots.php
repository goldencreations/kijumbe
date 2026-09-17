<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cycle_members', function (Blueprint $table): void {
            $table->dropUnique(['cycle_id', 'group_member_id']);
            $table->foreignId('group_member_slot_id')
                ->nullable()
                ->after('group_member_id')
                ->constrained('group_member_slots')
                ->restrictOnDelete();
            $table->unique(['cycle_id', 'group_member_slot_id'], 'cycle_members_cycle_slot_unique');
        });

        DB::table('cycle_members')->orderBy('id')->each(function (object $member): void {
            $slotId = DB::table('group_member_slots')
                ->where('group_member_id', $member->group_member_id)
                ->orderBy('payout_position')
                ->orderBy('id')
                ->value('id');

            if ($slotId !== null) {
                DB::table('cycle_members')->where('id', $member->id)->update([
                    'group_member_slot_id' => $slotId,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('cycle_members', function (Blueprint $table): void {
            $table->dropUnique('cycle_members_cycle_slot_unique');
            $table->dropConstrainedForeignId('group_member_slot_id');
            $table->unique(['cycle_id', 'group_member_id']);
        });
    }
};
