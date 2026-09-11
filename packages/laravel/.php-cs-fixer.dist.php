<?php

declare(strict_types=1);

/**
 * Code style for the toolkit's PHP packages.
 *
 * PSR-12 plus a small set of rules that remove review comments nobody should
 * have to write: import ordering, trailing commas, strict types, short array
 * syntax. Nothing here changes behaviour, and nothing here has an opinion about
 * how the library works - the charter in CLAUDE.md covers that.
 *
 * Global classes are deliberately left alone: `\RuntimeException` written
 * inline is as clear as an import, and churning every file to pick one is not
 * worth a commit.
 */

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arguments', 'arrays', 'parameters']],
    ])
    ->setFinder($finder);
