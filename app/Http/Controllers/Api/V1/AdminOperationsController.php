<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Contribution;
use App\Models\Cycle;
use App\Models\PaymentAttempt;
use App\Models\PaymentProviderEvent;
use App\Models\Payout;
use App\Models\WhatsappInboundContact;
use App\Models\WhatsappMessage;
use App\Models\WhatsappWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminOperationsController extends Controller
{
    public function cycles(Request $request): JsonResponse
    {
        $cycles = Cycle::query()
            ->with('group:id,name,group_code,status')
            ->withCount(['members', 'rounds'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('group_id'), fn ($query) => $query->where('group_id', $request->input('group_id')))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = '%'.$request->string('search')->toString().'%';
                $query->where(fn ($query) => $query
                    ->where('name', 'like', $search)
                    ->orWhereHas('group', fn ($query) => $query->where('name', 'like', $search)->orWhere('group_code', 'like', $search)));
            })
            ->when($request->filled('starts_from'), fn ($query) => $query->where('starts_at', '>=', $request->date('starts_from')))
            ->when($request->filled('starts_to'), fn ($query) => $query->where('starts_at', '<=', $request->date('starts_to')))
            ->latest('starts_at')
            ->paginate($this->perPage($request));

        return response()->json($cycles);
    }

    public function cycle(Cycle $cycle): JsonResponse
    {
        return response()->json(['data' => $cycle->load([
            'group',
            'members.groupMember.user',
            'rounds.recipient.groupMember.user',
            'rounds.payout',
            'rounds.contributions.cycleMember.groupMember.user',
        ])]);
    }

    public function contributions(Request $request): JsonResponse
    {
        $contributions = Contribution::query()
            ->with(['round.cycle.group', 'cycleMember.groupMember.user:id,name,email,phone,whatsapp_wa_id', 'paymentAttempts'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('cycle_id'), fn ($query) => $query->whereHas('round', fn ($query) => $query->where('cycle_id', $request->input('cycle_id'))))
            ->when($request->filled('group_id'), fn ($query) => $query->whereHas('round.cycle', fn ($query) => $query->where('group_id', $request->input('group_id'))))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = '%'.$request->string('search')->toString().'%';
                $query->where(function ($query) use ($search): void {
                    $query->whereHas('cycleMember.groupMember.user', fn ($query) => $query->where('name', 'like', $search)->orWhere('phone', 'like', $search))
                        ->orWhereHas('round.cycle.group', fn ($query) => $query->where('name', 'like', $search)->orWhere('group_code', 'like', $search));
                });
            })
            ->when($request->filled('due_from'), fn ($query) => $query->where('due_at', '>=', $request->date('due_from')))
            ->when($request->filled('due_to'), fn ($query) => $query->where('due_at', '<=', $request->date('due_to')))
            ->latest('due_at')
            ->paginate($this->perPage($request));

        return response()->json($contributions);
    }

    public function contribution(Contribution $contribution): JsonResponse
    {
        return response()->json(['data' => $contribution->load([
            'round.cycle.group',
            'cycleMember.groupMember.user',
            'paymentAttempts.allocations.obligation',
        ])]);
    }

    public function payments(Request $request): JsonResponse
    {
        $payments = PaymentAttempt::query()
            ->with(['initiator:id,name,email,phone', 'contribution.round.cycle.group', 'contribution.cycleMember.groupMember.user', 'allocations.obligation.group', 'allocations.obligation.slot.groupMember.user'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('payment_scope'), fn ($query) => $query->where('payment_scope', $request->string('payment_scope')->toString()))
            ->when($request->filled('provider'), fn ($query) => $query->where('provider', $request->string('provider')->toString()))
            ->when($request->filled('group_id'), function ($query) use ($request): void {
                $groupId = $request->string('group_id')->toString();
                $query->where(function ($query) use ($groupId): void {
                    $query->whereHas('contribution.round.cycle', fn ($query) => $query->where('group_id', $groupId))
                        ->orWhereHas('allocations.obligation', fn ($query) => $query->where('group_id', $groupId));
                });
            })
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = '%'.$request->string('search')->toString().'%';
                $query->where(fn ($query) => $query
                    ->where('order_reference', 'like', $search)
                    ->orWhere('provider_reference', 'like', $search)
                    ->orWhere('phone_e164', 'like', $search)
                    ->orWhereHas('contribution.cycleMember.groupMember.user', fn ($query) => $query->where('name', 'like', $search)->orWhere('phone', 'like', $search))
                    ->orWhereHas('contribution.round.cycle.group', fn ($query) => $query->where('name', 'like', $search)->orWhere('group_code', 'like', $search))
                    ->orWhereHas('allocations.obligation.slot.groupMember.user', fn ($query) => $query->where('name', 'like', $search)->orWhere('phone', 'like', $search))
                    ->orWhereHas('allocations.obligation.group', fn ($query) => $query->where('name', 'like', $search)->orWhere('group_code', 'like', $search)));
            })
            ->when($request->filled('date_from'), fn ($query) => $query->whereRaw('DATE(COALESCE(completed_at, failed_at, initiated_at, created_at)) >= ?', [$request->input('date_from')]))
            ->when($request->filled('date_to'), fn ($query) => $query->whereRaw('DATE(COALESCE(completed_at, failed_at, initiated_at, created_at)) <= ?', [$request->input('date_to')]))
            ->latest('id')
            ->paginate($this->perPage($request));

        return response()->json($payments);
    }

    public function payment(PaymentAttempt $paymentAttempt): JsonResponse
    {
        return response()->json(['data' => $paymentAttempt->load([
            'initiator:id,name,email,phone',
            'contribution.round.cycle.group',
            'contribution.cycleMember.groupMember.user',
            'allocations.obligation',
        ])]);
    }

    public function payouts(Request $request): JsonResponse
    {
        $payouts = Payout::query()
            ->with(['round.cycle.group', 'cycle.group', 'recipient.groupMember.user', 'recipientUser', 'requester:id,name,email', 'approver:id,name,email', 'verification'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('group_id'), fn ($query) => $query->where(function ($query) use ($request): void {
                $groupId = $request->input('group_id');
                $query->whereHas('round.cycle', fn ($query) => $query->where('group_id', $groupId))
                    ->orWhereHas('cycle', fn ($query) => $query->where('group_id', $groupId));
            }))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = '%'.$request->string('search')->toString().'%';
                $query->where(fn ($query) => $query
                    ->where('payment_reference', 'like', $search)
                    ->orWhere('destination_phone', 'like', $search)
                    ->orWhereHas('recipientUser', fn ($query) => $query->where('name', 'like', $search)->orWhere('phone', 'like', $search))
                    ->orWhereHas('recipient.groupMember.user', fn ($query) => $query->where('name', 'like', $search)->orWhere('phone', 'like', $search))
                    ->orWhereHas('round.cycle.group', fn ($query) => $query->where('name', 'like', $search)->orWhere('group_code', 'like', $search))
                    ->orWhereHas('cycle.group', fn ($query) => $query->where('name', 'like', $search)->orWhere('group_code', 'like', $search)));
            })
            ->when($request->filled('from'), fn ($query) => $query->where('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->where('created_at', '<=', $request->date('to')))
            ->latest('id')
            ->paginate($this->perPage($request));

        return response()->json($payouts);
    }

    public function payout(Payout $payout): JsonResponse
    {
        return response()->json(['data' => $payout->load([
            'round.cycle.group',
            'cycle.group',
            'recipient.groupMember.user',
            'recipientUser',
            'requester:id,name,email',
            'approver:id,name,email',
            'processor:id,name,email',
            'verification',
        ])]);
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $logs = AuditLog::query()
            ->with('actor:id,name,email,phone')
            ->when($request->filled('action'), fn ($query) => $query->where('action', $request->string('action')->toString()))
            ->when($request->filled('actor_id'), fn ($query) => $query->where('actor_id', $request->integer('actor_id')))
            ->when($request->filled('search'), fn ($query) => $query->where('action', 'like', '%'.$request->string('search')->toString().'%'))
            ->when($request->filled('from'), fn ($query) => $query->where('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->where('created_at', '<=', $request->date('to')))
            ->latest('id')
            ->paginate($this->perPage($request));

        return response()->json($logs);
    }

    public function whatsappMessages(Request $request): JsonResponse
    {
        $messages = WhatsappMessage::query()
            ->with('user:id,name,email,phone')
            ->when($request->filled('direction'), fn ($query) => $query->where('direction', $request->string('direction')->toString()))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('phone'), fn ($query) => $query->where('wa_id', 'like', '%'.$request->string('phone')->toString().'%'))
            ->when($request->filled('search'), fn ($query) => $query->where(function ($query) use ($request): void {
                $search = '%'.$request->string('search')->toString().'%';
                $query->where('meta_message_id', 'like', $search)->orWhere('template_name', 'like', $search)->orWhere('message_type', 'like', $search);
            }))
            ->latest('id')
            ->paginate($this->perPage($request));

        return response()->json($messages);
    }

    public function whatsappWebhookEvents(Request $request): JsonResponse
    {
        return response()->json(WhatsappWebhookEvent::query()
            ->when($request->filled('event_type'), fn ($query) => $query->where('event_type', $request->string('event_type')->toString()))
            ->when($request->boolean('unprocessed'), fn ($query) => $query->whereNull('processed_at'))
            ->latest('id')
            ->paginate($this->perPage($request)));
    }

    public function paymentProviderEvents(Request $request): JsonResponse
    {
        return response()->json(PaymentProviderEvent::query()
            ->when($request->filled('provider'), fn ($query) => $query->where('provider', $request->string('provider')->toString()))
            ->when($request->has('verified'), fn ($query) => $query->where('verified', $request->boolean('verified')))
            ->when($request->boolean('unprocessed'), fn ($query) => $query->whereNull('processed_at'))
            ->latest('id')
            ->paginate($this->perPage($request)));
    }

    public function whatsappContacts(Request $request): JsonResponse
    {
        return response()->json(WhatsappInboundContact::query()
            ->when($request->filled('search'), fn ($query) => $query->where('wa_id', 'like', '%'.$request->string('search')->toString().'%'))
            ->orderByDesc('last_seen_at')
            ->paginate($this->perPage($request)));
    }

    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 25), 1), 100);
    }
}
