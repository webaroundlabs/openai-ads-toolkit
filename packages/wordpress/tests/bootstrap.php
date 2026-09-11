<?php

declare(strict_types=1);

/**
 * Minimal WordPress stubs.
 *
 * The plugin's testable surface - transport, settings sanitization, request
 * context, event building, Pixel markup - depends on a small, well-defined set
 * of WordPress functions. Stubbing those is enough to test it properly, and
 * avoids making a full WordPress install a prerequisite for running the suite.
 *
 * Anything that genuinely needs WordPress (the admin screen's rendering, hook
 * registration order) is exercised on a real site instead.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Several small host-plugin doubles share one file, which PSR-4 cannot autoload.
require_once __DIR__ . '/Doubles.php';
require_once __DIR__ . '/WooDoubles.php';

final class WpStubs
{
    /** @var array<string, mixed> */
    public static array $options = [];

    /** @var array<string, list<callable>> */
    public static array $filters = [];

    /** @var list<array{string, array<int, mixed>}> */
    public static array $actions = [];

    /** @var list<array{url: string, args: array<string, mixed>}> */
    public static array $requests = [];

    /** @var mixed */
    public static $nextResponse = null;

    public static function reset(): void
    {
        self::$options = [];
        self::$filters = [];
        self::$actions = [];
        self::$requests = [];
        self::$nextResponse = null;
        $_COOKIE = [];
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
    }
}

final class WP_Error
{
    public function __construct(
        private readonly string $code = 'error',
        private readonly string $message = 'Something went wrong',
    ) {
    }

    public function get_error_message(): string
    {
        return $this->message;
    }

    public function get_error_code(): string
    {
        return $this->code;
    }
}

function is_wp_error(mixed $thing): bool
{
    return $thing instanceof WP_Error;
}

function get_option(string $name, mixed $default = false): mixed
{
    return WpStubs::$options[$name] ?? $default;
}

function update_option(string $name, mixed $value): bool
{
    WpStubs::$options[$name] = $value;

    return true;
}

function home_url(string $path = ''): string
{
    return 'https://shop.example.com' . $path;
}

function wp_parse_url(string $url, int $component = -1): mixed
{
    return parse_url($url, $component);
}

function sanitize_text_field(string $value): string
{
    $value = strip_tags($value);
    $value = (string) preg_replace('/[\r\n\t]+/', '', $value);

    return trim($value);
}

function esc_url_raw(string $url): string
{
    $url = trim($url);

    return preg_match('#^https?://#i', $url) === 1 ? $url : '';
}

function esc_url(string $url): string
{
    $url = trim($url);

    if (preg_match('#^https?://#i', $url) !== 1) {
        return '';
    }

    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function wp_json_encode(mixed $value): string|false
{
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

function wp_generate_uuid4(): string
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000,
        random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0xffff),
    );
}

function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted = 1): bool
{
    WpStubs::$filters[$hook][] = $callback;

    return true;
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    foreach (WpStubs::$filters[$hook] ?? [] as $callback) {
        $value = $callback($value, ...$args);
    }

    return $value;
}

function add_action(string $hook, callable $callback, int $priority = 10, int $accepted = 1): bool
{
    WpStubs::$filters[$hook][] = $callback;

    return true;
}

function do_action(string $hook, mixed ...$args): void
{
    WpStubs::$actions[] = [$hook, $args];
}

function is_admin(): bool
{
    return false;
}

function plugin_basename(string $file): string
{
    return basename(dirname($file)) . '/' . basename($file);
}

function admin_url(string $path = ''): string
{
    return 'https://shop.example.com/wp-admin/' . $path;
}

function wp_remote_request(string $url, array $args = []): mixed
{
    WpStubs::$requests[] = ['url' => $url, 'args' => $args];

    return WpStubs::$nextResponse ?? [
        'response' => ['code' => 200, 'message' => 'OK'],
        'headers' => ['content-type' => 'application/json'],
        'body' => '{}',
    ];
}

// error_log() is native to PHP and cannot be stubbed. Measurement only calls
// it when debug logging is switched on, which the tests leave off.

/**
 * Contact Form 7's submission singleton.
 *
 * Note that WPCF7_ContactForm is deliberately NOT defined: that is what the
 * integration's availability check looks for, and the tests assert it reports
 * itself unavailable on a site without the plugin.
 */
final class Cf7SubmissionStub
{
    /** @var array<string, mixed> */
    public static array $posted = [];
}

class WPCF7_Submission
{
    public static function get_instance(): self
    {
        return new self();
    }

    /**
     * @return array<string, mixed>
     */
    public function get_posted_data(): array
    {
        return Cf7SubmissionStub::$posted;
    }
}

function plugins_url(string $path = '', string $plugin = ''): string
{
    return 'https://shop.example.com/wp-content/plugins/openai-ads/' . ltrim($path, '/');
}

function wp_enqueue_script(string $handle, string $src = '', array $deps = [], mixed $ver = false, mixed $args = false): void
{
}

/**
 * WooCommerce function stubs.
 *
 * Note that the WooCommerce class itself is deliberately NOT defined: that is
 * what the integration's availability check looks for, and a test asserts it
 * reports itself unavailable on a site without the plugin.
 */
final class WooStubs
{
    /** @var array<int, object> */
    public static array $orders = [];

    public static string $currency = 'EUR';

    public static function reset(): void
    {
        self::$orders = [];
        self::$currency = 'EUR';
    }
}

function wc_get_order(mixed $id): mixed
{
    return WooStubs::$orders[(int) $id] ?? false;
}

function get_woocommerce_currency(): string
{
    return WooStubs::$currency;
}

/**
 * Action Scheduler stubs.
 *
 * Not defined by default: the plugin feature-detects as_enqueue_async_action(),
 * and a site without WooCommerce simply keeps the in-request shutdown flush. The
 * scheduler tests define it themselves to exercise the other path.
 */
final class SchedulerStubs
{
    /** @var list<array{hook: string, args: array<int, mixed>, group: string}> */
    public static array $scheduled = [];

    public static bool $available = false;

    public static function reset(): void
    {
        self::$scheduled = [];
        self::$available = false;
    }
}

if (!function_exists('as_enqueue_async_action')) {
    function as_enqueue_async_action(string $hook, array $args = [], string $group = ''): int
    {
        SchedulerStubs::$scheduled[] = ['hook' => $hook, 'args' => $args, 'group' => $group];

        return count(SchedulerStubs::$scheduled);
    }
}

function add_option(string $name, mixed $value, string $deprecated = '', bool|string $autoload = true): bool
{
    if (array_key_exists($name, WpStubs::$options)) {
        return false;
    }

    WpStubs::$options[$name] = $value;

    return true;
}

function delete_option(string $name): bool
{
    unset(WpStubs::$options[$name]);

    return true;
}

WpStubs::reset();
WooStubs::reset();
SchedulerStubs::reset();

// The public API is plain functions rather than a class, so it is loaded the
// way the plugin loads it - and therefore actually covered by these tests.
require_once __DIR__ . '/../src/api.php';
