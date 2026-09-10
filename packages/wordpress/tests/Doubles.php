<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Tests;

/**
 * Stand-ins for the host plugins' objects.
 *
 * Only the handful of methods the integrations actually call. Depending on more
 * of Contact Form 7's or Elementor's internals than this would make the tests
 * fragile against their releases without testing anything more.
 */
final class FakeCf7Tag
{
    public function __construct(
        public readonly string $basetype,
        public readonly string $name,
    ) {
    }
}

final class FakeCf7Form
{
    /**
     * @param list<FakeCf7Tag> $tags
     */
    public function __construct(
        private readonly int $id,
        private readonly string $title,
        private readonly array $tags,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }

    /**
     * @return list<FakeCf7Tag>
     */
    public function scan_form_tags(): array
    {
        return $this->tags;
    }
}

final class FakeElementorRecord
{
    /**
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, mixed>                $settings
     */
    public function __construct(
        private readonly array $fields,
        private readonly array $settings = [],
    ) {
    }

    public function get(string $key): mixed
    {
        return $key === 'fields' ? $this->fields : null;
    }

    public function get_form_settings(string $key): mixed
    {
        return $this->settings[$key] ?? null;
    }
}

final class FakeElementorHandler
{
    /** @var array<string, mixed> */
    public array $responseData = [];

    public function add_response_data(string $key, mixed $value): void
    {
        $this->responseData[$key] = $value;
    }
}
