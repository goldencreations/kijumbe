<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_inbound_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('wa_id', 32)->unique();
            $table->string('phone_number_id', 64)->nullable()->index();
            $table->string('last_message_id', 191)->nullable()->index();
            $table->string('last_message_type', 32)->nullable();
            $table->timestamp('first_seen_at')->nullable()->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->unsignedInteger('message_count')->default(0);
            $table->json('last_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_inbound_contacts');
    }
};
