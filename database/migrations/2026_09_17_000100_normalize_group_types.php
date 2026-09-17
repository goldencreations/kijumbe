<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('groups', 'group_type')) {
            return;
        }

        DB::table('groups')
            ->whereIn('group_type', ['product', 'good', 'goods', 'bidhaa'])
            ->update(['group_type' => 'product']);

        DB::table('groups')
            ->where(function ($query): void {
                $query->whereNull('group_type')->orWhere('group_type', '');
            })
            ->update(['group_type' => 'money']);

        DB::table('groups')
            ->whereNotNull('group_type')
            ->whereNotIn('group_type', ['money', 'product'])
            ->update(['group_type' => 'money']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('groups', 'group_type')) {
            DB::table('groups')->where('group_type', 'product')->update(['group_type' => 'good']);
        }
    }
};
