<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Integrations;

/**
 * Fluent Forms.
 *
 * The success boundary is `fluentform/submission_inserted`, which runs once the
 * entry exists - after validation and after any spam check.
 *
 * Reported server-side only. See `GravityForms` for why that is correct rather
 * than a shortcut, and for how to add the browser half if a site wants it.
 *
 * Fluent Forms hands over the submitted values keyed by the field's own name
 * rather than by a numeric id, and has no type information at this point. So
 * identity is read by name here instead of by type: `email` for the address,
 * `phone`, and the composite `names` field, which is an array of its parts.
 * Anything else is offered to the shared extractor, which only claims a value
 * when a field is explicitly named for a first or last name.
 */
final class FluentForms implements Integration
{
    public function __construct(
        private readonly LeadRecorder $recorder,
    ) {
    }

    public function id(): string
    {
        return 'fluent_forms';
    }

    public function label(): string
    {
        return 'Fluent Forms';
    }

    public function isAvailable(): bool
    {
        return defined('FLUENTFORM');
    }

    public function register(): void
    {
        \add_action('fluentform/submission_inserted', [$this, 'onSubmission'], 10, 3);
    }

    /**
     * @param int|string           $entryId
     * @param array<string, mixed> $formData
     * @param object|null          $form
     */
    public function onSubmission($entryId, array $formData, $form = null): void
    {
        $payload = $this->recorder->record(
            LeadRecorder::identityFromFields($this->fields($formData)),
            (string) ($form->id ?? ''),
            (string) ($form->title ?? ''),
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
        $fields = [];

        foreach ($formData as $key => $value) {
            $name = strtolower((string) $key);

            // The composite name field arrives as its parts, which is better
            // than a single string: no guessing where a first name ends.
            if (is_array($value)) {
                foreach (['first_name' => 'first name', 'last_name' => 'last name'] as $part => $label) {
                    $part = $value[$part] ?? null;

                    if (is_string($part) && trim($part) !== '') {
                        $fields[] = ['type' => 'text', 'name' => $label, 'value' => $part];
                    }
                }

                continue;
            }

            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            // No type information is available here, so the field's own name is
            // the only signal. Fluent Forms names these two consistently.
            $type = match (true) {
                str_contains($name, 'email') => 'email',
                str_contains($name, 'phone') => 'phone',
                default => 'text',
            };

            $fields[] = ['type' => $type, 'name' => $name, 'value' => $value];
        }

        return $fields;
    }
}
