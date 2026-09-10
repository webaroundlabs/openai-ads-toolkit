<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\Tests;

/**
 * Reads the shared specification from `packages/spec`.
 *
 * The spec is a development-time asset reached by relative path, never a
 * Composer dependency - which is why these tests only run from a monorepo
 * checkout and not from an installed package.
 */
final class Spec
{
    /**
     * @return array<string, mixed>
     */
    public static function load(string $file): array
    {
        $path = __DIR__ . '/../../spec/' . $file;
        $real = realpath($path);

        if ($real === false) {
            throw new \RuntimeException(sprintf(
                'Specification file "%s" not found at %s. These tests require a monorepo checkout.',
                $file,
                $path,
            ));
        }

        $contents = file_get_contents($real);

        if ($contents === false) {
            throw new \RuntimeException(sprintf('Could not read %s.', $real));
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
