<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_message_templates', function (Blueprint $table): void {
            $table->json('interaction')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_message_templates', function (Blueprint $table): void {
            $table->dropColumn('interaction');
        });
    }
};
