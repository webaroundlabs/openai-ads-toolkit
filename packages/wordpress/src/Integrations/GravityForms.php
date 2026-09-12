<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Integrations;

// Loaded by WordPress through Composer's autoloader. A direct request for this
// file would parse a class whose parents are not loaded, and a fatal error
// discloses the installation path.
defined('ABSPATH') || exit;

/**
 * Gravity Forms.
 *
 * The success boundary is `gform_after_submission`, which runs once the entry
 * has been saved - after validation, after any payment add-on has settled, and
 * not at all for a submission that failed. `$entry` is the saved record and
 * `$form` describes the fields it came from.
 *
 * Reported server-side only, and that is correct rather than a shortcut: nothing
 * fires a browser Pixel event for this conversion, so there is no second report
 * to deduplicate against. A site that wants the browser half as well can listen
 * for `openai_ads_form_recorded` and print it with `openai_ads_pixel_event()`,
 * using the event id handed over - the same one the server used.
 *
 * Field values live in `$entry` keyed by field id as a string. A `name` field is
 * the exception: it stores its parts under sub-ids, `3` for the first name and
 * `6` for the last, which is why it is read separately rather than through the
 * generic path.
 */
final class GravityForms implements Integration
{
    public function __construct(
        private readonly LeadRecorder $recorder,
    ) {
    }

    public function id(): string
    {
        return 'gravity_forms';
    }

    public function label(): string
    {
        return 'Gravity Forms';
    }

    public function isAvailable(): bool
    {
        return class_exists('GFAPI');
    }

    public function register(): void
    {
        \add_action('gform_after_submission', [$this, 'onSubmission'], 10, 2);
    }

    /**
     * @param array<int|string, mixed> $entry Field values, keyed by field id. PHP
     *                                        turns the numeric ones into ints.
     * @param array<string, mixed>     $form
     */
    public function onSubmission(array $entry, array $form): void
    {
        $payload = $this->recorder->record(
            LeadRecorder::identityFromFields($this->fields($entry, $form)),
            (string) ($form['id'] ?? ''),
            (string) ($form['title'] ?? ''),
            $this->id(),
        );

        if ($payload !== null) {
            \do_action('openai_ads_form_recorded', $payload, $this->id());
        }
    }

    /**
     * @param array<int|string, mixed> $entry
     * @param array<string, mixed>     $form
     *
     * @return list<array{type: string, name: string, value: string}>
     */
    private function fields(array $entry, array $form): array
    {
        if (!isset($form['fields']) || !is_array($form['fields'])) {
            return [];
        }

        $fields = [];

        foreach ($form['fields'] as $field) {
            if (!is_object($field) || !isset($field->id)) {
                continue;
            }

            $id = (string) $field->id;
            $type = isset($field->type) ? (string) $field->type : '';
            $label = isset($field->label) ? (string) $field->label : '';

            if ($type === 'name') {
                // Gravity Forms stores a name field's parts under sub-ids: .3 is
                // the first name and .6 the last. The parent id holds nothing.
                foreach (['3' => 'first name', '6' => 'last name'] as $part => $name) {
                    $value = $entry[$id . '.' . $part] ?? null;

                    if (is_string($value) && trim($value) !== '') {
                        $fields[] = ['type' => 'text', 'name' => $name, 'value' => $value];
                    }
                }

                continue;
            }

            $value = $entry[$id] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $fields[] = ['type' => $type, 'name' => $label, 'value' => $value];
            }
        }

        return $fields;
    }
}
