/**
 * Every error this package produces.
 *
 * Unlike the PHP core, these are almost never thrown at the caller: on a web
 * page a measurement mistake must not break a checkout, so `track()` routes
 * failures to `onError` instead. The class exists so a host application can
 * recognize them when it supplies its own handler.
 */
export class OpenAIAdsError extends Error {
  override readonly name = 'OpenAIAdsError';

  constructor(message: string, options?: { cause?: unknown }) {
    super(message, options);
  }
}
