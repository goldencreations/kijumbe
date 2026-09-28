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
            'page' => 'whatsapp-templates',
            'title' => 'Message templates',
            'templates' => $templates->all(),
        ]);
    }

    public function update(Request $request, string $key, WhatsappMessageTemplateService $templates, AuditService $audit): JsonResponse
    {
        abort_unless(array_key_exists($key, $templates->definitions()), 404);
        $definition = $templates->definition($key);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
            'interaction_type' => ['sometimes', 'string', 'in:none,buttons,list'],
            'interaction_button_text' => ['nullable', 'string', 'max:20'],
            'interaction_options' => ['nullable', 'array', 'max:10'],
            'interaction_options.*.id' => ['required', 'string', 'max:256'],
            'interaction_options.*.title' => ['required', 'string', 'max:24'],
            'interaction_options.*.description' => ['nullable', 'string', 'max:72'],
        ]);
        $unknown = $templates->unknownVariables($key, $data['body']);
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'body' => 'Unknown variable(s): '.implode(', ', array_map(fn (string $name): string => '{'.$name.'}', $unknown)).'.',
            ]);
        }
        $missing = $templates->missingVariables($key, $data['body']);
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'body' => 'Required variable(s) cannot be removed: '.implode(', ', array_map(fn (string $name): string => '{'.$name.'}', $missing)).'.',
            ]);
        }

        $template = WhatsappMessageTemplate::query()->firstOrNew(['key' => $key]);
        $before = $template->exists ? $template->attributesToArray() : ['body' => $definition['body']];
        $attributes = ['body' => $data['body'], 'updated_by' => $request->user()->id];
        if (isset($data['interaction_type'])) {
            $attributes['interaction'] = $this->validatedInteraction($data, $definition);
        }
        $template->fill($attributes)->save();
        $audit->record('whatsapp_template.updated', $template, $request->user(), $before, $template->fresh()->attributesToArray());

        return response()->json(['data' => ['key' => $key]]);
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $definition @return array<string, mixed> */
    private function validatedInteraction(array $data, array $definition): array
    {
        $type = $data['interaction_type'];
        if ($type === 'none') {
            return ['type' => 'none', 'options' => []];
        }
        if (($definition['category'] ?? null) !== 'Command menu') {
            throw ValidationException::withMessages([
                'interaction_type' => 'Buttons and lists can only be used for replies inside an active WhatsApp conversation.',
            ]);
        }
        if (mb_strlen($data['body']) > 1024) {
            throw ValidationException::withMessages(['body' => 'Interactive WhatsApp messages cannot exceed 1,024 characters.']);
        }

        $options = array_map(static fn (array $option): array => array_filter([
            'id' => trim($option['id']),
            'title' => trim($option['title']),
            'description' => trim($option['description'] ?? ''),
        ], static fn (string $value): bool => $value !== ''), $data['interaction_options'] ?? []);
        $maximum = $type === 'buttons' ? 3 : 10;
        if ($options === [] || count($options) > $maximum) {
            throw ValidationException::withMessages([
                'interaction_options' => $type === 'buttons'
                    ? 'Reply buttons require between 1 and 3 options.'
                    : 'A list requires between 1 and 10 options.',
            ]);
        }
        if (collect($options)->contains(fn (array $option): bool => empty($option['id']) || empty($option['title']))) {
            throw ValidationException::withMessages(['interaction_options' => 'Every choice needs a title and an action ID.']);
        }
        if (collect($options)->pluck('id')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['interaction_options' => 'Every action ID must be unique.']);
        }
        if ($type === 'buttons' && collect($options)->contains(fn (array $option): bool => mb_strlen($option['title']) > 20)) {
            throw ValidationException::withMessages(['interaction_options' => 'Reply button titles cannot exceed 20 characters.']);
        }

        $interaction = ['type' => $type, 'options' => $options];
        if ($type === 'list') {
            $buttonText = trim($data['interaction_button_text'] ?? '');
            if ($buttonText === '') {
                throw ValidationException::withMessages(['interaction_button_text' => 'A list button label is required.']);
            }
            $interaction['button_text'] = $buttonText;
        }

        return $interaction;
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
