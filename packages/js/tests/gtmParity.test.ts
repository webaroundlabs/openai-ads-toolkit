import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

import { describe, expect, it } from 'vitest';

import {
  normalizeCityOrRegion,
  normalizeCountry,
  normalizeEmail,
  normalizeName,
  normalizePhone,
  normalizePostalCode,
} from '../src/userData.js';
import { loadSpec, type NormalizationFixture } from './spec.js';

/**
 * The fourth copy of the identity rules.
 *
 * The GTM server template reimplements normalization in Google's sandboxed
 * JavaScript, which has no regular expressions, so it cannot share a line with
 * the browser package. That is the copy most likely to drift, and it is the only
 * one whose own tests run somewhere this repository cannot reach - inside GTM's
 * template editor.
 *
 * So the functions are lifted out of the template and run here, against the same
 * fixtures the PHP and TypeScript suites assert. The sandbox's `require()` calls
 * and its globals are not needed by these particular functions; `makeString` is
 * the only one, and it is a documented `String()`.
 *
 * If this file ever becomes hard to maintain, that is a signal the template has
 * grown logic it should not have - not a signal to delete the test.
 */

const TEMPLATE = fileURLToPath(new URL('../../gtm-server/template.tpl', import.meta.url));

interface SandboxNormalizers {
  normalizeEmail: (value: string) => string;
  normalizePhone: (value: string) => string;
  normalizeName: (value: string) => string;
  normalizeCountry: (value: string) => string;
  normalizeCityOrRegion: (value: string) => string;
  normalizePostalCode: (value: string) => string;
  isAllDigits: (value: string) => boolean;
}

/**
 * Pull the named function declarations out of the template's sandboxed block.
 *
 * Brace-matching from the declaration rather than a regular expression over the
 * whole file: the block contains nested braces and string literals, and a lazy
 * pattern would silently capture half a function.
 */
function extract(source: string, name: string): string {
  const start = source.indexOf(`function ${name}(`);

  if (start === -1) {
    throw new Error(`The GTM server template no longer declares ${name}().`);
  }

  let depth = 0;
  let seenBody = false;

  for (let i = source.indexOf('{', start); i < source.length; i++) {
    const char = source[i];

    if (char === '{') {
      depth++;
      seenBody = true;
    } else if (char === '}') {
      depth--;

      if (seenBody && depth === 0) {
        return source.slice(start, i + 1);
      }
    }
  }

  throw new Error(`Could not find the end of ${name}() in the GTM server template.`);
}

function loadSandboxNormalizers(): SandboxNormalizers {
  const template = readFileSync(TEMPLATE, 'utf8');
  const names: Array<keyof SandboxNormalizers> = [
    'normalizeEmail',
    'normalizePhone',
    'normalizeName',
    'normalizeCountry',
    'normalizeCityOrRegion',
    'normalizePostalCode',
    'isAllDigits',
  ];

  const body = [
    // The sandbox's makeString is documented as String().
    'const makeString = String;',
    ...names.map((name) => extract(template, name)),
    `return { ${names.join(', ')} };`,
  ].join('\n\n');

  // eslint-disable-next-line @typescript-eslint/no-implied-eval, @typescript-eslint/no-unsafe-call
  return new Function(body)() as SandboxNormalizers;
}

const sandbox = loadSandboxNormalizers();
const fixture = loadSpec<NormalizationFixture>('fixtures/normalization.cases.json');

describe('the GTM server template normalizes identically to the browser package', () => {
  const hashed: Record<string, (value: string) => string> = {
    email: sandbox.normalizeEmail,
    phone: sandbox.normalizePhone,
    first_name: sandbox.normalizeName,
    last_name: sandbox.normalizeName,
  };

  it.each(fixture.valid.filter((c) => c.field in hashed).map((c) => [c.asserts, c] as const))(
    'reproduces the pinned normalization: %s',
    (_label, testCase) => {
      expect(hashed[testCase.field]!(testCase.raw)).toBe(testCase.normalized);
    },
  );

  it('lowercases a non-ASCII name the same way, which is what keeps the digests equal', () => {
    const guard = fixture.divergence_guard;

    expect(sandbox.normalizeName(guard.raw)).toBe(guard.correct.normalized);
    expect(sandbox.normalizeName(guard.raw)).not.toBe(guard.wrong.normalized);
  });

  const geographic: Record<string, (value: string) => string> = {
    country: sandbox.normalizeCountry,
    city: sandbox.normalizeCityOrRegion,
    region: sandbox.normalizeCityOrRegion,
    postal_code: sandbox.normalizePostalCode,
  };

  it.each(fixture.geographic.valid.map((c) => [c.asserts, c] as const))(
    'reproduces the pinned geographic normalization: %s',
    (_label, testCase) => {
      expect(geographic[testCase.field]!(testCase.raw)).toBe(testCase.normalized);
    },
  );

  it.each(fixture.geographic.rejected)(
    'refuses the same geographic values: $reason',
    (testCase) => {
      expect(geographic[testCase.field]!(testCase.raw)).toBe('');
    },
  );

  /**
   * The regression the phone rule exists for. The template cannot throw - a
   * tag that throws fails the container - so it reports a skip instead, and
   * `isAllDigits` is the check that decides.
   */
  it.each(fixture.rejected.filter((c) => c.field === 'phone'))(
    'treats the same phone as unusable: $reason',
    (testCase) => {
      const normalized = sandbox.normalizePhone(testCase.raw);

      const usable =
        sandbox.isAllDigits(normalized) && normalized.length >= 8 && normalized.length <= 15;

      expect(usable).toBe(false);
    },
  );

  /**
   * The assertion that actually matters: the two implementations are the same
   * function, not merely two functions that pass the same fixtures.
   */
  it.each([
    ['email', sandbox.normalizeEmail, normalizeEmail, [' Ada@Example.COM ', 'a@b.co', 'ÉLAN@x.io']],
    [
      'phone',
      sandbox.normalizePhone,
      normalizePhone,
      ['+40 746 123 456', '+00 44 (0)20 7946 0958', '+1.555.123.4567', '0000', 'not-a-phone'],
    ],
    [
      'name',
      sandbox.normalizeName,
      normalizeName,
      ['Ștefănescu', "O'Neill", ' Van  Der Berg ', 'Ion'],
    ],
    [
      'city',
      sandbox.normalizeCityOrRegion,
      normalizeCityOrRegion,
      [' București ', 'Île-de-France', 'a'.repeat(200)],
    ],
    [
      'postal code',
      sandbox.normalizePostalCode,
      normalizePostalCode,
      [' SW1A 1AA ', '01.01/01', '///', 'x'.repeat(40)],
    ],
  ] as const)('agrees with the browser package on %s', (_label, inSandbox, inPackage, inputs) => {
    for (const input of inputs) {
      expect(inSandbox(input), `input: ${input}`).toBe(inPackage(input));
    }
  });

  it('agrees with the browser package on country, where the browser returns undefined', () => {
    for (const input of [' ro ', 'US', 'Romania', 'R', '']) {
      expect(sandbox.normalizeCountry(input)).toBe(normalizeCountry(input) ?? '');
    }
  });
});
