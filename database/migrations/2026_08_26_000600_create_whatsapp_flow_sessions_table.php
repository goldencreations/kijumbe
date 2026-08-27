<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_flow_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('flow_key', 64)->index();
            $table->string('step_key', 64)->index();
            $table->json('payload')->nullable();
            $table->string('last_message_id', 191)->nullable()->index();
            $table->timestamp('last_interaction_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['user_id', 'flow_key', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_flow_sessions');
    }
};
