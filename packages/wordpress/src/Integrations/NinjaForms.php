<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Integrations;

// Loaded by WordPress through Composer's autoloader. A direct request for this
// file would parse a class whose parents are not loaded, and a fatal error
// discloses the installation path.
defined('ABSPATH') || exit;

/**
 * Ninja Forms.
 *
 * The success boundary is `ninja_forms_after_submission`, which runs once the
 * submission has been processed and stored.
 *
 * Reported server-side only. See `GravityForms` for why that is correct rather
 * than a shortcut, and for how to add the browser half if a site wants it.
 *
 * `$formData['fields']` is keyed by field id, each entry carrying `key`, `value`
 * and `type`. Ninja Forms names its identity types `email`, `phone`, `firstname`
 * and `lastname`, so both the type and the key are offered to the shared
 * extractor - the type catches the email and phone, the key catches the names.
 */
final class NinjaForms implements Integration
{
    public function __construct(
        private readonly LeadRecorder $recorder,
    ) {
    }

    public function id(): string
    {
        return 'ninja_forms';
    }

    public function label(): string
    {
        return 'Ninja Forms';
    }

    public function isAvailable(): bool
    {
        return class_exists('Ninja_Forms');
    }

    public function register(): void
    {
        \add_action('ninja_forms_after_submission', [$this, 'onSubmission']);
    }

    /**
     * @param array<string, mixed> $formData
     */
    public function onSubmission(array $formData): void
    {
        $payload = $this->recorder->record(
            LeadRecorder::identityFromFields($this->fields($formData)),
            (string) ($formData['form_id'] ?? ''),
            (string) ($formData['settings']['title'] ?? ''),
            $this->id(),
        );

        if ($payload !== null) {
            \do_action('openai_ads_form_recorded', $payload, $this->id());
        }
    }

    /**
     * @param array<string, mixed> $formData
     *
     * @return list<array{type: string, name: string, value: string}>
     */
    private function fields(array $formData): array
    {
        if (!isset($formData['fields']) || !is_array($formData['fields'])) {
            return [];
        }

        $fields = [];

        foreach ($formData['fields'] as $field) {
            if (!is_array($field)) {
                continue;
            }

            $value = $field['value'] ?? null;

            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            $type = isset($field['type']) && is_string($field['type']) ? $field['type'] : '';
            $key = isset($field['key']) && is_string($field['key']) ? $field['key'] : '';

            // Ninja Forms uses "firstname" and "lastname" as TYPES, where the
            // shared extractor looks for them in the field's name. Mapping them
            // across here keeps that extractor free of per-plugin spellings.
            $name = match ($type) {
                'firstname' => 'first name',
                'lastname' => 'last name',
                default => $key,
            };

            $fields[] = ['type' => $type, 'name' => $name, 'value' => $value];
        }

        return $fields;
    }
}
