<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Whatsapp\MetaWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MetaWhatsappWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $token = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));

        abort_unless($mode === 'subscribe' && is_string($token) && hash_equals((string) config('services.whatsapp.verify_token'), $token), 403);

        return response((string) $challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request, MetaWebhookService $service): JsonResponse
    {
        $service->verifySignature($request->getContent(), $request->header('X-Hub-Signature-256'));
        $count = $service->receive($request->json()->all());

        return response()->json(['received' => true, 'events_stored' => $count]);
    }
}
