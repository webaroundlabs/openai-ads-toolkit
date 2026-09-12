___INFO___

{
  "type": "TAG",
  "id": "cvt_temp_public_id",
  "version": 1,
  "securityGroups": [],
  "displayName": "OpenAI Ads - send to your own server",
  "categories": ["ADVERTISING", "CONVERSIONS"],
  "brand": {
    "id": "webaround",
    "displayName": "Webaround"
  },
  "description": "Posts a conversion to the collection endpoint on your own site, which forwards it to the OpenAI Ads Conversions API. Server-side measurement without a server container. Independent community integration, not affiliated with OpenAI.",
  "containerContexts": ["WEB"]
}


___TEMPLATE_PARAMETERS___

[
  {
    "type": "TEXT",
    "name": "endpoint",
    "displayName": "Endpoint URL",
    "simpleValueType": true,
    "valueValidators": [{"type": "NON_EMPTY"}],
    "help": "From Settings \\u2192 OpenAI Ads on your site. Usually https://yoursite.com/wp-json/openai-ads/v1/collect"
  },
  {
    "type": "TEXT",
    "name": "secret",
    "displayName": "Shared secret",
    "simpleValueType": true,
    "valueValidators": [{"type": "NON_EMPTY"}],
    "help": "The secret shown next to the endpoint URL. Store it in a container variable rather than typing it into every tag. It is NOT your Conversions API key - that one never leaves your server."
  },
  {
    "type": "SELECT",
    "name": "eventName",
    "displayName": "Event",
    "selectItems": [
      {"value": "page_viewed", "displayValue": "page_viewed"},
      {"value": "contents_viewed", "displayValue": "contents_viewed"},
      {"value": "items_added", "displayValue": "items_added"},
      {"value": "checkout_started", "displayValue": "checkout_started"},
      {"value": "order_created", "displayValue": "order_created"},
      {"value": "lead_created", "displayValue": "lead_created"},
      {"value": "registration_completed", "displayValue": "registration_completed"},
      {"value": "appointment_scheduled", "displayValue": "appointment_scheduled"},
      {"value": "subscription_created", "displayValue": "subscription_created"},
      {"value": "trial_started", "displayValue": "trial_started"},
      {"value": "custom", "displayValue": "custom"}
    ],
    "simpleValueType": true,
    "defaultValue": "lead_created",
    "help": "app_installed and app_opened are absent: they are reported by a mobile measurement partner, not by a web page."
  },
  {
    "type": "TEXT",
    "name": "customEventName",
    "displayName": "Custom event name",
    "simpleValueType": true,
    "enablingConditions": [{"paramName": "eventName", "paramValue": "custom", "type": "EQUALS"}],
    "help": "1-64 characters: letters, digits, underscores or dashes. Must be identical to the name any other channel uses for this conversion."
  },
  {
    "type": "TEXT",
    "name": "eventId",
    "displayName": "Event ID",
    "simpleValueType": true,
    "valueValidators": [{"type": "NON_EMPTY"}],
    "help": "Required. Use an id the site already owns - an order number, a lead id - and use the SAME one for the browser Pixel, or the conversion is counted twice."
  },
  {
    "type": "GROUP",
    "name": "valueGroup",
    "displayName": "Value",
    "groupStyle": "ZIPPY_OPEN_ON_PARAM",
    "subParams": [
      {
        "type": "TEXT",
        "name": "amount",
        "displayName": "Amount (minor units)",
        "simpleValueType": true,
        "help": "An INTEGER in the currency's minor unit. 12.99 euros is 1299. Yen have no minor unit at all."
      },
      {"type": "TEXT", "name": "currency", "displayName": "Currency", "simpleValueType": true},
      {
        "type": "TEXT",
        "name": "planId",
        "displayName": "Plan ID",
        "simpleValueType": true,
        "help": "Only for subscription_created, trial_started and custom."
      }
    ]
  },
  {
    "type": "GROUP",
    "name": "userGroup",
    "displayName": "User data",
    "groupStyle": "ZIPPY_CLOSED",
    "subParams": [
      {
        "type": "LABEL",
        "name": "userNotice",
        "displayName": "Raw values are sent to YOUR server over HTTPS and hashed there. They never reach OpenAI unhashed, and this tag never hashes anything itself."
      },
      {"type": "TEXT", "name": "email", "displayName": "Email (raw)", "simpleValueType": true},
      {"type": "TEXT", "name": "phone", "displayName": "Phone (raw)", "simpleValueType": true},
      {"type": "TEXT", "name": "firstName", "displayName": "First name (raw)", "simpleValueType": true},
      {"type": "TEXT", "name": "lastName", "displayName": "Last name (raw)", "simpleValueType": true},
      {"type": "TEXT", "name": "externalId", "displayName": "External ID (raw)", "simpleValueType": true},
      {"type": "TEXT", "name": "country", "displayName": "Country (2 letters)", "simpleValueType": true},
      {"type": "TEXT", "name": "city", "displayName": "City", "simpleValueType": true},
      {"type": "TEXT", "name": "region", "displayName": "Region", "simpleValueType": true},
      {"type": "TEXT", "name": "postalCode", "displayName": "Postal code", "simpleValueType": true}
    ]
  }
]


___SANDBOXED_JS_FOR_WEB_TEMPLATE___

// Posts one conversion to the collection endpoint on the site's own server.
//
// Deliberately thin. Everything that can be got wrong - normalizing an email
// before hashing it, the exact shape of the Conversions API payload, the
// freshness window, batching - happens in PHP on the receiving end, where the
// toolkit's tests already cover it. Duplicating any of that here would be a
// fifth copy of rules that must not diverge.
//
// What this tag does NOT carry, on purpose:
//
//   - The Conversions API key. It never leaves the site's server. The secret
//     below only authorizes posting to that site, and a stolen one lets somebody
//     write conversions, not read anything.
//   - source_url, oppref, obref, the client IP, the user agent. The request goes
//     to the site's own origin from the visitor's own browser, so the server sees
//     all five correctly for itself. Sending them from here would mean trusting
//     a value assembled in a page over one the server observed.

const JSON = require('JSON');
const Object = require('Object');
const getUrl = require('getUrl');
const logToConsole = require('logToConsole');
const makeInteger = require('makeInteger');
const makeString = require('makeString');
const sendHttpRequest = require('sendHttpRequest');

function isSet(value) {
  return value !== undefined && value !== null && value !== '';
}

function fail(reason) {
  logToConsole('OpenAI Ads: ' + reason);
  data.gtmOnFailure();
}

if (!isSet(data.eventId)) {
  fail('an event id is required, and must match the one the Pixel uses.');
} else if (data.eventName === 'custom' && !isSet(data.customEventName)) {
  fail('a custom event needs a custom event name.');
} else {
  const body = {
    event: data.eventName,
    event_id: makeString(data.eventId),
    // The page this tag fired on. The server would see its own origin either
    // way, but the PATH is what tells the two apart, and only the page knows it.
    source_url: getUrl('protocol') + '://' + getUrl('host') + getUrl('path')
  };

  if (data.eventName === 'custom') {
    body.custom_event_name = makeString(data.customEventName);
  }

  const eventData = {};

  if (isSet(data.amount)) {
    if (!isSet(data.currency)) {
      fail('an amount needs a currency.');
    }
    // makeInteger, not a cast: "12.99" here means somebody sent major units,
    // and 12 euros reported as 12 cents is worse than a refused tag.
    eventData.amount = makeInteger(data.amount);
    eventData.currency = makeString(data.currency);
  }

  if (isSet(data.planId)) {
    eventData.plan_id = makeString(data.planId);
  }

  if (Object.keys(eventData).length > 0) {
    body.data = eventData;
  }

  const user = {};
  const identity = [
    ['email', data.email],
    ['phone', data.phone],
    ['first_name', data.firstName],
    ['last_name', data.lastName],
    ['external_id', data.externalId],
    ['country', data.country],
    ['city', data.city],
    ['region', data.region],
    ['postal_code', data.postalCode]
  ];

  for (let i = 0; i < identity.length; i++) {
    if (isSet(identity[i][1])) {
      user[identity[i][0]] = makeString(identity[i][1]);
    }
  }

  if (Object.keys(user).length > 0) {
    body.user = user;
  }

  sendHttpRequest(
    makeString(data.endpoint),
    function (statusCode) {
      if (statusCode >= 200 && statusCode < 300) {
        data.gtmOnSuccess();
      } else {
        // 401 means the secret is wrong. 429 means the rate limit was hit.
        // Anything else, look at the diagnostics table on the settings screen.
        logToConsole('OpenAI Ads: the collection endpoint returned ' + statusCode + '.');
        data.gtmOnFailure();
      }
    },
    {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-OpenAI-Ads-Key': makeString(data.secret)
      },
      // Same-origin, so the browser sends the __oppref and __obref cookies and
      // the server reads them itself - which is why this tag does not.
      withCredentials: true,
      timeout: 3000
    },
    JSON.stringify(body)
  );
}


___WEB_PERMISSIONS___

[
  {
    "instance": {
      "key": {"publicId": "send_http", "versionId": "1"},
      "param": [
        {
          "key": "allowedUrls",
          "value": {"type": 1, "string": "specific"}
        },
        {
          "key": "urls",
          "value": {
            "type": 2,
            "listItem": [{"type": 1, "string": "https://*/*"}]
          }
        }
      ]
    },
    "clientAnnotations": {"isEditedByUser": true},
    "isRequired": true
  },
  {
    "instance": {
      "key": {"publicId": "get_url", "versionId": "1"},
      "param": [
        {"key": "urlParts", "value": {"type": 1, "string": "specific"}},
        {"key": "queriesAllowed", "value": {"type": 1, "string": "any"}},
        {
          "key": "urlPartsAllowed",
          "value": {
            "type": 2,
            "listItem": [
              {"type": 1, "string": "protocol"},
              {"type": 1, "string": "host"},
              {"type": 1, "string": "path"}
            ]
          }
        }
      ]
    },
    "clientAnnotations": {"isEditedByUser": true},
    "isRequired": true
  },
  {
    "instance": {
      "key": {"publicId": "logging", "versionId": "1"},
      "param": [{"key": "environments", "value": {"type": 1, "string": "debug"}}]
    },
    "clientAnnotations": {"isEditedByUser": true},
    "isRequired": true
  }
]


___TESTS___

scenarios:
- name: A conversion is posted with the id the Pixel will use
  code: |-
    const mockData = {
      endpoint: 'https://shop.example.com/wp-json/openai-ads/v1/collect',
      secret: 'test-secret',
      eventName: 'order_created',
      eventId: 'wc_1042',
      amount: '2599',
      currency: 'EUR'
    };

    let sent = null;
    mock('sendHttpRequest', function (url, callback, options, body) {
      sent = {url: url, options: options, body: JSON.parse(body)};
      callback(202);
    });

    runCode(mockData);

    assertThat(sent.url).isEqualTo(mockData.endpoint);
    assertThat(sent.options.headers['X-OpenAI-Ads-Key']).isEqualTo('test-secret');
    assertThat(sent.body.event).isEqualTo('order_created');
    assertThat(sent.body.event_id).isEqualTo('wc_1042');
    assertThat(sent.body.data.amount).isEqualTo(2599);
    assertApi('gtmOnSuccess').wasCalled();

- name: Nothing resembling a Conversions API credential is sent
  code: |-
    // The tag has no parameter for one, and the body carries only the event.
    // A credential typed into a web container is published to every visitor and
    // cannot be taken back, so the repository also greps this file for one.
    const mockData = {
      endpoint: 'https://shop.example.com/wp-json/openai-ads/v1/collect',
      secret: 'test-secret',
      eventName: 'lead_created',
      eventId: 'lead_1'
    };

    let sent = null;
    mock('sendHttpRequest', function (url, callback, options, body) {
      sent = {options: options, body: JSON.parse(body)};
      callback(202);
    });

    runCode(mockData);

    assertThat(sent.body.authorization).isUndefined();
    assertThat(sent.options.headers.Authorization).isUndefined();

- name: An event without an id is refused
  code: |-
    const mockData = {
      endpoint: 'https://shop.example.com/wp-json/openai-ads/v1/collect',
      secret: 'test-secret',
      eventName: 'lead_created'
    };

    runCode(mockData);

    assertApi('gtmOnFailure').wasCalled();
    assertApi('sendHttpRequest').wasNotCalled();

- name: A custom event without a name is refused
  code: |-
    const mockData = {
      endpoint: 'https://shop.example.com/wp-json/openai-ads/v1/collect',
      secret: 'test-secret',
      eventName: 'custom',
      eventId: 'quote_881'
    };

    runCode(mockData);

    assertApi('gtmOnFailure').wasCalled();

- name: Raw identity is passed through for the server to hash
  code: |-
    const mockData = {
      endpoint: 'https://shop.example.com/wp-json/openai-ads/v1/collect',
      secret: 'test-secret',
      eventName: 'lead_created',
      eventId: 'lead_1',
      email: ' Ada@Example.COM '
    };

    let sent = null;
    mock('sendHttpRequest', function (url, callback, options, body) {
      sent = JSON.parse(body);
      callback(202);
    });

    runCode(mockData);

    // Unnormalized and unhashed, deliberately: normalizing here would be a
    // fifth copy of rules that must agree byte for byte with four others.
    assertThat(sent.user.email).isEqualTo(' Ada@Example.COM ');

- name: A rejection from the endpoint fails the tag rather than passing silently
  code: |-
    const mockData = {
      endpoint: 'https://shop.example.com/wp-json/openai-ads/v1/collect',
      secret: 'wrong',
      eventName: 'lead_created',
      eventId: 'lead_1'
    };

    mock('sendHttpRequest', function (url, callback) {
      callback(401);
    });

    runCode(mockData);

    assertApi('gtmOnFailure').wasCalled();


___NOTES___

Sends conversions to a collection endpoint on your own site, which forwards them
to the OpenAI Ads Conversions API.

WHY THIS EXISTS

Server-side tagging in GTM normally needs a server container, and Google hosts
those on Google Cloud - a real monthly bill for a site that already has a server.
This tag skips that: it posts to your own site, and your site talks to OpenAI.

What you get:

  - The Conversions API key stays on your server. It is never in a container,
    never in page source, never recoverable by a visitor.
  - The request to OpenAI leaves from your server, so no ad blocker sees it.
  - No extra hosting.

What you do not get, so you can judge it honestly:

  - The first hop is still a browser request. An aggressive blocker can stop it,
    the same way it can stop the Pixel.
  - If your site is down, the event is lost. A real server container retries.

SETUP

  1. In WordPress: Settings -> OpenAI Ads -> Tag manager endpoint. Switch it on
     and save. Copy the URL and the secret.
  2. Put the secret in a GTM constant variable rather than typing it into every
     tag, so rotating it is one edit.
  3. Set the Event ID to something the site already owns - an order number, a
     lead id - and use the SAME value in the Pixel tag for that conversion. That
     is what makes OpenAI count one conversion instead of two.

THE SECRET IS NOT THE API KEY

It authorizes posting to your site and nothing else. Someone who steals it can
record conversions that did not happen, which corrupts the numbers your campaigns
are optimized against - so treat it seriously - but they cannot read anything and
they cannot spend your budget directly. Rotate it from the same settings screen;
anything still using the old one stops working immediately.

WHAT THIS TAG DOES NOT SEND

source_url is sent, because only the page knows its own path. The client IP, the
user agent, and the __oppref and __obref cookies are not: the request goes to
your own origin from the visitor's own browser, so your server observes all four
correctly for itself. A value the server observed beats a value assembled in a
page.

Identity is sent raw, over HTTPS, to your own server, which normalizes and hashes
it there. This tag hashes nothing. Those rules already exist in four places that
must agree byte for byte, and a fifth copy in sandboxed JavaScript is how they
stop agreeing.

Independent community integration. Not created, certified, endorsed or supported
by OpenAI.
