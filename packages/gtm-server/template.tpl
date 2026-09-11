___INFO___

{
  "type": "TAG",
  "id": "cvt_temp_public_id",
  "version": 1,
  "securityGroups": [],
  "displayName": "OpenAI Ads Conversions API",
  "categories": ["ADVERTISING", "CONVERSIONS"],
  "brand": {
    "id": "webaround",
    "displayName": "Webaround"
  },
  "description": "Sends conversions to the OpenAI Ads Conversions API from a server container. Independent community integration, not affiliated with OpenAI.",
  "containerContexts": ["SERVER"]
}


___TEMPLATE_PARAMETERS___

[
  {
    "type": "TEXT",
    "name": "pixelId",
    "displayName": "Pixel ID",
    "simpleValueType": true,
    "valueValidators": [{"type": "NON_EMPTY"}]
  },
  {
    "type": "TEXT",
    "name": "apiKey",
    "displayName": "Conversions API key",
    "simpleValueType": true,
    "valueValidators": [{"type": "NON_EMPTY"}],
    "help": "Server-side only. Store it in a container variable, never in a web container and never in page source."
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
      {"value": "app_installed", "displayValue": "app_installed"},
      {"value": "app_opened", "displayValue": "app_opened"},
      {"value": "custom", "displayValue": "custom"}
    ],
    "simpleValueType": true,
    "defaultValue": "page_viewed"
  },
  {
    "type": "TEXT",
    "name": "customEventName",
    "displayName": "Custom event name",
    "simpleValueType": true,
    "enablingConditions": [{"paramName": "eventName", "paramValue": "custom", "type": "EQUALS"}]
  },
  {
    "type": "TEXT",
    "name": "eventId",
    "displayName": "Event ID",
    "simpleValueType": true,
    "valueValidators": [{"type": "NON_EMPTY"}],
    "help": "Must be the SAME value the browser Pixel sends for this conversion, or it is counted twice."
  },
  {
    "type": "SELECT",
    "name": "actionSource",
    "displayName": "Action source",
    "selectItems": [
      {"value": "web", "displayValue": "web"},
      {"value": "mobile_app", "displayValue": "mobile_app"},
      {"value": "offline", "displayValue": "offline"},
      {"value": "physical_store", "displayValue": "physical_store"},
      {"value": "phone_call", "displayValue": "phone_call"},
      {"value": "email", "displayValue": "email"},
      {"value": "other", "displayValue": "other"}
    ],
    "simpleValueType": true,
    "defaultValue": "web",
    "help": "app_installed and app_opened require mobile_app."
  },
  {
    "type": "TEXT",
    "name": "sourceUrl",
    "displayName": "Source URL",
    "simpleValueType": true,
    "enablingConditions": [{"paramName": "actionSource", "paramValue": "web", "type": "EQUALS"}],
    "help": "Required for web events. Strip query strings first: they routinely carry email addresses and order keys."
  },
  {
    "type": "TEXT",
    "name": "oppref",
    "displayName": "oppref",
    "simpleValueType": true,
    "help": "Opaque attribution value from the __oppref cookie. Pass it through unchanged; never parse, decode or invent one. Leave blank to read the cookie from the incoming request."
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
        "help": "An INTEGER in the currency's minor unit. 12.99 euros is 1299."
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
    "type": "SIMPLE_TABLE",
    "name": "contents",
    "displayName": "Contents",
    "simpleTableColumns": [
      {"defaultValue": "", "displayName": "id", "name": "id", "type": "TEXT"},
      {"defaultValue": "", "displayName": "group_id", "name": "group_id", "type": "TEXT"},
      {"defaultValue": "", "displayName": "name", "name": "name", "type": "TEXT"},
      {"defaultValue": "product", "displayName": "content_type", "name": "content_type", "type": "TEXT"},
      {"defaultValue": "", "displayName": "quantity", "name": "quantity", "type": "TEXT"},
      {"defaultValue": "", "displayName": "amount (minor)", "name": "amount", "type": "TEXT"}
    ],
    "help": "Not accepted on lead_created, registration_completed or appointment_scheduled: those use the customer_action shape, which has no contents array."
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
        "displayName": "Raw values are normalized and hashed here, in the server container. They are never sent as-is."
      },
      {"type": "TEXT", "name": "email", "displayName": "Email (raw)", "simpleValueType": true},
      {"type": "TEXT", "name": "phone", "displayName": "Phone (raw)", "simpleValueType": true},
      {"type": "TEXT", "name": "externalId", "displayName": "External ID (raw)", "simpleValueType": true},
      {"type": "TEXT", "name": "firstName", "displayName": "First name (raw)", "simpleValueType": true},
      {"type": "TEXT", "name": "lastName", "displayName": "Last name (raw)", "simpleValueType": true},
      {"type": "TEXT", "name": "country", "displayName": "Country", "simpleValueType": true},
      {"type": "TEXT", "name": "city", "displayName": "City", "simpleValueType": true},
      {"type": "TEXT", "name": "region", "displayName": "Region", "simpleValueType": true},
      {"type": "TEXT", "name": "postalCode", "displayName": "Postal code", "simpleValueType": true},
      {
        "type": "TEXT",
        "name": "obref",
        "displayName": "obref",
        "simpleValueType": true,
        "help": "Opaque browser reference from the __obref cookie. Distinct from oppref, and it belongs inside the user object. Leave blank to read the cookie."
      }
    ]
  },
  {
    "type": "GROUP",
    "name": "advancedGroup",
    "displayName": "Advanced",
    "groupStyle": "ZIPPY_CLOSED",
    "subParams": [
      {
        "type": "CHECKBOX",
        "name": "validateOnly",
        "checkboxText": "Validation mode (validate_only)",
        "simpleValueType": true,
        "help": "Check the event against the API without recording it. The honest way to test a container."
      },
      {
        "type": "CHECKBOX",
        "name": "optOut",
        "checkboxText": "Exclude from personalization (opt_out)",
        "simpleValueType": true,
        "help": "NOT a consent gate. It still sends the event."
      },
      {
        "type": "TEXT",
        "name": "integrationSource",
        "displayName": "Integration source",
        "simpleValueType": true,
        "defaultValue": "webaroundlabs-gtm-server"
      }
    ]
  }
]


___SANDBOXED_JS_FOR_SERVER___

// Posts one event to the OpenAI Ads Conversions API.
//
// Identity is normalized and hashed here, using the rules the toolkit pins in
// packages/spec/user.json, so a conversion reported through this container
// produces the same digests as one reported by the PHP or JavaScript packages -
// which is what lets them deduplicate rather than describe two different people.

const JSON = require('JSON');
const Object = require('Object');
const encodeUriComponent = require('encodeUriComponent');
const getAllEventData = require('getAllEventData');
const getCookieValues = require('getCookieValues');
const getTimestampMillis = require('getTimestampMillis');
const logToConsole = require('logToConsole');
const makeInteger = require('makeInteger');
const makeString = require('makeString');
const sendHttpRequest = require('sendHttpRequest');
const sha256Sync = require('sha256Sync');

const ENDPOINT = 'https://bzr.openai.com/v1/events';

const DATA_SHAPES = {
  page_viewed: 'contents',
  contents_viewed: 'contents',
  items_added: 'contents',
  checkout_started: 'contents',
  order_created: 'contents',
  lead_created: 'customer_action',
  registration_completed: 'customer_action',
  appointment_scheduled: 'customer_action',
  app_installed: 'customer_action',
  app_opened: 'customer_action',
  subscription_created: 'plan_enrollment',
  trial_started: 'plan_enrollment',
  custom: 'custom'
};

function fail(message) {
  logToConsole('OpenAI Ads: ' + message);
  data.gtmOnFailure();
}

function isSet(value) {
  return value !== undefined && value !== null && value !== '';
}

function acceptsContents(shape) {
  return shape !== 'customer_action';
}

function acceptsPlanId(shape) {
  return shape === 'plan_enrollment' || shape === 'custom';
}

// --- normalization, matching packages/spec/user.json ----------------------

function hash(value) {
  return sha256Sync(value, {outputEncoding: 'hex'});
}

function normalizeEmail(value) {
  return makeString(value).trim().toLowerCase();
}

// Only the four separators the API documents are removed - whitespace,
// parentheses, periods and hyphens - then a leading '+', then leading zeroes.
// Anything else that is not a digit is deliberately LEFT IN so the caller can
// refuse the value: stripping every non-digit turns "ext. 89" into two more
// digits of a plausible-looking number belonging to nobody. The sandbox has no
// regular expressions, so this walks the string.
function normalizePhone(value) {
  const input = makeString(value).trim();
  let compact = '';

  for (let i = 0; i < input.length; i++) {
    const character = input.charAt(i);
    const isSeparator = character === ' ' || character === '\t' || character === '\n' ||
      character === '\r' || character === '(' || character === ')' ||
      character === '.' || character === '-';

    if (!isSeparator) {
      compact = compact + character;
    }
  }

  if (compact.charAt(0) === '+') {
    compact = compact.substring(1);
  }

  let start = 0;
  while (start < compact.length && compact.charAt(start) === '0') {
    start++;
  }

  return compact.substring(start);
}

function isAllDigits(value) {
  if (value === '') {
    return false;
  }

  for (let i = 0; i < value.length; i++) {
    const character = value.charAt(i);

    if (character < '0' || character > '9') {
      return false;
    }
  }

  return true;
}

// ISO 3166-1 alpha-2, uppercased. Returns '' for anything else, because the API
// drops a country NAME without an error - it would look sent and match nobody.
function normalizeCountry(value) {
  const trimmed = makeString(value).trim();

  if (trimmed.length !== 2) {
    return '';
  }

  for (let i = 0; i < 2; i++) {
    const character = trimmed.charAt(i).toLowerCase();

    if (character < 'a' || character > 'z') {
      return '';
    }
  }

  return trimmed.toUpperCase();
}

// Trim, lowercase, cap at 128 characters - what the API does on receipt. Done
// here too so the value sent is the value stored, and so a conversion reported
// from this container carries the same string as one reported from PHP.
function normalizeCityOrRegion(value) {
  return makeString(value).trim().toLowerCase().substring(0, 128);
}

// Letters, digits, spaces and hyphens, capped at 32 characters. Case is not
// folded: upstream states a lowercase rule for cities and regions, none here.
function normalizePostalCode(value) {
  const input = makeString(value).trim();
  let result = '';

  for (let i = 0; i < input.length; i++) {
    const character = input.charAt(i);
    const lowered = character.toLowerCase();
    const isLetter = lowered >= 'a' && lowered <= 'z';
    const isDigit = character >= '0' && character <= '9';

    if (isLetter || isDigit || character === ' ' || character === '-') {
      result = result + character;
    }
  }

  return result.substring(0, 32).trim();
}

// Lowercase, then whitespace and ASCII punctuation removed, preserving
// non-ASCII characters so a name with diacritics hashes the same here as it
// does in PHP and in the browser.
function normalizeName(value) {
  const input = makeString(value).trim().toLowerCase();
  let result = '';

  for (let i = 0; i < input.length; i++) {
    const character = input.charAt(i);
    const code = character.charCodeAt(0);
    const isAsciiPunctuation = (code >= 33 && code <= 47) || (code >= 58 && code <= 64) ||
      (code >= 91 && code <= 96) || (code >= 123 && code <= 126);
    const isWhitespace = character === ' ' || character === '\t' || character === '\n' || character === '\r';

    if (!isAsciiPunctuation && !isWhitespace) {
      result = result + character;
    }
  }

  return result;
}

function buildUser() {
  const user = {};

  if (isSet(data.email)) {
    const email = normalizeEmail(data.email);
    if (email !== '') user.emails_sha256 = [hash(email)];
  }

  if (isSet(data.phone)) {
    const phone = normalizePhone(data.phone);
    if (isAllDigits(phone) && phone.length >= 8 && phone.length <= 15) {
      user.phone_numbers_sha256 = [hash(phone)];
    } else {
      // The number itself is deliberately absent from this message.
      logToConsole('OpenAI Ads: phone skipped, it is not 8-15 digits after normalization.');
    }
  }

  // External ids keep their case, unlike every other hashed field.
  if (isSet(data.externalId)) {
    const externalId = makeString(data.externalId).trim();
    if (externalId !== '') user.external_ids_sha256 = [hash(externalId)];
  }

  if (isSet(data.firstName)) {
    const firstName = normalizeName(data.firstName);
    if (firstName !== '') user.first_names_sha256 = [hash(firstName)];
  }

  if (isSet(data.lastName)) {
    const lastName = normalizeName(data.lastName);
    if (lastName !== '') user.last_names_sha256 = [hash(lastName)];
  }

  // Geographic values are lists on the wire and are never hashed - but they are
  // normalized, because the API documents a rule for each and drops a value that
  // does not satisfy it without reporting anything.
  if (isSet(data.country)) {
    const country = normalizeCountry(data.country);
    if (country !== '') {
      user.countries = [country];
    } else {
      logToConsole('OpenAI Ads: country skipped, it is not a two-letter code such as "US".');
    }
  }

  if (isSet(data.city)) {
    const city = normalizeCityOrRegion(data.city);
    if (city !== '') user.cities = [city];
  }

  if (isSet(data.region)) {
    const region = normalizeCityOrRegion(data.region);
    if (region !== '') user.regions = [region];
  }

  if (isSet(data.postalCode)) {
    const postalCode = normalizePostalCode(data.postalCode);
    if (postalCode !== '') user.postal_codes = [postalCode];
  }

  // obref is user-level and opaque. oppref is a different field on the event
  // itself; conflating the two silently breaks matching.
  const obref = isSet(data.obref) ? data.obref : readCookie('__obref');
  if (isSet(obref)) user.obref = obref;

  const eventData = getAllEventData();

  if (eventData && isSet(eventData.ip_override)) user.ip_address = eventData.ip_override;
  if (eventData && isSet(eventData.user_agent)) user.user_agent = eventData.user_agent;

  return user;
}

function readCookie(name) {
  const values = getCookieValues(name);

  return values && values.length > 0 ? values[0] : undefined;
}

function buildContents(shape) {
  const rows = data.contents;

  if (!rows || rows.length === 0) {
    return undefined;
  }

  if (!acceptsContents(shape)) {
    // Sending one would fail the request, and the response cannot explain why.
    logToConsole('OpenAI Ads: contents ignored, the ' + shape + ' shape has no contents array.');

    return undefined;
  }

  const contents = [];

  for (let i = 0; i < rows.length; i++) {
    const row = rows[i];
    const item = {};

    if (isSet(row.id)) item.id = row.id;
    if (isSet(row.group_id)) item.group_id = row.group_id;
    if (isSet(row.name)) item.name = row.name;
    if (isSet(row.content_type)) item.content_type = row.content_type;
    if (isSet(row.quantity)) item.quantity = makeInteger(row.quantity);

    if (isSet(row.amount)) {
      item.amount = makeInteger(row.amount);
      if (isSet(data.currency)) item.currency = data.currency;
    }

    if (Object.keys(item).length > 0) {
      contents.push(item);
    }
  }

  return contents.length > 0 ? contents : undefined;
}

// --- the request ----------------------------------------------------------

const shape = DATA_SHAPES[data.eventName];

if (!shape) {
  fail('"' + data.eventName + '" is not a supported event.');
} else if (data.eventName === 'custom' && !isSet(data.customEventName)) {
  fail('a custom event needs a custom event name.');
} else if (data.actionSource === 'web' && !isSet(data.sourceUrl)) {
  fail('source_url is required when action_source is web.');
} else if ((data.eventName === 'app_installed' || data.eventName === 'app_opened') && data.actionSource !== 'mobile_app') {
  fail(data.eventName + ' requires action_source mobile_app.');
} else {
  const eventData = {type: shape};

  if (isSet(data.planId)) {
    if (acceptsPlanId(shape)) {
      eventData.plan_id = data.planId;
    } else {
      logToConsole('OpenAI Ads: plan_id ignored, the ' + shape + ' shape has no plan id.');
    }
  }

  let amountValid = true;

  if (isSet(data.amount)) {
    if (!isSet(data.currency)) {
      amountValid = false;
      fail('currency is required when amount is set.');
    } else {
      eventData.amount = makeInteger(data.amount);
      eventData.currency = data.currency;
    }
  }

  if (amountValid) {
    const contents = buildContents(shape);
    if (contents) eventData.contents = contents;

    const event = {
      id: data.eventId,
      type: data.eventName,
      timestamp_ms: getTimestampMillis(),
      action_source: data.actionSource,
      data: eventData
    };

    if (data.eventName === 'custom') event.custom_event_name = data.customEventName;
    if (isSet(data.sourceUrl)) event.source_url = data.sourceUrl;
    if (data.optOut) event.opt_out = true;

    const oppref = isSet(data.oppref) ? data.oppref : readCookie('__oppref');
    if (isSet(oppref)) event.oppref = oppref;

    const user = buildUser();
    if (Object.keys(user).length > 0) event.user = user;

    const body = {
      integration_source: isSet(data.integrationSource) ? data.integrationSource : 'webaroundlabs-gtm-server',
      events: [event]
    };

    if (data.validateOnly) body.validate_only = true;

    const url = ENDPOINT + '?pid=' + encodeUriComponent(data.pixelId);

    sendHttpRequest(url, function (statusCode) {
      // A completed request with a non-2xx status still completed. It is
      // reported as a failure to the container so the tag shows red, but the
      // status is logged rather than interpreted: OpenAI documents no status
      // taxonomy, so guessing at one would be inventing a contract.
      if (statusCode >= 200 && statusCode < 300) {
        data.gtmOnSuccess();
      } else {
        fail('the API returned HTTP ' + statusCode + '.');
      }
    }, {
      headers: {
        'Authorization': 'Bearer ' + data.apiKey,
        'Content-Type': 'application/json'
      },
      method: 'POST',
      timeout: 5000
    }, JSON.stringify(body));
  }
}


___SERVER_PERMISSIONS___

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
            "listItem": [{"type": 1, "string": "https://bzr.openai.com/*"}]
          }
        }
      ]
    },
    "clientAnnotations": {"isEditedByUser": true},
    "isRequired": true
  },
  {
    "instance": {
      "key": {"publicId": "read_event_data", "versionId": "1"},
      "param": [{"key": "eventDataAccess", "value": {"type": 1, "string": "any"}}]
    },
    "clientAnnotations": {"isEditedByUser": true},
    "isRequired": true
  },
  {
    "instance": {
      "key": {"publicId": "get_cookies", "versionId": "1"},
      "param": [
        {"key": "cookieAccess", "value": {"type": 1, "string": "specific"}},
        {
          "key": "cookieNames",
          "value": {
            "type": 2,
            "listItem": [
              {"type": 1, "string": "__oppref"},
              {"type": 1, "string": "__obref"}
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
    "isRequired": true
  }
]


___TESTS___

scenarios:
- name: A valid lead is posted to the documented endpoint
  code: |-
    mockData.eventName = 'lead_created';
    mockData.eventId = 'lead_9';
    mockData.actionSource = 'web';
    mockData.sourceUrl = 'https://example.com/thank-you';
    mock('sendHttpRequest', function (url, callback, options, body) {
      assertThat(url).contains('https://bzr.openai.com/v1/events?pid=px-1');
      assertThat(options.headers['Authorization']).isEqualTo('Bearer test-key');
      const parsed = JSON.parse(body);
      assertThat(parsed.events[0].type).isEqualTo('lead_created');
      assertThat(parsed.events[0].data.type).isEqualTo('customer_action');
      callback(200);
    });
    runCode(mockData);
    assertApi('gtmOnSuccess').wasCalled();

- name: A web event without a source URL is refused
  code: |-
    mockData.eventName = 'lead_created';
    mockData.eventId = 'lead_9';
    mockData.actionSource = 'web';
    runCode(mockData);
    assertApi('gtmOnFailure').wasCalled();

- name: An app event requires the mobile action source
  code: |-
    mockData.eventName = 'app_installed';
    mockData.eventId = 'install_1';
    mockData.actionSource = 'web';
    runCode(mockData);
    assertApi('gtmOnFailure').wasCalled();

- name: An amount without a currency is refused
  code: |-
    mockData.eventName = 'order_created';
    mockData.eventId = 'order_1';
    mockData.actionSource = 'web';
    mockData.sourceUrl = 'https://example.com/thank-you';
    mockData.amount = '1299';
    runCode(mockData);
    assertApi('gtmOnFailure').wasCalled();

- name: An email is hashed rather than sent raw
  code: |-
    mockData.eventName = 'lead_created';
    mockData.eventId = 'lead_9';
    mockData.actionSource = 'web';
    mockData.sourceUrl = 'https://example.com/thank-you';
    mockData.email = ' Ada@Example.COM ';
    mock('sendHttpRequest', function (url, callback, options, body) {
      assertThat(body).doesNotContain('ada@example.com');
      const parsed = JSON.parse(body);
      assertThat(parsed.events[0].user.emails_sha256[0]).isEqualTo(
        'b5fc85e55755f9e0d030a10ab4429b6b2944855f9a0d60077fe832becbc41d72'
      );
      callback(200);
    });
    runCode(mockData);
    assertApi('gtmOnSuccess').wasCalled();

- name: Validation mode is sent only when asked for
  code: |-
    mockData.eventName = 'lead_created';
    mockData.eventId = 'lead_9';
    mockData.actionSource = 'web';
    mockData.sourceUrl = 'https://example.com/thank-you';
    mockData.validateOnly = true;
    mock('sendHttpRequest', function (url, callback, options, body) {
      assertThat(JSON.parse(body).validate_only).isEqualTo(true);
      callback(200);
    });
    runCode(mockData);
    assertApi('gtmOnSuccess').wasCalled();

- name: Contents are dropped on a shape that has no contents array
  code: |-
    mockData.eventName = 'lead_created';
    mockData.eventId = 'lead_9';
    mockData.actionSource = 'web';
    mockData.sourceUrl = 'https://example.com/thank-you';
    mockData.contents = [{id: 'sku_1', quantity: '1'}];
    mock('sendHttpRequest', function (url, callback, options, body) {
      assertThat(JSON.parse(body).events[0].data.contents).isUndefined();
      callback(200);
    });
    runCode(mockData);
    assertApi('gtmOnSuccess').wasCalled();

setup: |-
  const JSON = require('JSON');
  const mockData = {
    pixelId: 'px-1',
    apiKey: 'test-key',
    integrationSource: 'webaroundlabs-gtm-server'
  };
  mock('sendHttpRequest', function (url, callback) {
    callback(200);
  });


___NOTES___

Independent community integration. Not created, certified, endorsed or supported
by OpenAI.

The API key belongs in a server container only. Never put it in a web container,
a page, or anything a browser can read.

Deduplication: the Event ID here must be the same value the browser Pixel sends
for the same conversion, together with the same Pixel ID and event name.

Identity normalization matches the rest of the toolkit exactly - trim and
lowercase for email, digits only then leading zeroes removed for phone, case
preserved for external ids, and Unicode-aware lowercasing for names - so a
conversion reported here produces the same digests as one reported by the PHP or
JavaScript packages.

This tag sends one event per fire and does not retry. A completed request with a
non-2xx status is reported as a tag failure but is not interpreted further:
OpenAI documents no status taxonomy, and repeating a request whose outcome is
unknown risks counting a conversion twice.
