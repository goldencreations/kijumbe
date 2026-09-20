<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('groups')
            ->where('status', 'active')
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('cycles')
                ->whereColumn('cycles.group_id', 'groups.id'))
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('cycles')
                ->whereColumn('cycles.group_id', 'groups.id')
                ->where('cycles.status', '!=', 'completed'))
            ->update(['status' => 'completed', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('groups')
            ->where('status', 'completed')
            ->update(['status' => 'active', 'updated_at' => now()]);
    }
};
