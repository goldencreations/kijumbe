<?php

namespace App\Jobs;

use App\Models\WhatsappWebhookEvent;
use App\Services\Whatsapp\WhatsappWebhookEventProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessWhatsappWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 120, 300];

    public function __construct(public readonly int $eventId) {}

    public function handle(WhatsappWebhookEventProcessor $processor): void
    {
        $processor->process(WhatsappWebhookEvent::findOrFail($this->eventId));
    }
}
