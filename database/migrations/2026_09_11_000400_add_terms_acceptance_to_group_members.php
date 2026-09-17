<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('group_members', 'terms_accepted_at')) {
            Schema::table('group_members', function (Blueprint $table): void {
                $table->timestamp('terms_accepted_at')->nullable()->after('majina_count');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('group_members', 'terms_accepted_at')) {
            Schema::table('group_members', fn (Blueprint $table) => $table->dropColumn('terms_accepted_at'));
        }
    }
};
