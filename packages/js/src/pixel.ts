import { OpenAIAdsError } from './errors.js';
import { assertUsableEventId } from './eventId.js';
import { CUSTOM_EVENT_NAME_PATTERN, DATA_SHAPES, PIXEL_SUPPORTED, SDK_URL, isEventName } from './spec.js';
import type { EventName } from './spec.js';
import type { DataFor, EventData, InitConfig, ToolkitOptions, TrackOptions } from './types.js';

type OaiqQueue = ((...args: unknown[]) => void) & { q?: unknown[] };

declare global {
  var oaiq: OaiqQueue | undefined;
}

/**
 * A typed, consent-aware wrapper over OpenAI's official browser SDK.
 *
 * It does NOT reimplement the transport. The official SDK is a script on
 * OpenAI's CDN plus a global command queue, and this wrapper loads that script
 * and speaks to that queue. Everything the SDK owns - batching, timestamps,
 * `source_url`, capturing `oppref` into the `__oppref` cookie - is left to it.
 *
 * Nothing here throws at the caller. A measurement failure must never break a
 * checkout or a form submission, so errors go to `onError`.
 */
class OpenAIAdsPixel {
  private readonly pixelIds = new Set<string>();

  private debug = false;

  private consentGranted = true;

  private scriptInjected = false;

  private onError: (error: Error) => void = () => {};

  /**
   * Configure error reporting. Optional; call before `init` to catch setup
   * problems.
   */
  configure(options: ToolkitOptions): void {
    if (options.onError !== undefined) {
      this.onError = options.onError;
    }
  }

  /**
   * Record the visitor's consent decision.
   *
   * Must be called before `init` to take effect on the SDK, per its
   * documentation. When consent is denied this wrapper also stops emitting
   * events of its own accord - silently, because a refused consent is a normal
   * outcome and not an error.
   */
  consent(granted: boolean): void {
    this.consentGranted = granted;
    // Recorded before init so the SDK sees the decision first, as documented.

    this.safely(() => {
      // The queue is created without injecting the script, so a denial can be
      // recorded on a page that never loads the SDK, and a grant is replayed
      // ahead of init once the script arrives.
      this.ensureQueue();
      this.queue()('consent', granted);
    });
  }

  /**
   * Load the SDK and initialize a Pixel ID.
   *
   * Safe to call more than once, which is required rather than merely tolerated:
   * the documented way to attach identity once a visitor becomes known is to
   * call init again with `user`. Re-initializing the same Pixel ID with no new
   * user data is skipped, which is the guard against the common mistake of
   * initializing in both a root layout and a page.
   *
   * On a page with several pixels, call this once per Pixel ID.
   */
  init(config: InitConfig = {}): void {
    this.safely(() => {
      if (config.debug !== undefined) {
        this.debug = config.debug;
      }

      const pixelId = config.pixelId?.trim();

      if (pixelId === undefined || pixelId === '') {
        if (this.pixelIds.size === 0) {
          throw new OpenAIAdsError('init requires a pixelId on the first call.');
        }
      }

      if (!this.consentGranted) {
        // Do not load a measurement SDK the visitor has refused.
        this.warn('Consent is denied; the Pixel SDK was not loaded.');

        return;
      }

      this.load();

      if (pixelId !== undefined && pixelId !== '' && this.pixelIds.has(pixelId) && config.user === undefined) {
        this.warn(
          `Pixel "${pixelId}" is already initialized and no new user data was supplied; ` +
            'skipping. Initializing twice usually means a layout and a page both call init.',
        );

        return;
      }

      const payload: Record<string, unknown> = {};

      if (pixelId !== undefined && pixelId !== '') {
        payload['pixelId'] = pixelId;
        this.pixelIds.add(pixelId);
      }

      if (this.debug) {
        payload['debug'] = true;
      }

      if (config.user !== undefined) {
        payload['user'] = config.user;
      }

      this.queue()('init', payload);
    });
  }

  /**
   * Emit a standard event at a confirmed conversion boundary.
   *
   * Fire after the action has succeeded - after payment is confirmed, after the
   * lead is accepted - never on a button click, unless the click genuinely is
   * the conversion.
   */
  track<N extends EventName>(name: N, data?: Partial<DataFor<N>>, options: TrackOptions = {}): void {
    this.safely(() => {
      this.emit(name, data, options);
    });
  }

  /**
   * Emit a custom event.
   *
   * Use only where no standard event describes the action. The same name must be
   * used on the Conversions API side or the two will not deduplicate.
   */
  trackCustom(
    customEventName: string,
    data?: Partial<Omit<EventData, 'type'>>,
    options: Omit<TrackOptions, 'customEventName'> = {},
  ): void {
    this.safely(() => {
      this.emit('custom', data, { ...options, customEventName });
    });
  }

  /** Pixel IDs initialized so far. Exposed for diagnostics and tests. */
  initializedPixelIds(): string[] {
    return [...this.pixelIds];
  }

  /** Test seam. Not part of the public API. */
  reset(): void {
    this.pixelIds.clear();
    this.debug = false;
    this.consentGranted = true;
    this.scriptInjected = false;
    this.onError = () => {};
  }

  /**
   * `name` is a string rather than an EventName on purpose.
   *
   * TypeScript proves the caller passed a valid one; JavaScript proves nothing,
   * and this package is published for both. The check below is what a plain-JS
   * caller gets instead of a compile error.
   */
  private emit(name: string, data: Partial<EventData> | undefined, options: TrackOptions): void {
    if (!isEventName(name)) {
      throw new OpenAIAdsError(`"${name}" is not a supported event name.`);
    }

    if (!PIXEL_SUPPORTED[name]) {
      throw new OpenAIAdsError(
        `"${name}" is a Conversions API event and cannot be sent from the browser. ` +
          'Send it server-side.',
      );
    }

    if (this.pixelIds.size === 0) {
      throw new OpenAIAdsError(`init must be called before tracking "${name}".`);
    }

    if (!this.consentGranted) {
      // Silent by design: a refused consent is an expected outcome.
      return;
    }

    const customEventName = this.validateCustomEventName(name, options.customEventName);
    const payload = this.buildData(name, data);
    const sdkOptions = this.buildOptions(options, customEventName);
    const target = options.pixelId?.trim();

    if (target !== undefined && target !== '') {
      if (!this.pixelIds.has(target)) {
        throw new OpenAIAdsError(`Pixel "${target}" has not been initialized.`);
      }

      this.queue()('measureSingle', target, name, payload, sdkOptions);

      return;
    }

    if (this.pixelIds.size > 1) {
      // `measure` goes to every pixel initialized at the time of the call. That
      // is the SDK's documented behaviour and is preserved here, but on a
      // multi-pixel page it is usually not what the caller meant.
      this.warn(
        `This event was broadcast to ${this.pixelIds.size} initialized pixels. ` +
          'Pass options.pixelId to send it to one.',
      );
    }

    this.queue()('measure', name, payload, sdkOptions);
  }

  private buildData(name: EventName, data: Partial<EventData> | undefined): EventData {
    const shape = DATA_SHAPES[name];
    const merged = { ...data, type: shape } as EventData;

    if (merged.amount !== undefined) {
      if (!Number.isInteger(merged.amount)) {
        const suggestion = Math.round(merged.amount * 100);

        throw new OpenAIAdsError(
          `amount must be an integer in the currency's minor unit; got ${merged.amount}. ` +
            `Did you mean ${suggestion}?`,
        );
      }

      if (merged.currency === undefined) {
        throw new OpenAIAdsError('currency is required when amount is present.');
      }
    }

    if (merged.currency !== undefined && !/^[A-Za-z]{3}$/.test(merged.currency)) {
      throw new OpenAIAdsError(
        `currency must be an ISO 4217 alpha-3 code such as "EUR"; got "${merged.currency}".`,
      );
    }

    if (merged.currency !== undefined) {
      merged.currency = merged.currency.toUpperCase();
    }

    return merged;
  }

  private buildOptions(
    options: TrackOptions,
    customEventName: string | undefined,
  ): Record<string, unknown> {
    const sdkOptions: Record<string, unknown> = {};

    if (options.eventId !== undefined) {
      sdkOptions['event_id'] = assertUsableEventId(options.eventId);
    }

    if (customEventName !== undefined) {
      sdkOptions['custom_event_name'] = customEventName;
    }

    if (options.optOut !== undefined) {
      sdkOptions['opt_out'] = options.optOut;
    }

    return sdkOptions;
  }

  private validateCustomEventName(name: EventName, customEventName?: string): string | undefined {
    if (name !== 'custom') {
      if (customEventName !== undefined) {
        throw new OpenAIAdsError(
          `customEventName is only valid for the "custom" event; "${name}" is a standard event.`,
        );
      }

      return undefined;
    }

    if (customEventName === undefined || customEventName === '') {
      throw new OpenAIAdsError('customEventName is required for a custom event.');
    }

    if (!CUSTOM_EVENT_NAME_PATTERN.test(customEventName)) {
      throw new OpenAIAdsError(
        'customEventName must be 1-64 characters of letters, digits, underscores or dashes, ' +
          `starting and ending with a letter or digit; got "${customEventName}".`,
      );
    }

    if (isEventName(customEventName)) {
      throw new OpenAIAdsError(
        `customEventName must not reuse the standard event name "${customEventName}".`,
      );
    }

    return customEventName;
  }

  /**
   * Create the global command queue.
   *
   * This is the first half of the documented loader snippet: define the queue
   * synchronously so commands issued before the script arrives are replayed in
   * order. Kept separate from injecting the script so that `consent(false)` can
   * be recorded on a page where the SDK is never loaded at all.
   */
  private ensureQueue(): void {
    if (globalThis.oaiq !== undefined) {
      return;
    }

    const queue: OaiqQueue = function (...args: unknown[]): void {
      queue.q?.push(args);
    };
    queue.q = [];
    globalThis.oaiq = queue;
  }

  /** The second half of the loader snippet: fetch the official SDK, once. */
  private load(): void {
    if (typeof document === 'undefined') {
      throw new OpenAIAdsError('The Pixel requires a browser document; it cannot run server-side.');
    }

    this.ensureQueue();

    if (this.scriptInjected) {
      return;
    }

    this.scriptInjected = true;

    const script = document.createElement('script');
    script.async = true;
    script.src = SDK_URL;
    script.addEventListener('error', () => {
      this.onError(
        new OpenAIAdsError('The OpenAI Ads Pixel SDK failed to load; events were not delivered.'),
      );
    });

    const first = document.getElementsByTagName('script')[0];

    if (first?.parentNode) {
      first.parentNode.insertBefore(script, first);
    } else {
      (document.head ?? document.documentElement).appendChild(script);
    }
  }

  private queue(): OaiqQueue {
    const queue = globalThis.oaiq;

    if (queue === undefined) {
      throw new OpenAIAdsError('The Pixel SDK is not loaded; call init first.');
    }

    return queue;
  }

  /**
   * The reason nothing here throws at the caller.
   *
   * A measurement mistake on a checkout page must not take the checkout with it,
   * so failures are reported and swallowed. Supply `onError` to route them into
   * your own logging.
   */
  private safely(operation: () => void): void {
    try {
      operation();
    } catch (error) {
      const wrapped =
        error instanceof Error ? error : new OpenAIAdsError('Unknown failure', { cause: error });

      this.onError(wrapped);

      if (this.debug) {
        console.warn('[openai-ads]', wrapped.message);
      }
    }
  }

  private warn(message: string): void {
    if (this.debug) {
      console.warn('[openai-ads]', message);
    }
  }
}

export { OpenAIAdsPixel };

/** The shared instance. A page has one Pixel SDK, so it has one of these. */
export const OpenAIAds = new OpenAIAdsPixel();
