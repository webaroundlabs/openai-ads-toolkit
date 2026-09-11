{{--
    The OpenAI Ads Measurement Pixel.

    Render in <head>, as early as possible:  @openaiAdsPixel
    With identity once the visitor is known: @openaiAdsPixel(['email' => $user->email])

    Only ever emits the PUBLIC Pixel ID. The Conversions API key is not
    available to this view and must never be rendered into a page.

    The directive takes RAW values and hashes them before this view is reached,
    so a raw email address never appears in page source. What arrives here is
    already a map of digests.
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
