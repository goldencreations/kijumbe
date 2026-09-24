<?php

namespace App\Services\Whatsapp;

use App\Models\WhatsappMessageTemplate;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class WhatsappMessageTemplateService
{
    /** @var array<string, string> */
    private array $resolvedBodies = [];

    /** @var array<string, array<string, mixed>|null> */
    private array $resolvedInteractions = [];

    /** @return array<string, array{category: string, name: string, description: string, body: string, variables: array<int, string>}> */
    public function definitions(): array
    {
        return config('whatsapp_messages', []);
    }

    /** @return Collection<int, array<string, mixed>> */
    public function all(): Collection
    {
        $overrides = WhatsappMessageTemplate::query()
            ->with('editor:id,name')
            ->whereIn('key', array_keys($this->definitions()))
            ->get()
            ->keyBy('key');

        return collect($this->definitions())->map(function (array $definition, string $key) use ($overrides): array {
            $override = $overrides->get($key);

            return [
                'key' => $key,
                ...$definition,
                'body' => $override?->body ?? $definition['body'],
                'interaction' => $override?->interaction ?? ($definition['interaction'] ?? ['type' => 'none', 'options' => []]),
                'is_custom' => $override !== null,
                'updated_at' => $override?->updated_at,
                'updated_by' => $override?->editor?->name,
            ];
        })->values();
    }

    /** @param array<string, scalar|null> $variables */
    public function interaction(string $key, array $variables = []): ?array
    {
        $definition = $this->definition($key);
        if (! array_key_exists($key, $this->resolvedInteractions)) {
            $stored = WhatsappMessageTemplate::query()->where('key', $key)->value('interaction');
            $this->resolvedInteractions[$key] = $stored === null
                ? ($definition['interaction'] ?? null)
                : (is_array($stored) ? $stored : json_decode($stored, true, 512, JSON_THROW_ON_ERROR));
        }

        $interaction = $this->resolvedInteractions[$key];
        if (! is_array($interaction) || ($interaction['type'] ?? 'none') === 'none') {
            return null;
        }

        return $this->replaceVariables($interaction, $variables);
    }

    /** @param array<string, scalar|null> $variables */
    public function render(string $key, array $variables): string
    {
        $definition = $this->definition($key);
        $body = $this->resolvedBodies[$key] ??= WhatsappMessageTemplate::query()->where('key', $key)->value('body') ?? $definition['body'];
        $replacements = [];

        foreach ($definition['variables'] as $variable) {
            $replacements['{'.$variable.'}'] = (string) ($variables[$variable] ?? '');
        }

        $rendered = strtr($body, $replacements);
        $rendered = preg_replace("/\n{3,}/", "\n\n", $rendered) ?? $rendered;

        return trim($rendered);
    }

    /** @return array{name: string, description: string, body: string, variables: array<int, string>} */
    public function definition(string $key): array
    {
        $definition = $this->definitions()[$key] ?? null;
        if (! is_array($definition)) {
            throw new InvalidArgumentException('Unknown WhatsApp message template.');
        }

        return $definition;
    }

    /** @return array<int, string> */
    public function unknownVariables(string $key, string $body): array
    {
        preg_match_all('/\{([a-z0-9_]+)\}/i', $body, $matches);

        return array_values(array_diff(array_unique($matches[1] ?? []), $this->definition($key)['variables']));
    }

    /** @return array<int, string> */
    public function missingVariables(string $key, string $body): array
    {
        preg_match_all('/\{([a-z0-9_]+)\}/i', $body, $matches);
        $present = array_unique($matches[1] ?? []);

        return array_values(array_diff($this->definition($key)['variables'], $present));
    }

    /** @param array<string, mixed> $value @param array<string, scalar|null> $variables */
    private function replaceVariables(array $value, array $variables): array
    {
        $replacements = collect($variables)->mapWithKeys(
            fn ($replacement, string $name): array => ['{'.$name.'}' => (string) ($replacement ?? '')],
        )->all();

        array_walk_recursive($value, function (&$item) use ($replacements): void {
            if (is_string($item)) {
                $item = strtr($item, $replacements);
            }
        });

        return $value;
    }
}
