{{--
    The OpenAI Ads Measurement Pixel.

    Render in <head>, as early as possible:  @openaiAdsPixel
    With identity once the visitor is known: @openaiAdsPixel(['email_sha256' => $digest])

    Only ever emits the PUBLIC Pixel ID. The Conversions API key is not
    available to this view and must never be rendered into a page.

    `user` values must already be hashed - see the JavaScript package's
    hashUser(), or hash server-side and pass the digests in. Raw email
    addresses must not be placed in browser code.
--}}
@php
    /** @var \WebaroundLabs\OpenAIAds\Laravel\Measurement $openAiAds */
    $openAiAds = app(\WebaroundLabs\OpenAIAds\Laravel\Measurement::class);
    $openAiAdsPixelId = $openAiAds->pixelId();
    $openAiAdsUser = array_filter($user ?? []);
@endphp
@if ($openAiAds->pixelEnabled() && $openAiAdsPixelId !== null)
<script>
(function (w, d, s, u) {
  if (w.oaiq) return;
  var q = function () { q.q.push(arguments); };
  q.q = [];
  w.oaiq = q;
  var js = d.createElement(s); js.async = true; js.src = u;
  var f = d.getElementsByTagName(s)[0];
  f.parentNode.insertBefore(js, f);
})(window, document, "script", "https://bzrcdn.openai.com/sdk/oaiq.min.js");
oaiq("init", @json(array_merge(['pixelId' => $openAiAdsPixelId], $openAiAdsUser === [] ? [] : ['user' => $openAiAdsUser])));
</script>
@endif
