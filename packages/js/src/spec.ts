/**
 * The events OpenAI Ads documents.
 *
 * Mirrors `packages/spec/events.json`. `tests/spec-parity.test.ts` asserts this
 * file and the specification agree on the catalogue, the data shapes and Pixel
 * support, so an upstream addition turns this package red rather than letting it
 * drift from the PHP core.
 */

export const EVENT_NAMES = [
  'page_viewed',
  'contents_viewed',
  'items_added',
  'checkout_started',
  'order_created',
  'lead_created',
  'registration_completed',
  'appointment_scheduled',
  'subscription_created',
  'trial_started',
  'custom',
  'app_installed',
  'app_opened',
] as const;

export type EventName = (typeof EVENT_NAMES)[number];

export type DataShape = 'contents' | 'customer_action' | 'plan_enrollment' | 'custom';

export const DATA_SHAPES: Readonly<Record<EventName, DataShape>> = {
  page_viewed: 'contents',
  contents_viewed: 'contents',
  items_added: 'contents',
  checkout_started: 'contents',
  order_created: 'contents',
  lead_created: 'customer_action',
  registration_completed: 'customer_action',
  appointment_scheduled: 'customer_action',
  subscription_created: 'plan_enrollment',
  trial_started: 'plan_enrollment',
  custom: 'custom',
  app_installed: 'customer_action',
  app_opened: 'customer_action',
};

/**
 * Whether the browser Pixel can emit the event at all.
 *
 * `app_installed` and `app_opened` are Conversions API only. Attempting them
 * here is a programming error the wrapper refuses rather than passes to the SDK,
 * which would accept and silently discard them.
 */
export const PIXEL_SUPPORTED: Readonly<Record<EventName, boolean>> = {
  page_viewed: true,
  contents_viewed: true,
  items_added: true,
  checkout_started: true,
  order_created: true,
  lead_created: true,
  registration_completed: true,
  appointment_scheduled: true,
  subscription_created: true,
  trial_started: true,
  custom: true,
  app_installed: false,
  app_opened: false,
};

/** Mirrors `patterns.custom_event_name` in the specification. */
export const CUSTOM_EVENT_NAME_PATTERN =
  /^[A-Za-z0-9]$|^[A-Za-z0-9][A-Za-z0-9_-]{0,62}[A-Za-z0-9]$/;

/** The official SDK. Loaded from OpenAI's CDN; never bundled or vendored. */
export const SDK_URL = 'https://bzrcdn.openai.com/sdk/oaiq.min.js';

export function isEventName(value: string): value is EventName {
  return (EVENT_NAMES as readonly string[]).includes(value);
}
