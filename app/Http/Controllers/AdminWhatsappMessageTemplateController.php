<?php

namespace App\Http\Controllers;

use App\Models\WhatsappMessageTemplate;
use App\Services\AuditService;
use App\Services\Whatsapp\WhatsappMessageTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminWhatsappMessageTemplateController extends Controller
{
    public function index(WhatsappMessageTemplateService $templates): View
    {
        return view('admin.whatsapp-templates', [
            'page' => 'whatsapp',
            'title' => 'Message templates',
            'templates' => $templates->all(),
        ]);
    }

    public function update(Request $request, string $key, WhatsappMessageTemplateService $templates, AuditService $audit): JsonResponse
    {
        abort_unless(array_key_exists($key, $templates->definitions()), 404);
        $definition = $templates->definition($key);
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        $unknown = $templates->unknownVariables($key, $data['body']);
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'body' => 'Unknown variable(s): '.implode(', ', array_map(fn (string $name): string => '{'.$name.'}', $unknown)).'.',
            ]);
        }

        $template = WhatsappMessageTemplate::query()->firstOrNew(['key' => $key]);
        $before = $template->exists ? $template->attributesToArray() : ['body' => $definition['body']];
        $template->fill(['body' => $data['body'], 'updated_by' => $request->user()->id])->save();
        $audit->record('whatsapp_template.updated', $template, $request->user(), $before, $template->fresh()->attributesToArray());

        return response()->json(['data' => ['key' => $key]]);
    }

    public function destroy(Request $request, string $key, WhatsappMessageTemplateService $templates, AuditService $audit): JsonResponse
    {
        abort_unless(array_key_exists($key, $templates->definitions()), 404);
        $definition = $templates->definition($key);
        $template = WhatsappMessageTemplate::query()->where('key', $key)->first();
        if ($template) {
            $audit->record('whatsapp_template.reset', $template, $request->user(), $template->attributesToArray(), ['body' => $definition['body']]);
            $template->delete();
        }

        return response()->json(['data' => ['key' => $key]]);
    }
}
