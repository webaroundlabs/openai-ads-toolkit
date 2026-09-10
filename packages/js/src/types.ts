import type { EventName } from './spec.js';

/** An item inside a `contents` array. */
export interface Content {
  id?: string;
  name?: string;
  content_type?: string;
  quantity?: number;
  amount?: number;
  currency?: string;
  // `group_id` and `variant_dict` are Conversions API only and are deliberately
  // absent here: the Pixel would accept and discard them.
}

interface BaseData {
  /** Integer, in the currency's minor unit. 1299, never 12.99. */
  amount?: number;
  /** ISO 4217 alpha-3. Required whenever `amount` is present. */
  currency?: string;
}

export interface ContentsData extends BaseData {
  type: 'contents';
  contents?: Content[];
}

export interface CustomerActionData extends BaseData {
  type: 'customer_action';
  // No `contents`, no `plan_id`: the shape does not accept them.
}

export interface PlanEnrollmentData extends BaseData {
  type: 'plan_enrollment';
  plan_id?: string;
  contents?: Content[];
}

export interface CustomData extends BaseData {
  type: 'custom';
  plan_id?: string;
  contents?: Content[];
  [key: string]: unknown;
}

export type EventData = ContentsData | CustomerActionData | PlanEnrollmentData | CustomData;

/** Maps an event name to the only data shape it accepts. */
export type DataFor<N extends EventName> = Extract<EventData, { type: DataShapeOf<N> }>;

type DataShapeOf<N extends EventName> = N extends
  | 'page_viewed'
  | 'contents_viewed'
  | 'items_added'
  | 'checkout_started'
  | 'order_created'
  ? 'contents'
  : N extends 'lead_created' | 'registration_completed' | 'appointment_scheduled'
    ? 'customer_action'
    : N extends 'subscription_created' | 'trial_started'
      ? 'plan_enrollment'
      : 'custom';

/**
 * Identity for the Pixel: singular keys, scalar values.
 *
 * The Conversions API wants the same digests under plural keys with array
 * values. Producing this shape is `hashUser()`'s job; do not hand-build it from
 * a Conversions API payload.
 */
export interface PixelUser {
  email_sha256?: string;
  phone_number_sha256?: string;
  external_id_sha256?: string;
  first_name_sha256?: string;
  last_name_sha256?: string;
  country?: string;
  city?: string;
  region?: string;
  postal_code?: string;
}

/** Raw identity, normalized and hashed in the browser by `hashUser()`. */
export interface RawUser {
  email?: string;
  phone?: string;
  externalId?: string;
  firstName?: string;
  lastName?: string;
  country?: string;
  city?: string;
  region?: string;
  postalCode?: string;
}

export interface InitConfig {
  /** Required on the first call. Subsequent calls may omit it on a single-pixel page. */
  pixelId?: string;
  /** Turns on the SDK's own debug output and this wrapper's warnings. */
  debug?: boolean;
  /** Already-hashed identity. Use `hashUser()` to produce it. */
  user?: PixelUser;
}

export interface TrackOptions {
  /**
   * Ties this browser event to its server-side twin. Both sides must use the
   * same Pixel ID, event name and id, or the conversion is counted twice.
   */
  eventId?: string;
  /** Required when the event is `custom`. */
  customEventName?: string;
  /** Excludes the event from personalization. NOT a consent gate. */
  optOut?: boolean;
  /**
   * Send to one Pixel ID instead of every initialized one.
   *
   * Without this the SDK broadcasts to every pixel initialized at the time of
   * the call, which double-counts on a page running more than one pixel.
   */
  pixelId?: string;
}

export interface ToolkitOptions {
  /**
   * Called instead of throwing. Measurement must never break the page, so every
   * failure - a validation mistake included - is routed here.
   *
   * Defaults to a `console.warn` when `debug` is on, and to silence otherwise.
   */
  onError?: (error: Error) => void;
}
