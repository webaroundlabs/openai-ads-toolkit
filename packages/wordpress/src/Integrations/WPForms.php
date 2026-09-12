<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Integrations;

// Loaded by WordPress through Composer's autoloader. A direct request for this
// file would parse a class whose parents are not loaded, and a fatal error
// discloses the installation path.
defined('ABSPATH') || exit;

/**
 * WPForms.
 *
 * The success boundary is `wpforms_process_complete`, which runs after
 * validation, after any spam check, and after the entry has been stored. It does
 * not run for a submission that failed.
 *
 * Reported server-side only. See `GravityForms` for why that is correct rather
 * than a shortcut, and for how to add the browser half if a site wants it.
 *
 * `$fields` arrives already flattened by WPForms into
 * `[ id => ['name' => …, 'value' => …, 'type' => …] ]`, with a `name` field also
 * carrying `first` and `last`. That is the one field type read specially -
 * splitting a combined name on a space is wrong often enough to hurt matching
 * rather than help it.
 */
final class WPForms implements Integration
{
    public function __construct(
        private readonly LeadRecorder $recorder,
    ) {
    }

    public function id(): string
    {
        return 'wpforms';
    }

    public function label(): string
    {
        return 'WPForms';
    }

    public function isAvailable(): bool
    {
        return function_exists('wpforms');
    }

    public function register(): void
    {
        \add_action('wpforms_process_complete', [$this, 'onComplete'], 10, 3);
    }

    /**
     * @param array<int|string, mixed> $fields
     * @param array<string, mixed>     $entry
     * @param array<string, mixed>     $formData
     */
    public function onComplete(array $fields, array $entry, array $formData): void
    {
        $payload = $this->recorder->record(
            LeadRecorder::identityFromFields($this->fields($fields)),
            (string) ($formData['id'] ?? ''),
            (string) ($formData['settings']['form_title'] ?? ''),
            $this->id(),
        );

        if ($payload !== null) {
            \do_action('openai_ads_form_recorded', $payload, $this->id());
        }
    }

    /**
     * @param array<int|string, mixed> $fields
     *
     * @return list<array{type: string, name: string, value: string}>
     */
    private function fields(array $fields): array
    {
        $extracted = [];

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $type = isset($field['type']) && is_string($field['type']) ? $field['type'] : '';
            $label = isset($field['name']) && is_string($field['name']) ? $field['name'] : '';

            if ($type === 'name') {
                foreach (['first' => 'first name', 'last' => 'last name'] as $part => $name) {
                    $value = $field[$part] ?? null;

                    if (is_string($value) && trim($value) !== '') {
                        $extracted[] = ['type' => 'text', 'name' => $name, 'value' => $value];
                    }
                }

                continue;
            }

            $value = $field['value'] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $extracted[] = ['type' => $type, 'name' => $label, 'value' => $value];
            }
        }

        return $extracted;
    }
}
