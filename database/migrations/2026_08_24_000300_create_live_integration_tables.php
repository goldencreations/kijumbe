<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('whatsapp_wa_id', 32)->nullable()->unique()->after('phone');
            $table->timestamp('whatsapp_opted_in_at')->nullable()->after('is_active');
            $table->timestamp('whatsapp_opted_out_at')->nullable()->after('whatsapp_opted_in_at');
        });

        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 16)->index();
            $table->string('meta_message_id', 191)->nullable()->unique();
            $table->string('wa_id', 32)->nullable()->index();
            $table->string('phone_number_id', 64)->nullable()->index();
            $table->string('message_type', 32);
            $table->string('template_name', 191)->nullable();
            $table->string('status', 32)->default('received')->index();
            $table->json('payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('whatsapp_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 191)->unique();
            $table->string('event_type', 32)->index();
            $table->string('meta_message_id', 191)->nullable()->index();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->text('processing_error')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contribution_id')->constrained()->restrictOnDelete();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider', 32)->index();
            $table->string('order_reference', 100)->unique();
            $table->string('idempotency_key', 191)->unique();
            $table->string('phone_e164', 32);
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3)->default('TZS');
            $table->string('status', 32)->default('created')->index();
            $table->string('provider_reference', 191)->nullable()->index();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('last_queried_at')->nullable();
            $table->timestamps();

            $table->index(['contribution_id', 'status']);
        });

        Schema::create('payment_provider_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32)->index();
            $table->string('event_key', 191)->unique();
            $table->string('event_name', 64)->index();
            $table->string('order_reference', 100)->nullable()->index();
            $table->string('provider_reference', 191)->nullable()->index();
            $table->boolean('verified')->default(false);
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->text('processing_error')->nullable();
            $table->timestamps();
        });

        Schema::table('payouts', function (Blueprint $table) {
            $table->foreignId('requested_by')->nullable()->after('processed_by')->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->after('requested_by')->constrained('users')->nullOnDelete();
            $table->string('destination_phone', 32)->nullable()->after('payment_reference');
            $table->string('provider', 32)->nullable()->index()->after('destination_phone');
            $table->string('order_reference', 100)->nullable()->unique()->after('provider');
            $table->string('provider_reference', 191)->nullable()->index()->after('order_reference');
            $table->string('idempotency_key', 191)->nullable()->unique()->after('provider_reference');
            $table->timestamp('approved_at')->nullable()->after('processed_at');
            $table->timestamp('initiated_at')->nullable()->after('approved_at');
            $table->json('response_payload')->nullable()->after('notes');
            $table->text('failure_reason')->nullable()->after('response_payload');
        });
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropForeign(['requested_by']);
            $table->dropForeign(['approved_by']);
            $table->dropColumn([
                'requested_by', 'approved_by', 'destination_phone', 'provider', 'order_reference',
                'provider_reference', 'idempotency_key', 'approved_at', 'initiated_at',
                'response_payload', 'failure_reason',
            ]);
        });

        Schema::dropIfExists('payment_provider_events');
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('whatsapp_webhook_events');
        Schema::dropIfExists('whatsapp_messages');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_wa_id', 'whatsapp_opted_in_at', 'whatsapp_opted_out_at']);
        });
    }
};
