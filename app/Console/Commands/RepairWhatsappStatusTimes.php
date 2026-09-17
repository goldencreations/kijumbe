<?php

namespace App\Console\Commands;

use App\Models\WhatsappMessage;
use App\Models\WhatsappWebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RepairWhatsappStatusTimes extends Command
{
    protected $signature = 'kijumbe:repair-whatsapp-status-times {--message= : Repair one Meta message ID only}';

    protected $description = 'Rebuild WhatsApp status timestamps from Meta Unix timestamps in the application timezone.';

    public function handle(): int
    {
        $repaired = 0;
        $missing = 0;

        WhatsappWebhookEvent::query()
            ->where('event_type', 'status')
            ->when($this->option('message'), fn ($query, $id) => $query->where('meta_message_id', $id))
            ->orderBy('id')
            ->chunkById(250, function ($events) use (&$repaired, &$missing): void {
                foreach ($events as $event) {
                    $status = (array) data_get($event->payload, 'status', []);
                    $messageId = (string) ($status['id'] ?? $event->meta_message_id ?? '');
                    $timestamp = $status['timestamp'] ?? null;

                    if ($messageId === '' || ! is_numeric($timestamp)) {
                        continue;
                    }

                    $message = WhatsappMessage::query()->where('meta_message_id', $messageId)->first();
                    if (! $message) {
                        $missing++;

                        continue;
                    }

                    $column = match ((string) ($status['status'] ?? '')) {
                        'sent' => 'sent_at',
                        'delivered' => 'delivered_at',
                        'read' => 'read_at',
                        'failed' => 'failed_at',
                        default => null,
                    };

                    if (! $column) {
                        continue;
                    }

                    $message->forceFill([
                        $column => Carbon::createFromTimestamp((int) $timestamp, config('app.timezone')),
                    ])->save();
                    $repaired++;
                }
            });

        $this->info("Repaired {$repaired} WhatsApp status timestamp(s); {$missing} event(s) had no matching message.");

        return self::SUCCESS;
    }
}
