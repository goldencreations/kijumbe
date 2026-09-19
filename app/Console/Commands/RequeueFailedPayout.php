<?php

namespace App\Console\Commands;

use App\Enums\PayoutStatus;
use App\Enums\RoundStatus;
use App\Models\Payout;
use App\Services\AuditService;
use App\Services\Whatsapp\MetaWhatsAppClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RequeueFailedPayout extends Command
{
    protected $signature = 'kijumbe:requeue-failed-payout {payout : Payout ID} {--notify : Notify the recipient to send POKEA again}';

    protected $description = 'Safely requeue a failed payout only when there is proof it was never submitted to the provider.';

    public function handle(AuditService $audit, MetaWhatsAppClient $whatsapp): int
    {
        $payout = DB::transaction(function () use ($audit): ?Payout {
            $payout = Payout::query()
                ->with(['round.cycle.group', 'cycle.group', 'recipient.groupMember.user', 'recipientUser', 'verification'])
                ->lockForUpdate()
                ->find($this->argument('payout'));

            if (! $payout) {
                $this->error('Payout was not found.');

                return null;
            }

            if ($payout->status !== PayoutStatus::Failed) {
                $this->error('Only a failed payout can be requeued.');

                return null;
            }

            if ($payout->provider_reference || $payout->initiated_at || $payout->processed_at) {
                $this->error('Unsafe to requeue: this payout may already have reached the provider. Reconcile it first.');

                return null;
            }

            if (! $payout->isCoordinatorFee() && $payout->round?->status !== RoundStatus::ReadyForPayout) {
                $this->error('The payout round is not ready for payout.');

                return null;
            }

            if ($payout->isCoordinatorFee() && $payout->cycle?->status?->value !== 'completed') {
                $this->error('The coordinator fee payout cycle is not completed.');

                return null;
            }

            $before = $payout->attributesToArray();
            $payout->update([
                'status' => PayoutStatus::Pending,
                'approved_by' => null,
                'approved_at' => null,
                'failure_reason' => null,
                'response_payload' => null,
            ]);
            $payout->verification?->update([
                'status' => 'retry_required',
                'payment_quote_id' => null,
                'payment_quote_name' => null,
                'payment_quote_fee' => null,
                'payment_quote_total' => null,
                'payment_quote_expires_at' => null,
            ]);
            $audit->record(
                'payout.safely_requeued',
                $payout,
                null,
                before: $before,
                after: $payout->fresh()->attributesToArray(),
                metadata: ['reason' => 'No provider reference or initiation timestamp existed.'],
            );

            return $payout->fresh(['round.cycle.group', 'cycle.group', 'recipient.groupMember.user', 'recipientUser']);
        }, 3);

        if (! $payout) {
            return self::FAILURE;
        }

        $recipient = $payout->recipientUser ?: $payout->recipient?->groupMember?->user;
        if ($this->option('notify') && $recipient) {
            $cycle = $payout->cycle ?: $payout->round?->cycle;
            $whatsapp->sendText(
                $recipient,
                'Malipo yako ya TZS '.number_format((int) $payout->amount).' katika '.($cycle?->group?->name ?: 'mchezo wako').' yamerudishwa kwenye foleni salama. Tuma '.($payout->isCoordinatorFee() ? 'POKEA ADA' : 'POKEA').' kuanza uthibitisho upya.',
            );
        }

        $this->info("Payout {$payout->id} was safely requeued as pending.".($this->option('notify') ? ' Recipient notified.' : ' Recipient was not notified.'));

        return self::SUCCESS;
    }
}
