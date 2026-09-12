<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Integrations;

// Loaded by WordPress through Composer's autoloader. A direct request for this
// file would parse a class whose parents are not loaded, and a fatal error
// discloses the installation path.
defined('ABSPATH') || exit;

/**
 * Elementor Pro Forms.
 *
 * The success boundary is `elementor_pro/forms/new_record`, which runs after
 * validation and after the form's own actions have been accepted. Elementor also
 * submits over AJAX, so the event id is added to the response through the form
 * handler's own `add_response_data()` and the bundled script fires the matching
 * Pixel event.
 *
 * Requires Elementor Pro. The free Elementor plugin has no form widget, so the
 * availability check looks for the Pro form module rather than for Elementor.
 */
final class ElementorForms implements Integration
{
    public function __construct(
        private readonly LeadRecorder $recorder,
    ) {
    }

    public function id(): string
    {
        return 'elementor_forms';
    }

    public function label(): string
    {
        return 'Elementor Forms';
    }

    public function isAvailable(): bool
    {
        // Forms are a Pro feature; the free plugin being active is not enough.
        return class_exists('\ElementorPro\Modules\Forms\Classes\Form_Record');
    }

    public function register(): void
    {
        \add_action('elementor_pro/forms/new_record', [$this, 'onNewRecord'], 10, 2);
    }

    /**
     * @param object $record  Form_Record
     * @param object $handler Ajax_Handler
     */
    public function onNewRecord(object $record, object $handler): void
    {
        if (!method_exists($record, 'get')) {
            return;
        }

        /** @var mixed $rawFields */
        $rawFields = $record->get('fields');

        if (!is_array($rawFields)) {
            return;
        }

        $formName = '';

        if (method_exists($record, 'get_form_settings')) {
            $formName = (string) ($record->get_form_settings('form_name') ?? '');
        }

        $formId = '';

        if (method_exists($record, 'get_form_settings')) {
            $formId = (string) ($record->get_form_settings('id') ?? '');
        }

        $payload = $this->recorder->record(
            LeadRecorder::identityFromFields($this->fields($rawFields)),
            $formId !== '' ? $formId : $formName,
            $formName,
            $this->id(),
        );

        if ($payload !== null && method_exists($handler, 'add_response_data')) {
            $handler->add_response_data('openai_ads', $payload);
        }
    }

    /**
     * Elementor hands over fields already typed by the widget, so email and
     * phone are identified by what the editor declared rather than by guessing
     * at field labels.
     *
     * @param array<string, mixed> $rawFields
     *
     * @return list<array{type: string, name: string, value: string}>
     */
    private function fields(array $rawFields): array
    {
        $fields = [];

        foreach ($rawFields as $key => $field) {
            if (!is_array($field)) {
                continue;
            }

            $value = $field['value'] ?? $field['raw_value'] ?? '';

            if (is_array($value)) {
                $value = reset($value);
            }

            if (!is_scalar($value)) {
                continue;
            }

            // The label is the useful name here: Elementor ids are opaque
            // ("field_a1b2c3"), while the label is what the editor typed.
            $name = (string) ($field['title'] ?? $field['id'] ?? $key);

            $fields[] = [
                'type' => (string) ($field['type'] ?? ''),
                'name' => $name,
                'value' => (string) $value,
            ];
        }

        return $fields;
    }
}
