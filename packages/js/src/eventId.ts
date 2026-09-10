import { OpenAIAdsError } from './errors.js';

/**
 * Deduplication identifiers.
 *
 * The key is Pixel ID + event name + event id, so the browser event and its
 * server-side twin must carry the same value.
 *
 * Prefer an id the server already owns - an order id, a payment intent id, a
 * lead id - and prefer to learn it FROM the server rather than minting one here:
 *
 *   1. the server completes the business action and knows the record's id;
 *   2. it returns that id (AJAX) or renders it into the confirmation page;
 *   3. it sends the Conversions API event with that id;
 *   4. the browser fires the Pixel event with the same id.
 *
 * `createEventId()` is the fallback for flows where no such id exists at the
 * moment the browser must act. Mint it once, send it to the server with the
 * request, and never regenerate it - a retry that re-mints is a second
 * conversion.
 */
export function createEventId(): string {
  const cryptoRef = globalThis.crypto;

  if (typeof cryptoRef?.randomUUID === 'function') {
    return cryptoRef.randomUUID();
  }

  if (typeof cryptoRef?.getRandomValues === 'function') {
    const bytes = cryptoRef.getRandomValues(new Uint8Array(16));
    // RFC 4122 version 4.
    bytes[6] = (bytes[6]! & 0x0f) | 0x40;
    bytes[8] = (bytes[8]! & 0x3f) | 0x80;
    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');

    return [
      hex.slice(0, 8),
      hex.slice(8, 12),
      hex.slice(12, 16),
      hex.slice(16, 20),
      hex.slice(20),
    ].join('-');
  }

  // Math.random() is deliberately NOT used as a further fallback. A weak id can
  // collide, and a collision silently merges two people's conversions.
  throw new OpenAIAdsError(
    'Cannot generate an event id: this browser exposes no Web Crypto. ' +
      'Supply a stable business id from the server instead.',
  );
}

/**
 * Rejects an unusable id rather than letting an empty string become a
 * deduplication key that matches everything.
 */
export function assertUsableEventId(id: string): string {
  const trimmed = id.trim();

  if (trimmed === '') {
    throw new OpenAIAdsError('eventId must be a non-empty string.');
  }

  return trimmed;
}
