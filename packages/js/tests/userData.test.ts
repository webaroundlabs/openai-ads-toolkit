import { describe, expect, it } from 'vitest';

import { OpenAIAdsError } from '../src/errors.js';
import {
  hashUser,
  normalizeCityOrRegion,
  normalizeCountry,
  normalizeEmail,
  normalizeExternalId,
  normalizeName,
  normalizePhone,
  normalizePostalCode,
  sha256Hex,
} from '../src/userData.js';
import { loadSpec, type NormalizationFixture } from './spec.js';

const fixture = loadSpec<NormalizationFixture>('fixtures/normalization.cases.json');

const normalizers: Record<string, (value: string) => string> = {
  email: normalizeEmail,
  phone: normalizePhone,
  external_id: normalizeExternalId,
  first_name: normalizeName,
  last_name: normalizeName,
};

describe('normalization matches the shared specification', () => {
  /**
   * The point of this suite: these are the same pinned digests the PHP test
   * suite asserts, from the same file. If the two runtimes ever disagree about
   * what a value hashes to, a Pixel event and its Conversions API twin stop
   * describing the same person - and nothing reports it.
   */
  it.each(fixture.valid.map((c) => [c.asserts, c] as const))(
    'reproduces the pinned digest: %s',
    async (_label, testCase) => {
      const normalize = normalizers[testCase.field];
      expect(normalize, `no normalizer mapped for "${testCase.field}"`).toBeDefined();

      const normalized = normalize!(testCase.raw);

      expect(normalized).toBe(testCase.normalized);
      await expect(sha256Hex(normalized)).resolves.toBe(testCase.sha256);
    },
  );

  /**
   * Geographic values are never hashed, but the API documents a normalization
   * rule for each and drops a value that does not satisfy it, silently. The PHP
   * suite asserts these same cases from this same file.
   */
  it.each(fixture.geographic.valid.map((c) => [c.asserts, c] as const))(
    'reproduces the pinned geographic normalization: %s',
    (_label, testCase) => {
      const normalize =
        testCase.field === 'country'
          ? (v: string) => normalizeCountry(v) ?? ''
          : testCase.field === 'postal_code'
            ? normalizePostalCode
            : normalizeCityOrRegion;

      expect(normalize(testCase.raw)).toBe(testCase.normalized);
    },
  );

  it('lowercases non-ASCII names, producing the correct digest and not the byte-wise one', async () => {
    const guard = fixture.divergence_guard;

    const digest = await sha256Hex(normalizeName(guard.raw));

    expect(digest).toBe(guard.correct.sha256);
    expect(digest).not.toBe(guard.wrong.sha256);
  });
});

describe('hashUser', () => {
  it('produces the Pixel shape: singular keys, scalar values', async () => {
    const user = await hashUser({
      email: ' Ada@Example.COM ',
      phone: '+40 746 123 456',
      externalId: '  CUST-00042  ',
      firstName: 'Ion',
      lastName: 'Ștefănescu',
      country: 'RO',
      city: 'București',
      region: 'București',
      postalCode: '010101',
    });

    const capiFixture = loadSpec<{ expected: Record<string, string[] | string> }>(
      'fixtures/user.full.capi.json',
    );
    const pixelFixture = loadSpec<{ expected: Record<string, string> }>(
      'fixtures/user.full.pixel.json',
    );

    expect(user).toEqual(pixelFixture.expected);

    // Same digests as the server produces, under different key names.
    expect(user.email_sha256).toBe((capiFixture.expected['emails_sha256'] as string[])[0]);
    expect(user.last_name_sha256).toBe((capiFixture.expected['last_names_sha256'] as string[])[0]);
  });

  it('omits fields the browser must not send', async () => {
    const user = await hashUser({ email: 'ada@example.com' });

    // obref, ip_address, user_agent and android_advertising_id are marked
    // pixel:null in the spec - the browser supplies them itself.
    expect(user).not.toHaveProperty('obref');
    expect(user).not.toHaveProperty('ip_address');
    expect(user).not.toHaveProperty('user_agent');
  });

  it('returns an empty object when nothing was supplied', async () => {
    await expect(hashUser({})).resolves.toEqual({});
  });

  it('drops values that normalize away rather than hashing an empty string', async () => {
    const user = await hashUser({ email: '   ', city: '  ' });

    expect(user).toEqual({});
  });

  it('is idempotent for an already-clean value', async () => {
    const once = await hashUser({ email: ' Ada@Example.COM ' });
    const twice = await hashUser({ email: 'ada@example.com' });

    expect(once).toEqual(twice);
  });

  it('preserves the case of an external id', async () => {
    const upper = await hashUser({ externalId: 'CUST-42' });
    const lower = await hashUser({ externalId: 'cust-42' });

    expect(upper.external_id_sha256).not.toBe(lower.external_id_sha256);
  });

  it.each(fixture.rejected.filter((c) => c.field === 'phone'))(
    'refuses a phone without echoing it: $reason',
    async (testCase) => {
      await expect(hashUser({ phone: testCase.raw })).rejects.toThrow(OpenAIAdsError);

      await hashUser({ phone: testCase.raw }).catch((error: Error) => {
        const isNonDigit = !/^[0-9]*$/.test(testCase.normalized);

        expect(error.message).toContain(
          isNonDigit ? 'only digits' : 'between 8 and 15 digits',
        );
        expect(error.message).not.toContain(testCase.normalized);
      });
    },
  );

  /**
   * The regression the phone rule exists for. Stripping every non-digit would
   * turn the extension into two more digits, pass the length check, and hash a
   * number belonging to nobody.
   */
  it('refuses a phone with an extension rather than silently rewriting it', async () => {
    await expect(hashUser({ phone: '+1 (555) 123-4567 ext. 89' })).rejects.toThrow(
      /only digits/,
    );
  });

  it.each(fixture.geographic.rejected)(
    'does not send a geographic value the API would drop: $reason',
    async (testCase) => {
      if (testCase.field === 'country') {
        await expect(hashUser({ country: testCase.raw })).rejects.toThrow(
          /two-letter ISO 3166-1 alpha-2 code/,
        );

        return;
      }

      await expect(hashUser({ postalCode: testCase.raw })).resolves.toEqual({});
    },
  );
});

describe('sha256Hex', () => {
  it('fails loudly when Web Crypto is unavailable instead of sending raw values', async () => {
    const original = globalThis.crypto;

    Object.defineProperty(globalThis, 'crypto', { value: undefined, configurable: true });

    try {
      await expect(sha256Hex('ada@example.com')).rejects.toThrow(/Web Crypto is unavailable/);
    } finally {
      Object.defineProperty(globalThis, 'crypto', { value: original, configurable: true });
    }
  });
});
