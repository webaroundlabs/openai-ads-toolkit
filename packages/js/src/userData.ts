import { OpenAIAdsError } from './errors.js';
import type { PixelUser, RawUser } from './types.js';

/**
 * Identity normalization and hashing in the browser.
 *
 * These rules must produce byte-identical digests to the PHP core, because a
 * Pixel event and its Conversions API twin describe the same person. Both
 * implementations are pinned by
 * `packages/spec/fixtures/normalization.cases.json`, and both test suites read
 * that file.
 *
 * Raw values never leave this module: `hashUser()` returns digests, and nothing
 * here writes a raw identifier to the DOM, to storage, or to the console.
 */

/** ASCII punctuation. Non-ASCII characters are preserved, as the spec requires. */
const ASCII_PUNCTUATION = /[!-/:-@[-`{-~]/g;

/** Mirrors `normalizations.city_region.max_length` in packages/spec/user.json. */
const CITY_REGION_MAX_LENGTH = 128;

/** Mirrors `normalizations.postal_code.max_length` in packages/spec/user.json. */
const POSTAL_CODE_MAX_LENGTH = 32;

export function normalizeEmail(value: string): string {
  return value.trim().toLowerCase();
}

/**
 * Remove the four documented separators, then a leading '+', then leading
 * zeroes - and nothing else.
 *
 * Upstream documents '8-15 digits after removing a leading +, leading zeroes,
 * whitespace, parentheses, periods, and hyphens'. Removing every non-digit
 * instead looks equivalent and is not: '+1 (555) 123-4567 ext. 89' would become
 * '1555123456789', which passes a length check and hashes to nobody. Whatever
 * is left over is returned as-is so the caller can refuse it.
 *
 * Dialling plans are still not parsed: '+00 44 (0)20 7946 0958' becomes
 * '4402079460958'.
 */
export function normalizePhone(value: string): string {
  return value
    .trim()
    .replace(/[\s().-]/g, '')
    .replace(/^\+/, '')
    .replace(/^0+/, '');
}

/**
 * ISO 3166-1 alpha-2, uppercased.
 *
 * Returns undefined for anything that is not two ASCII letters. A country name
 * rather than a code is dropped by the API without an error, so sending one
 * would look like matching data and be nothing of the sort.
 */
export function normalizeCountry(value: string): string | undefined {
  const trimmed = value.trim();

  return /^[A-Za-z]{2}$/.test(trimmed) ? trimmed.toUpperCase() : undefined;
}

/**
 * Trim, lowercase, cap at 128 characters - what the API does on receipt.
 *
 * Applied here too so the string sent is the string stored, and so the Pixel
 * and the Conversions API carry the same one for the same person.
 */
export function normalizeCityOrRegion(value: string): string {
  return value.trim().toLowerCase().slice(0, CITY_REGION_MAX_LENGTH);
}

/**
 * Reduce to letters, digits, spaces and hyphens, cap at 32 characters.
 *
 * Disallowed characters are removed rather than refused - a stray period is a
 * formatting artefact. Case is deliberately not folded: upstream states a
 * lowercase rule for cities and regions and states none here.
 */
export function normalizePostalCode(value: string): string {
  return value
    .trim()
    .replace(/[^A-Za-z0-9 -]/g, '')
    .slice(0, POSTAL_CODE_MAX_LENGTH)
    .trim();
}

export function normalizeExternalId(value: string): string {
  // Case is preserved deliberately.
  return value.trim();
}

/**
 * Lowercase, then strip whitespace and ASCII punctuation.
 *
 * `toLowerCase()` is Unicode-aware, which is what the PHP side gets from
 * `mb_strtolower`. A byte-wise lowercase there would leave a diacritic
 * untouched and produce a digest that never matches this one.
 */
export function normalizeName(value: string): string {
  return value.trim().toLowerCase().replace(/\s+/gu, '').replace(ASCII_PUNCTUATION, '');
}

/**
 * SHA-256 as lowercase hexadecimal.
 *
 * Web Crypto is asynchronous and only available in a secure context, so this is
 * async and fails loudly on plain HTTP rather than degrading to a weaker hash or
 * - far worse - sending the raw value.
 */
export async function sha256Hex(value: string): Promise<string> {
  const subtle = globalThis.crypto?.subtle;

  if (subtle === undefined) {
    throw new OpenAIAdsError(
      'Web Crypto is unavailable, so identity cannot be hashed in the browser. ' +
        'This usually means the page is not served over HTTPS. Raw values are never sent.',
    );
  }

  const digest = await subtle.digest('SHA-256', new TextEncoder().encode(value));

  return [...new Uint8Array(digest)].map((byte) => byte.toString(16).padStart(2, '0')).join('');
}

/**
 * Normalize and hash raw identity into the Pixel's shape.
 *
 * Pass the result to `init({ user })` when the person becomes known - after a
 * login, a checkout, a lead submission. Geographic values are sent unhashed, as
 * documented.
 *
 * Values that normalize away to nothing are dropped rather than hashed: the
 * digest of an empty string is a valid-looking value that matches nobody.
 */
export async function hashUser(raw: RawUser): Promise<PixelUser> {
  const user: PixelUser = {};

  const hashed: Array<[keyof PixelUser, string | undefined, (v: string) => string]> = [
    ['email_sha256', raw.email, normalizeEmail],
    ['phone_number_sha256', raw.phone, normalizePhone],
    ['external_id_sha256', raw.externalId, normalizeExternalId],
    ['first_name_sha256', raw.firstName, normalizeName],
    ['last_name_sha256', raw.lastName, normalizeName],
  ];

  for (const [key, value, normalize] of hashed) {
    if (value === undefined || value === null) {
      continue;
    }

    const normalized = normalize(value);

    if (normalized === '') {
      continue;
    }

    if (key === 'phone_number_sha256') {
      // Both messages deliberately omit the number itself.
      if (!/^[0-9]*$/.test(normalized)) {
        throw new OpenAIAdsError(
          'phone must contain only digits once whitespace, parentheses, periods, hyphens, ' +
            'a leading plus and leading zeroes are removed. An extension or a letter is not ' +
            'stripped, because stripping it would silently hash a different number.',
        );
      }

      if (normalized.length < 8 || normalized.length > 15) {
        throw new OpenAIAdsError(
          `phone must contain between 8 and 15 digits after normalization; got ${normalized.length}.`,
        );
      }
    }

    user[key] = await sha256Hex(normalized);
  }

  // Geographic values are sent unhashed but NOT unnormalized: the API documents
  // a rule for each and drops a value that does not satisfy it, silently.
  if (raw.country !== undefined && raw.country.trim() !== '') {
    const country = normalizeCountry(raw.country);

    if (country === undefined) {
      throw new OpenAIAdsError(
        `country must be a two-letter ISO 3166-1 alpha-2 code such as "US"; got "${raw.country.trim()}".`,
      );
    }

    user.country = country;
  }

  const geographic: Array<[keyof PixelUser, string | undefined, (v: string) => string]> = [
    ['city', raw.city, normalizeCityOrRegion],
    ['region', raw.region, normalizeCityOrRegion],
    ['postal_code', raw.postalCode, normalizePostalCode],
  ];

  for (const [key, value, normalize] of geographic) {
    if (value === undefined || value === null) {
      continue;
    }

    const normalized = normalize(value);

    if (normalized !== '') {
      user[key] = normalized;
    }
  }

  return user;
}
