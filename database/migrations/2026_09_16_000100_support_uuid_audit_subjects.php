<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', fn (Blueprint $table) => $table->string('auditable_id', 36)->change());
    }

    public function down(): void
    {
        // UUID audit references cannot be losslessly converted back to integers.
    }
};
