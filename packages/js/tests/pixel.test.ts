/**
 * @vitest-environment jsdom
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { OpenAIAdsPixel } from '../src/pixel.js';
import { SDK_URL } from '../src/spec.js';

type Call = unknown[];

function calls(): Call[] {
  return (globalThis.oaiq?.q ?? []) as Call[];
}

function commands(name: string): Call[] {
  return calls().filter((call) => call[0] === name);
}

let pixel: OpenAIAdsPixel;
let errors: Error[];

beforeEach(() => {
  document.head.innerHTML = '';
  document.body.innerHTML = '';
  globalThis.oaiq = undefined;
  errors = [];
  pixel = new OpenAIAdsPixel();
  pixel.configure({ onError: (error) => errors.push(error) });
});

describe('loading the official SDK', () => {
  it('injects the documented loader exactly once', () => {
    pixel.init({ pixelId: 'px-1' });
    pixel.init({ pixelId: 'px-2' });

    const scripts = [...document.querySelectorAll(`script[src="${SDK_URL}"]`)];

    expect(scripts).toHaveLength(1);
    expect(scripts[0]?.getAttribute('src')).toBe(SDK_URL);
    expect((scripts[0] as HTMLScriptElement).async).toBe(true);
  });

  it('does not bundle or vendor the SDK - it loads OpenAI\'s CDN script', () => {
    pixel.init({ pixelId: 'px-1' });

    expect(SDK_URL).toBe('https://bzrcdn.openai.com/sdk/oaiq.min.js');
  });

  it('queues commands before the script arrives so nothing is lost', () => {
    pixel.init({ pixelId: 'px-1' });
    pixel.track('page_viewed');

    expect(commands('init')).toHaveLength(1);
    expect(commands('measure')).toHaveLength(1);
  });

  it('reports a failed script load without throwing', () => {
    pixel.init({ pixelId: 'px-1' });

    document.querySelector(`script[src="${SDK_URL}"]`)?.dispatchEvent(new Event('error'));

    expect(errors[0]?.message).toMatch(/failed to load/);
  });
});

describe('init', () => {
  it('requires a pixel id on the first call', () => {
    pixel.init({});

    expect(errors[0]?.message).toMatch(/requires a pixelId/);
    expect(calls()).toHaveLength(0);
  });

  it('skips a duplicate init for the same pixel with no new user data', () => {
    pixel.init({ pixelId: 'px-1' });
    pixel.init({ pixelId: 'px-1' });

    expect(commands('init')).toHaveLength(1);
  });

  it('allows re-init with user data, which is how identity is attached', () => {
    pixel.init({ pixelId: 'px-1' });
    pixel.init({ user: { email_sha256: 'a'.repeat(64) } });

    const initCalls = commands('init');
    expect(initCalls).toHaveLength(2);
    expect(initCalls[1]?.[1]).toEqual({ user: { email_sha256: 'a'.repeat(64) } });
  });

  it('initializes each pixel separately on a multi-pixel page', () => {
    pixel.init({ pixelId: 'px-1' });
    pixel.init({ pixelId: 'px-2' });

    expect(pixel.initializedPixelIds()).toEqual(['px-1', 'px-2']);
    expect(commands('init')).toHaveLength(2);
  });
});

describe('track', () => {
  beforeEach(() => {
    pixel.init({ pixelId: 'px-1' });
  });

  it('sends the event name, the shape-correct data and the options', () => {
    pixel.track('lead_created', undefined, { eventId: 'lead_88213' });

    expect(commands('measure')[0]).toEqual([
      'measure',
      'lead_created',
      { type: 'customer_action' },
      { event_id: 'lead_88213' },
    ]);
  });

  it('supplies the data shape the event requires', () => {
    pixel.track('order_created', { amount: 2599, currency: 'EUR' });

    expect(commands('measure')[0]?.[2]).toEqual({
      type: 'contents',
      amount: 2599,
      currency: 'EUR',
    });
  });

  it('does not hand-pass fields the SDK supplies itself', () => {
    pixel.track('lead_created', undefined, { eventId: 'x' });

    const data = commands('measure')[0]?.[2] as Record<string, unknown>;
    const options = commands('measure')[0]?.[3] as Record<string, unknown>;

    // The SDK adds source_url, timestamps events, batches, and captures oppref.
    for (const key of ['source_url', 'timestamp_ms', 'oppref', 'action_source']) {
      expect(data).not.toHaveProperty(key);
      expect(options).not.toHaveProperty(key);
    }
  });

  it('refuses a Conversions API only event', () => {
    pixel.track('app_installed');

    expect(errors[0]?.message).toMatch(/cannot be sent from the browser/);
    expect(commands('measure')).toHaveLength(0);
  });

  it('rejects a major-unit amount and suggests the minor unit', () => {
    pixel.track('order_created', { amount: 25.99, currency: 'EUR' });

    expect(errors[0]?.message).toMatch(/Did you mean 2599\?/);
    expect(commands('measure')).toHaveLength(0);
  });

  it('requires a currency when an amount is present', () => {
    pixel.track('order_created', { amount: 2599 });

    expect(errors[0]?.message).toMatch(/currency is required when amount is present/);
  });

  it('uppercases a currency and rejects a malformed one', () => {
    pixel.track('order_created', { amount: 2599, currency: 'eur' });
    expect((commands('measure')[0]?.[2] as Record<string, unknown>)['currency']).toBe('EUR');

    pixel.track('order_created', { amount: 2599, currency: 'EURO' });
    expect(errors[0]?.message).toMatch(/ISO 4217 alpha-3/);
  });

  it('rejects an empty event id rather than letting it become a matching key', () => {
    pixel.track('lead_created', undefined, { eventId: '   ' });

    expect(errors[0]?.message).toMatch(/non-empty/);
  });

  it('refuses to track before init', () => {
    const fresh = new OpenAIAdsPixel();
    const captured: Error[] = [];
    fresh.configure({ onError: (error) => captured.push(error) });

    fresh.track('lead_created');

    expect(captured[0]?.message).toMatch(/init must be called before/);
  });

  it('never throws at the caller, so a checkout cannot be broken by measurement', () => {
    const unconfigured = new OpenAIAdsPixel();

    expect(() => unconfigured.track('app_installed')).not.toThrow();
    expect(() => unconfigured.trackCustom('')).not.toThrow();
    expect(() => unconfigured.init({})).not.toThrow();
  });
});

describe('multiple pixels', () => {
  beforeEach(() => {
    pixel.init({ pixelId: 'px-1' });
    pixel.init({ pixelId: 'px-2' });
  });

  /**
   * The documented behaviour of `measure` is to broadcast to every pixel
   * initialized at the time of the call. That is preserved, because changing it
   * would make this wrapper lie about the SDK - but on a multi-pixel page it is
   * rarely what the caller intended, so it is surfaced.
   */
  it('broadcasts by default and warns about it in debug', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    pixel.init({ pixelId: 'px-1', debug: true, user: { country: 'RO' } });

    pixel.track('lead_created');

    expect(commands('measure')).toHaveLength(1);
    expect(warn.mock.calls.some((call) => String(call[1]).includes('broadcast to 2'))).toBe(true);
    warn.mockRestore();
  });

  it('routes to one pixel with measureSingle when a target is given', () => {
    pixel.track('lead_created', undefined, { pixelId: 'px-2', eventId: 'lead_1' });

    expect(commands('measure')).toHaveLength(0);
    expect(commands('measureSingle')[0]).toEqual([
      'measureSingle',
      'px-2',
      'lead_created',
      { type: 'customer_action' },
      { event_id: 'lead_1' },
    ]);
  });

  it('refuses a target that was never initialized', () => {
    pixel.track('lead_created', undefined, { pixelId: 'px-9' });

    expect(errors[0]?.message).toMatch(/has not been initialized/);
    expect(commands('measureSingle')).toHaveLength(0);
  });
});

describe('custom events', () => {
  beforeEach(() => {
    pixel.init({ pixelId: 'px-1' });
  });

  it('sends the custom shape and the name in options', () => {
    pixel.trackCustom('whatsapp_lead', undefined, { eventId: 'w_1' });

    expect(commands('measure')[0]).toEqual([
      'measure',
      'custom',
      { type: 'custom' },
      { event_id: 'w_1', custom_event_name: 'whatsapp_lead' },
    ]);
  });

  it.each([['_lead'], ['lead_'], ['whatsapp lead'], ['lead!'], ['a'.repeat(65)], ['']])(
    'rejects the invalid custom name %j',
    (name) => {
      pixel.trackCustom(name);

      expect(errors).toHaveLength(1);
      expect(commands('measure')).toHaveLength(0);
    },
  );

  it('refuses a custom name that reuses a standard event name', () => {
    pixel.trackCustom('order_created');

    expect(errors[0]?.message).toMatch(/must not reuse the standard event name/);
  });

  it('rejects a custom name on a standard event', () => {
    pixel.track('lead_created', undefined, { customEventName: 'nope' });

    expect(errors[0]?.message).toMatch(/only valid for the "custom" event/);
  });
});

describe('consent', () => {
  it('passes the decision to the SDK', () => {
    pixel.consent(true);
    pixel.init({ pixelId: 'px-1' });

    expect(commands('consent')[0]).toEqual(['consent', true]);
  });

  it('does not load the SDK when consent is denied', () => {
    pixel.consent(false);
    pixel.init({ pixelId: 'px-1' });

    expect(document.querySelector(`script[src="${SDK_URL}"]`)).toBeNull();
  });

  /** A refused consent is a normal outcome, not an error. */
  it('makes tracking a silent no-op when consent is denied', () => {
    pixel.init({ pixelId: 'px-1' });
    pixel.consent(false);

    pixel.track('lead_created');

    expect(commands('measure')).toHaveLength(0);
    expect(errors).toHaveLength(0);
  });

  it('distinguishes opt_out from consent - opt_out still sends the event', () => {
    pixel.init({ pixelId: 'px-1' });

    pixel.track('lead_created', undefined, { optOut: true });

    expect((commands('measure')[0]?.[3] as Record<string, unknown>)['opt_out']).toBe(true);
  });
});
