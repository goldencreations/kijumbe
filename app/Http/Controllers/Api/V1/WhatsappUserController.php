<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConfigureWhatsappUserRequest;
use App\Http\Requests\SendWhatsappTemplateRequest;
use App\Models\User;
use App\Services\PhoneNumber;
use App\Services\Whatsapp\MetaWhatsAppClient;
use Illuminate\Http\JsonResponse;

class WhatsappUserController extends Controller
{
    public function configure(
        ConfigureWhatsappUserRequest $request,
        User $user,
        PhoneNumber $phones,
    ): JsonResponse {
        abort_unless($request->user()->isAdmin(), 403, 'Only administrators can configure WhatsApp access.');

        $waId = $phones->toTanzanianWaId($request->validated('phone'));
        $optedIn = $request->boolean('opted_in');
        $user->update([
            'phone' => $waId,
            'whatsapp_wa_id' => $waId,
            'whatsapp_opted_in_at' => $optedIn ? now() : null,
            'whatsapp_opted_out_at' => $optedIn ? null : now(),
        ]);

        return response()->json(['data' => $user->fresh()]);
    }

    public function sendTemplate(
        SendWhatsappTemplateRequest $request,
        MetaWhatsAppClient $whatsapp,
    ): JsonResponse {
        abort_unless($request->user()->isAdmin(), 403, 'Only administrators can send office templates.');
        $user = User::findOrFail($request->integer('user_id'));
        $message = $whatsapp->sendTemplate(
            $user,
            $request->validated('template_name'),
            $request->validated('language'),
            $request->validated('components', []),
        );

        return response()->json(['data' => $message], 201);
    }
}
