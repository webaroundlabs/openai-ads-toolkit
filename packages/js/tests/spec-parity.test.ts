import { describe, expect, it } from 'vitest';

import { createEventId } from '../src/eventId.js';
import {
  CUSTOM_EVENT_NAME_PATTERN,
  DATA_SHAPES,
  EVENT_NAMES,
  PIXEL_SUPPORTED,
  SDK_URL,
} from '../src/spec.js';
import { hashUser } from '../src/userData.js';
import { loadSpec } from './spec.js';

interface EventsSpec {
  events: Record<string, { data_shape: string; pixel: boolean; capi: boolean }>;
  patterns: Record<string, string>;
  data_shapes: Record<string, unknown>;
}

interface UserSpec {
  fields: Record<
    string,
    {
      capi: { key: string; cardinality: string };
      pixel: { key: string; cardinality: string } | null;
    }
  >;
}

const events = loadSpec<EventsSpec>('events.json');
const users = loadSpec<UserSpec>('user.json');

/**
 * Keeps this runtime honest against `packages/spec`, exactly as the PHP suite
 * does. The specification lives in three places on purpose; these assertions are
 * what make that duplication safe rather than a source of drift.
 */
describe('parity with the shared specification', () => {
  it('has the same event catalogue', () => {
    expect([...EVENT_NAMES].sort()).toEqual(Object.keys(events.events).sort());
  });

  it.each(Object.entries(events.events))('agrees about "%s"', (name, definition) => {
    expect(DATA_SHAPES[name as keyof typeof DATA_SHAPES]).toBe(definition.data_shape);
    expect(PIXEL_SUPPORTED[name as keyof typeof PIXEL_SUPPORTED]).toBe(definition.pixel);
  });

  it('reaches every documented data shape', () => {
    expect([...new Set(Object.values(DATA_SHAPES))].sort()).toEqual(
      Object.keys(events.data_shapes).sort(),
    );
  });

  it('uses the specified custom event name pattern', () => {
    expect(CUSTOM_EVENT_NAME_PATTERN.source).toBe(events.patterns['custom_event_name']);
  });

  /**
   * Parity assertion 3, the browser half: a fully-populated user must emit
   * exactly the keys the spec marks as Pixel-supported, and nothing marked
   * `"pixel": null`.
   */
  it('emits exactly the Pixel key set, and none of the browser-supplied fields', async () => {
    const user = await hashUser({
      email: 'ada@example.com',
      phone: '+40 746 123 456',
      externalId: 'CUST-00042',
      firstName: 'Ion',
      lastName: 'Ștefănescu',
      country: 'RO',
      city: 'București',
      region: 'București',
      postalCode: '010101',
    });

    const expectedKeys = Object.values(users.fields)
      .filter((field) => field.pixel !== null)
      .map((field) => field.pixel!.key);

    expect(Object.keys(user).sort()).toEqual(expectedKeys.sort());

    for (const [logical, field] of Object.entries(users.fields)) {
      if (field.pixel !== null) {
        continue;
      }

      expect(user, `"${logical}" is pixel:null and must not be sent`).not.toHaveProperty(
        field.capi.key,
      );
    }
  });

  it('loads the SDK url the specification records', () => {
    expect(SDK_URL).toBe('https://bzrcdn.openai.com/sdk/oaiq.min.js');
  });
});

describe('createEventId', () => {
  it('produces a version 4 UUID', () => {
    expect(createEventId()).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
    );
  });

  it('does not repeat itself', () => {
    const ids = new Set(Array.from({ length: 500 }, () => createEventId()));

    expect(ids.size).toBe(500);
  });

  it('refuses to invent a weak id when Web Crypto is missing', () => {
    const original = globalThis.crypto;
    Object.defineProperty(globalThis, 'crypto', { value: undefined, configurable: true });

    try {
      // Math.random() is never used as a fallback: a collision silently merges
      // two people's conversions.
      expect(() => createEventId()).toThrow(/no Web Crypto/);
    } finally {
      Object.defineProperty(globalThis, 'crypto', { value: original, configurable: true });
    }
  });
});
