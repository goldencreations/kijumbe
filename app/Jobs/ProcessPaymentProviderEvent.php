<?php

namespace App\Jobs;

use App\Models\PaymentProviderEvent;
use App\Services\Payments\PaymentProviderEventProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessPaymentProviderEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 120, 300];

    public function __construct(public readonly int $eventId) {}

    public function handle(PaymentProviderEventProcessor $processor): void
    {
        $processor->process(PaymentProviderEvent::findOrFail($this->eventId));
    }
}
