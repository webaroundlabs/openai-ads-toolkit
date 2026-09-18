<?php

declare(strict_types=1);

namespace WebaroundLabs\OpenAIAds\WordPress\Admin;

// Loaded by WordPress through Composer's autoloader. A direct request for this
// file would parse a class whose parents are not loaded, and a fatal error
// discloses the installation path.
defined('ABSPATH') || exit;

/**
 * Suggested privacy policy text, for the site owner to adapt.
 *
 * WordPress asks every plugin that discloses data to a third party to supply
 * wording for Tools -> Privacy, and this plugin discloses to one by definition:
 * that is what measuring a conversion is. The site owner is the data controller
 * and writes their own policy, so this is a draft of the facts they need - which
 * service, what reaches it, and what does not - never a claim of compliance.
 *
 * It states only what the code does. Anything here that stops being true is a
 * bug of the same kind as a wrong hash.
 *
 * The text domain is spelled out in every call rather than held in a variable:
 * the translation tooling reads these as literals and sees nothing else.
 */
final class PrivacyPolicy
{
    public static function register(): void
    {
        \add_action('admin_init', [self::class, 'add']);
    }

    public static function add(): void
    {
        // The tutorial paragraph is addressed to the site owner and is left
        // behind when the text is copied into the policy; everything after
        // "Suggested text" is the draft itself.
        $content =
            '<p class="privacy-policy-tutorial">'
            . \esc_html__(
                'This plugin sends advertising conversion data to OpenAI, and only once you have entered a Pixel ID or a Conversions API key. Adapt the wording below to the events your site actually measures, and to the consent mechanism you use.',
                'conversion-tracking-for-openai-ads',
            )
            . '</p>'

            . '<strong class="privacy-policy-tutorial">'
            . \esc_html__('Suggested text:', 'conversion-tracking-for-openai-ads')
            . '</strong> '

            . '<p>'
            . \esc_html__(
                'We use OpenAI Ads to measure the effectiveness of our advertising. When you visit this site, the OpenAI Ads Measurement Pixel may load in your browser and report the pages you view and the actions you complete - such as placing an order or submitting a form - to OpenAI. Your IP address and browser user agent reach OpenAI as part of those requests. The Pixel stores two first-party cookies, __oppref and __obref, which record which advertisement you arrived from.',
                'conversion-tracking-for-openai-ads',
            )
            . '</p>'

            . '<p>'
            . \esc_html__(
                'We also send the same conversions from our own server through the OpenAI Conversions API. Where you have given us your email address, telephone number, name or address - for example by placing an order - these are converted into irreversible SHA-256 hashes before they leave our server, so OpenAI can recognise a returning customer without receiving the values themselves. Commerce events also carry the items concerned: product name, identifier, quantity, price and any variation such as size or colour.',
                'conversion-tracking-for-openai-ads',
            )
            . '</p>'

            . '<p>'
            . \esc_html__(
                'Both halves of this measurement carry the same event identifier, so a conversion seen in the browser and on our server is counted once rather than twice.',
                'conversion-tracking-for-openai-ads',
            )
            . '</p>'

            . '<p>'
            . \esc_html__(
                'We do not send OpenAI your raw email address, telephone number or name, and we remove query strings from page addresses before they are reported.',
                'conversion-tracking-for-openai-ads',
            )
            . '</p>'

            . '<p>'
            . sprintf(
                /* translators: %s: link to OpenAI's privacy policy. */
                \esc_html__(
                    'What OpenAI does with this data is described in its privacy policy: %s',
                    'conversion-tracking-for-openai-ads',
                ),
                '<a href="https://openai.com/policies/privacy-policy/">https://openai.com/policies/privacy-policy/</a>',
            )
            . '</p>';

        \wp_add_privacy_policy_content(
            \__('Conversion Tracking for OpenAI Ads', 'conversion-tracking-for-openai-ads'),
            \wp_kses_post($content),
        );
    }
}
