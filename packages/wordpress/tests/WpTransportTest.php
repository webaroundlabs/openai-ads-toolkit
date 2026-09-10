<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use WebaroundLabs\OpenAIAds\Capi\Client;
use WebaroundLabs\OpenAIAds\Capi\TransportException;
use WebaroundLabs\OpenAIAds\SystemClock;
use WebaroundLabs\OpenAIAds\WordPress\Http\NetworkFailure;
use WebaroundLabs\OpenAIAds\WordPress\Http\WpTransport;
use WP_Error;
use WpStubs;

#[CoversClass(WpTransport::class)]
#[CoversClass(NetworkFailure::class)]
final class WpTransportTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubs::reset();
    }

    #[Test]
    public function it_sends_a_psr7_request_through_the_wordpress_http_api(): void
    {
        $factory = new Psr17Factory();
        $transport = new WpTransport($factory, 7);

        $request = $factory->createRequest('POST', 'https://bzr.openai.com/v1/events?pid=px-1')
            ->withHeader('Authorization', 'Bearer secret')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream('{"events":[]}'));

        $response = $transport->sendRequest($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, WpStubs::$requests);

        $sent = WpStubs::$requests[0];
        self::assertSame('https://bzr.openai.com/v1/events?pid=px-1', $sent['url']);
        self::assertSame('POST', $sent['args']['method']);
        self::assertSame('{"events":[]}', $sent['args']['body']);
        self::assertSame('Bearer secret', $sent['args']['headers']['Authorization']);
        self::assertSame('application/json', $sent['args']['headers']['Content-Type']);
    }

    #[Test]
    public function it_uses_a_bounded_timeout_and_does_not_follow_redirects(): void
    {
        $factory = new Psr17Factory();

        (new WpTransport($factory, 7))->sendRequest($factory->createRequest('POST', 'https://example.com'));

        $args = WpStubs::$requests[0]['args'];
        self::assertSame(7, $args['timeout']);
        self::assertSame(0, $args['redirection']);
        self::assertTrue($args['blocking']);
    }

    #[Test]
    public function it_does_not_forward_the_host_header(): void
    {
        // WordPress derives Host from the URL; forwarding ours can break vhosts.
        $factory = new Psr17Factory();
        $request = $factory->createRequest('POST', 'https://bzr.openai.com/v1/events');

        (new WpTransport($factory))->sendRequest($request);

        self::assertArrayNotHasKey('Host', WpStubs::$requests[0]['args']['headers']);
    }

    #[Test]
    public function it_returns_a_non_2xx_response_rather_than_treating_it_as_a_failure(): void
    {
        WpStubs::$nextResponse = [
            'response' => ['code' => 400, 'message' => 'Bad Request'],
            'headers' => ['content-type' => 'application/json'],
            'body' => '{"error":"bad"}',
        ];
        $factory = new Psr17Factory();

        $response = (new WpTransport($factory))->sendRequest($factory->createRequest('POST', 'https://example.com'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('{"error":"bad"}', (string) $response->getBody());
    }

    /**
     * A WP_Error means no round trip happened, which is a different thing from
     * the API refusing the payload - and the core relies on that distinction to
     * decide whether a retry could ever be safe.
     */
    #[Test]
    public function a_wp_error_becomes_a_psr18_network_exception(): void
    {
        WpStubs::$nextResponse = new WP_Error('http_request_failed', 'cURL error 28: timed out');
        $factory = new Psr17Factory();

        try {
            (new WpTransport($factory))->sendRequest($factory->createRequest('POST', 'https://example.com'));
            self::fail('Expected a network exception.');
        } catch (NetworkExceptionInterface $e) {
            self::assertStringContainsString('could not be reached', $e->getMessage());
            self::assertStringContainsString('timed out', $e->getMessage());
            self::assertSame('POST', $e->getRequest()->getMethod());
        }
    }

    /**
     * The whole point of the core depending on PSR-18: this transport drops
     * straight into it, and the core turns a WordPress failure into its own
     * TransportException without knowing WordPress exists.
     */
    #[Test]
    public function the_core_client_works_through_this_transport(): void
    {
        $factory = new Psr17Factory();
        $client = new Client(
            pixelId: 'px-1',
            apiKey: 'secret',
            http: new WpTransport($factory),
            requests: $factory,
            streams: $factory,
            clock: new SystemClock(),
            integrationSource: 'webaroundlabs-wordpress',
        );

        $response = $client->send([EventFactory::lead()]);

        self::assertTrue($response->isSuccessful());
        $body = json_decode((string) WpStubs::$requests[0]['args']['body'], true);
        self::assertSame('webaroundlabs-wordpress', $body['integration_source']);
        self::assertSame('lead_created', $body['events'][0]['type']);
    }

    #[Test]
    public function a_wordpress_failure_surfaces_as_the_cores_transport_exception(): void
    {
        WpStubs::$nextResponse = new WP_Error('http_request_failed', 'connection refused');
        $factory = new Psr17Factory();
        $client = new Client(
            pixelId: 'px-1',
            apiKey: 'secret',
            http: new WpTransport($factory),
            requests: $factory,
            streams: $factory,
            clock: new SystemClock(),
        );

        $this->expectException(TransportException::class);

        $client->send([EventFactory::lead()]);
    }

    #[Test]
    public function the_api_key_is_never_part_of_a_failure_message(): void
    {
        WpStubs::$nextResponse = new WP_Error('http_request_failed', 'connection refused');
        $factory = new Psr17Factory();
        $request = $factory->createRequest('POST', 'https://example.com')
            ->withHeader('Authorization', 'Bearer super-secret-key');

        try {
            (new WpTransport($factory))->sendRequest($request);
            self::fail('Expected a network exception.');
        } catch (NetworkExceptionInterface $e) {
            self::assertStringNotContainsString('super-secret-key', $e->getMessage());
        }
    }
}
