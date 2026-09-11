<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Integrations;

/**
 * Contact Form 7.
 *
 * The success boundary is `wpcf7_mail_sent`, which fires only after the
 * submission has passed validation, survived any spam checks and been sent. The
 * tempting alternatives are all wrong: `wpcf7_before_send_mail` runs before the
 * outcome is known, and a submit-button click is not a lead at all.
 *
 * Contact Form 7 submits over AJAX, so the browser half of the conversion cannot
 * simply be printed into a page. The event id travels back in the form's own
 * JSON response and the bundled script fires the Pixel event with it - same
 * Pixel ID, same event name, same id, so the two are matched rather than
 * counted twice.
 */
final class ContactForm7 implements Integration
{
    /**
     * The id minted for the submission currently being processed, held between
     * the mail-sent hook and the response filter that follows it in the same
     * request.
     *
     * @var array<string, mixed>|null
     */
    private ?array $pending = null;

    public function __construct(
        private readonly LeadRecorder $recorder,
    ) {
    }

    public function id(): string
    {
        return 'contact_form_7';
    }

    public function label(): string
    {
        return 'Contact Form 7';
    }

    public function isAvailable(): bool
    {
        return class_exists('WPCF7_ContactForm');
    }

    public function register(): void
    {
        \add_action('wpcf7_mail_sent', [$this, 'onMailSent'], 10, 1);
        \add_filter('wpcf7_feedback_response', [$this, 'addResponseData'], 10, 2);
    }

    /**
     * Fires once the submission has genuinely succeeded.
     *
     * @param object $contactForm WPCF7_ContactForm
     */
    public function onMailSent(object $contactForm): void
    {
        $posted = $this->postedData();

        if ($posted === null) {
            return;
        }

        $this->pending = $this->recorder->record(
            LeadRecorder::identityFromFields($this->fields($contactForm, $posted)),
            (string) (method_exists($contactForm, 'id') ? $contactForm->id() : ''),
            (string) (method_exists($contactForm, 'title') ? $contactForm->title() : ''),
            $this->id(),
        );
    }

    /**
     * Attach the event id to the AJAX response so the browser can report the
     * same conversion.
     *
     * @param array<string, mixed> $response
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    public function addResponseData(array $response, array $result): array
    {
        if ($this->pending !== null && ($result['status'] ?? '') === 'mail_sent') {
            $response['openai_ads'] = $this->pending;
        }

        $this->pending = null;

        return $response;
    }

    /**
     * Read the submitted values, typed by the form's own tags.
     *
     * Using the tag's basetype rather than guessing from field names is what
     * makes email and phone detection reliable: the form builder already
     * declared which field is which.
     *
     * @param array<string, mixed> $posted
     *
     * @return list<array{type: string, name: string, value: string}>
     */
    private function fields(object $contactForm, array $posted): array
    {
        if (!method_exists($contactForm, 'scan_form_tags')) {
            return [];
        }

        $fields = [];

        /** @var iterable<object> $tags */
        $tags = $contactForm->scan_form_tags();

        foreach ($tags as $tag) {
            $name = (string) ($tag->name ?? '');

            if ($name === '' || !isset($posted[$name])) {
                continue;
            }

            $value = $posted[$name];

            if (is_array($value)) {
                $value = reset($value);
            }

            if (!is_scalar($value)) {
                continue;
            }

            $fields[] = [
                'type' => (string) ($tag->basetype ?? ''),
                'name' => $name,
                'value' => (string) $value,
            ];
        }

        return $fields;
    }

    /**
     * The submitted fields, or null when there is no usable submission.
     *
     * Returns the data rather than the submission object: the object is the only
     * thing a caller would ever ask for it, and handing back `?object` means
     * every caller re-proves that get_posted_data() exists.
     *
     * @return array<string, mixed>|null
     */
    private function postedData(): ?array
    {
        if (!class_exists('WPCF7_Submission')) {
            return null;
        }

        /** @var object|null $submission */
        $submission = \WPCF7_Submission::get_instance();

        if (!is_object($submission) || !method_exists($submission, 'get_posted_data')) {
            return null;
        }

        $posted = $submission->get_posted_data();

        return is_array($posted) ? $posted : null;
    }
}
