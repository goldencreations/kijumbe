<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cycles', function (Blueprint $table): void {
            $table->timestamp('completion_notified_at')->nullable()->after('completed_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('cycles', function (Blueprint $table): void {
            $table->dropColumn('completion_notified_at');
        });
    }
};
