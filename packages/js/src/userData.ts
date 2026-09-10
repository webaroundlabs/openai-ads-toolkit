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

export function normalizeEmail(value: string): string {
  return value.trim().toLowerCase();
}

/**
 * Remove every non-digit, then leading zeroes.
 *
 * The upstream documentation lists the operations but not their order, and the
 * order changes the result, so the toolkit pins this one. It does not parse
 * dialling plans: '+00 44 (0)20 7946 0958' becomes '4402079460958'.
 */
export function normalizePhone(value: string): string {
  return value.trim().replace(/\D/g, '').replace(/^0+/, '');
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

    if (key === 'phone_number_sha256' && (normalized.length < 8 || normalized.length > 15)) {
      // The number itself is deliberately absent from this message.
      throw new OpenAIAdsError(
        `phone must contain between 8 and 15 digits after normalization; got ${normalized.length}.`,
      );
    }

    user[key] = await sha256Hex(normalized);
  }

  const plain: Array<[keyof PixelUser, string | undefined]> = [
    ['country', raw.country],
    ['city', raw.city],
    ['region', raw.region],
    ['postal_code', raw.postalCode],
  ];

  for (const [key, value] of plain) {
    const trimmed = value?.trim();

    if (trimmed !== undefined && trimmed !== '') {
      user[key] = trimmed;
    }
  }

  return user;
}
