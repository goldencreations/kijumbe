<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class IntegrationStatus extends Command
{
    protected $signature = 'kijumbe:integration-status';

    protected $description = 'Check live WhatsApp and payment configuration without printing secrets.';

    public function handle(): int
    {
        $rows = [
            ['Public HTTPS APP_URL', str_starts_with((string) config('app.url'), 'https://')],
            ['WhatsApp live enabled', (bool) config('services.whatsapp.live_enabled')],
            ['WhatsApp WABA ID', filled(config('services.whatsapp.business_account_id'))],
            ['WhatsApp phone number ID', filled(config('services.whatsapp.phone_number_id'))],
            ['WhatsApp access token', filled(config('services.whatsapp.access_token'))],
            ['WhatsApp app secret', filled(config('services.whatsapp.app_secret'))],
            ['WhatsApp verify token', filled(config('services.whatsapp.verify_token'))],
            ['Payments live enabled', (bool) config('services.payments.live_enabled')],
            ['Selcom base URL', filled(config('services.selcom.base_url'))],
            ['Selcom API key', filled(config('services.selcom.api_key'))],
            ['Selcom API secret', filled(config('services.selcom.api_secret'))],
            ['Selcom vendor', filled(config('services.selcom.vendor'))],
            ['Selcom float PIN', filled(config('services.selcom.float_pin'))],
            ['Selcom C2B token', filled(config('services.selcom.c2b_token'))],
        ];

        $this->table(['Check', 'Ready'], array_map(fn (array $row) => [$row[0], $row[1] ? 'YES' : 'NO'], $rows));

        return collect($rows)->every(fn (array $row) => $row[1]) ? self::SUCCESS : self::FAILURE;
    }
}
