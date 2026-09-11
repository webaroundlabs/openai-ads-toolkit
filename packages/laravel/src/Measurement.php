<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\Laravel;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;
use WebaroundLabs\OpenAIAds\Capi\Client;
use WebaroundLabs\OpenAIAds\Capi\Response;
use WebaroundLabs\OpenAIAds\Capi\TransportException;
use WebaroundLabs\OpenAIAds\Event;
use WebaroundLabs\OpenAIAds\ImageTag;
use WebaroundLabs\OpenAIAds\InvalidArgument;
use WebaroundLabs\OpenAIAds\Laravel\Jobs\SendConversionEvents;
use WebaroundLabs\OpenAIAds\UserData;

/**
 * What the OpenAIAds facade resolves to.
 *
 * Thin by design. It decides three things - whether measurement is switched on,
 * whether consent allows it, and whether to send now or on the queue - and then
 * hands the work to the core. It contains no knowledge of the OpenAI Ads wire
 * format.
 *
 * Nothing here throws. Analytics must never break the application, so a failure
 * to report a conversion is logged and swallowed; the checkout or the form
 * submission that triggered it still succeeds.
 */
final class Measurement
{
    /**
     * Documented field name => the core's parameter name.
     *
     * The keys are the names OpenAI's documentation uses, so an application
     * developer recognizes them without learning a second vocabulary.
     *
     * @var array<string, string>
     */
    private const IDENTITY_FIELDS = [
        'email' => 'email',
        'phone' => 'phone',
        'external_id' => 'externalId',
        'first_name' => 'firstName',
        'last_name' => 'lastName',
        'country' => 'country',
        'city' => 'city',
        'region' => 'region',
        'postal_code' => 'postalCode',
    ];

    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Send now, in the current request.
     *
     * Prefer `queue()` on a web request: this blocks until OpenAI answers.
     *
     * @return Response|null null when measurement did not happen at all - disabled,
     *                       unconfigured, consent refused, or a delivery failure
     */
    public function send(Event ...$events): ?Response
    {
        if (!$this->allowed() || $events === []) {
            return null;
        }

        try {
            // array_values because a variadic can be called with named
            // arguments, which would hand the core a string-keyed array where
            // its contract says list.
            return $this->client()->send(
                array_values($events),
                (bool) $this->config->get('openai-ads.validate_only'),
            );
        } catch (InvalidArgument $e) {
            // A malformed or stale event. Retrying will not fix it.
            $this->logger->error('OpenAI Ads: event rejected before sending.', [
                'reason' => $e->getMessage(),
            ]);
        } catch (TransportException $e) {
            $this->logger->warning('OpenAI Ads: the request did not complete.', [
                'reason' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Hand the events to the queue.
     *
     * The usual choice: the conversion has already succeeded, and the visitor
     * should not wait for an ad platform. Falls back to sending inline when the
     * queue is switched off.
     */
    public function queue(Event ...$events): void
    {
        if (!$this->allowed() || $events === []) {
            return;
        }

        if (!$this->config->get('openai-ads.queue.enabled')) {
            $this->send(...$events);

            return;
        }

        $job = new SendConversionEvents(array_values($events));

        $connection = $this->config->get('openai-ads.queue.connection');
        $queue = $this->config->get('openai-ads.queue.queue');

        if (is_string($connection) && $connection !== '') {
            $job->onConnection($connection);
        }

        if (is_string($queue) && $queue !== '') {
            $job->onQueue($queue);
        }

        dispatch($job);
    }

    /**
     * Check the batch against the API without recording it.
     *
     * Uses the documented validation mode, which is the one honest way to prove
     * an integration works end to end. Always sends inline, and ignores the
     * `enabled` switch so it stays useful on a staging environment where
     * measurement is off.
     */
    public function validate(Event ...$events): ?Response
    {
        if (!$this->configured() || $events === []) {
            return null;
        }

        try {
            return $this->client()->validate(array_values($events));
        } catch (InvalidArgument | TransportException $e) {
            $this->logger->warning('OpenAI Ads: validation failed.', ['reason' => $e->getMessage()]);
        }

        return null;
    }

    /** Attribution and identity context for the current request. */
    public function context(): RequestContext
    {
        return $this->container->make(RequestContext::class);
    }

    /**
     * Raw identity, hashed into the shape the browser Pixel wants.
     *
     * This is what `@openaiAdsPixel(['email' => $user->email])` calls. Hashing
     * happens here, on the server, so a raw email address never reaches browser
     * code - which is the whole reason the directive takes raw values rather
     * than asking the application to hash them itself.
     *
     * Never throws. A page must render even when a stored phone number turns out
     * to be unusable; the offending field is dropped and logged, and the rest
     * still matches.
     *
     * @param array<string, mixed> $user email, phone, external_id, first_name,
     *                                   last_name, country, city, region, postal_code
     *
     * @return array<string, string>
     */
    public function pixelUser(array $user): array
    {
        if (!$this->pixelEnabled() || $user === []) {
            return [];
        }

        $values = [];

        foreach (self::IDENTITY_FIELDS as $key => $parameter) {
            $values[$parameter] = isset($user[$key]) && is_string($user[$key]) ? $user[$key] : null;
        }

        return UserData::fromUntrusted(
            $values,
            function (string $field, string $reason): void {
                // The reason names no identifier, and neither does this line.
                $this->logger->notice('OpenAI Ads: identity field dropped.', [
                    'field' => $field,
                    'reason' => $reason,
                ]);
            },
        )->toPixelArray();
    }

    /**
     * A no-JavaScript conversion URL, for an email body or an AMP page.
     *
     * Returns null when measurement is off, consent was refused, or the event
     * cannot travel in a URL - which is the case whenever it carries identity,
     * because OpenAI documents no user object for this channel and forbids
     * personal data in a query parameter. Strip it deliberately with
     * `Event::withoutIdentity()` when you mean to.
     */
    public function imageTagUrl(Event $event): ?string
    {
        $pixelId = $this->pixelId();

        if ($pixelId === null || !$this->pixelEnabled()) {
            return null;
        }

        try {
            return ImageTag::url($pixelId, $event);
        } catch (InvalidArgument $e) {
            $this->logger->warning('OpenAI Ads: the image tag could not be built.', [
                'reason' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /** The public Pixel ID, safe to render in a page. */
    public function pixelId(): ?string
    {
        $pixelId = $this->config->get('openai-ads.pixel_id');

        return is_string($pixelId) && trim($pixelId) !== '' ? trim($pixelId) : null;
    }

    /**
     * Whether the browser Pixel should be rendered.
     *
     * Independent of the Conversions API: a site may run one without the other.
     */
    public function pixelEnabled(): bool
    {
        return (bool) $this->config->get('openai-ads.pixel_enabled')
            && $this->pixelId() !== null
            && $this->consented();
    }

    /**
     * Whether the visitor has consented.
     *
     * Defers to the host application's own mechanism through the `consent`
     * callback. With no callback configured this returns true, because the
     * toolkit must not invent a privacy model - gating is the application's
     * responsibility and its existing banner already knows the answer.
     */
    public function consented(): bool
    {
        $callback = $this->config->get('openai-ads.consent');

        if (!is_callable($callback)) {
            return true;
        }

        return (bool) $callback();
    }

    private function allowed(): bool
    {
        if (!$this->config->get('openai-ads.enabled')) {
            return false;
        }

        if (!$this->configured()) {
            $this->logger->warning(
                'OpenAI Ads: measurement is enabled but OPENAI_ADS_PIXEL_ID or '
                . 'OPENAI_ADS_CAPI_KEY is missing; nothing was sent.',
            );

            return false;
        }

        // A refused consent is a normal outcome, so it is silent.
        return $this->consented();
    }

    private function configured(): bool
    {
        $key = $this->config->get('openai-ads.capi_key');

        return $this->pixelId() !== null && is_string($key) && trim($key) !== '';
    }

    private function client(): Client
    {
        return $this->container->make(Client::class);
    }
}
