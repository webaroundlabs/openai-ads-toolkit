___INFO___

{
  "type": "TAG",
  "id": "cvt_temp_public_id",
  "version": 1,
  "securityGroups": [],
  "displayName": "OpenAI Ads Measurement Pixel",
  "categories": ["ADVERTISING", "CONVERSIONS"],
  "brand": {
    "id": "webaround",
    "displayName": "Webaround"
  },
  "description": "Loads the official OpenAI Ads Measurement Pixel and reports conversions. Independent community integration, not affiliated with OpenAI.",
  "containerContexts": ["WEB"]
}


___TEMPLATE_PARAMETERS___

[
  {
    "type": "SELECT",
    "name": "tagType",
    "displayName": "Tag type",
    "selectItems": [
      {"value": "init", "displayValue": "Initialize Pixel"},
      {"value": "event", "displayValue": "Track event"}
    ],
    "simpleValueType": true,
    "defaultValue": "event",
    "help": "Initialize once per Pixel ID, on a trigger that fires early on every page. Use a separate tag for each conversion."
  },
  {
    "type": "TEXT",
    "name": "pixelId",
    "displayName": "Pixel ID",
    "simpleValueType": true,
    "valueValidators": [{"type": "NON_EMPTY"}],
    "help": "Public. On an event tag this routes the event to one pixel instead of broadcasting to every initialized pixel."
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
    "defaultValue": "page_viewed",
    "enablingConditions": [{"paramName": "tagType", "paramValue": "event", "type": "EQUALS"}],
    "help": "app_installed and app_opened are deliberately absent: they are Conversions API only and the browser cannot send them."
  },
  {
    "type": "TEXT",
    "name": "customEventName",
    "displayName": "Custom event name",
    "simpleValueType": true,
    "enablingConditions": [{"paramName": "eventName", "paramValue": "custom", "type": "EQUALS"}],
    "help": "1-64 characters: letters, digits, underscores or dashes, starting and ending with a letter or digit. Must match the name the server sends."
  },
  {
    "type": "TEXT",
    "name": "eventId",
    "displayName": "Event ID",
    "simpleValueType": true,
    "enablingConditions": [{"paramName": "tagType", "paramValue": "event", "type": "EQUALS"}],
    "help": "Required for deduplication. Must be the SAME value the Conversions API sends for this conversion, or it is counted twice. Prefer an id the site already owns, such as an order number."
  },
  {
    "type": "GROUP",
    "name": "valueGroup",
    "displayName": "Value",
    "groupStyle": "ZIPPY_OPEN_ON_PARAM",
    "enablingConditions": [{"paramName": "tagType", "paramValue": "event", "type": "EQUALS"}],
    "subParams": [
      {
        "type": "TEXT",
        "name": "amount",
        "displayName": "Amount (minor units)",
        "simpleValueType": true,
        "help": "An INTEGER in the currency's minor unit. 12.99 euros is 1299. Yen have no minor unit, so 1299 yen is 1299."
      },
      {
        "type": "TEXT",
        "name": "currency",
        "displayName": "Currency",
        "simpleValueType": true,
        "help": "ISO 4217, such as EUR. Required whenever an amount is set."
      }
    ]
  },
  {
    "type": "GROUP",
    "name": "userGroup",
    "displayName": "User data (already hashed)",
    "groupStyle": "ZIPPY_CLOSED",
    "enablingConditions": [{"paramName": "tagType", "paramValue": "init", "type": "EQUALS"}],
    "subParams": [
      {
        "type": "LABEL",
        "name": "userNotice",
        "displayName": "Hashed values only. Raw email addresses and phone numbers must never be placed in browser code. Hash server-side, or use the toolkit's JavaScript package."
      },
      {"type": "TEXT", "name": "emailSha256", "displayName": "email_sha256", "simpleValueType": true},
      {"type": "TEXT", "name": "phoneNumberSha256", "displayName": "phone_number_sha256", "simpleValueType": true},
      {"type": "TEXT", "name": "externalIdSha256", "displayName": "external_id_sha256", "simpleValueType": true},
      {"type": "TEXT", "name": "firstNameSha256", "displayName": "first_name_sha256", "simpleValueType": true},
      {"type": "TEXT", "name": "lastNameSha256", "displayName": "last_name_sha256", "simpleValueType": true},
      {"type": "TEXT", "name": "country", "displayName": "country", "simpleValueType": true},
      {"type": "TEXT", "name": "city", "displayName": "city", "simpleValueType": true},
      {"type": "TEXT", "name": "region", "displayName": "region", "simpleValueType": true},
      {"type": "TEXT", "name": "postalCode", "displayName": "postal_code", "simpleValueType": true}
    ]
  },
  {
    "type": "CHECKBOX",
    "name": "optOut",
    "checkboxText": "Exclude this event from personalization (opt_out)",
    "simpleValueType": true,
    "enablingConditions": [{"paramName": "tagType", "paramValue": "event", "type": "EQUALS"}],
    "help": "This is NOT a consent gate. It still sends the event. If consent was refused, do not fire the tag at all."
  }
]


___SANDBOXED_JS_FOR_WEB_TEMPLATE___

// Wraps OpenAI's official browser SDK. It does not reimplement the transport:
// the SDK is loaded from OpenAI's CDN and spoken to through its command queue,
// exactly as the documented snippet does.
//
// Everything the SDK owns stays with the SDK - batching, event timestamps,
// source_url, and capturing oppref into the __oppref cookie. Passing any of
// those by hand would corrupt them.

const createArgumentsQueue = require('createArgumentsQueue');
const injectScript = require('injectScript');
const logToConsole = require('logToConsole');
const makeNumber = require('makeNumber');

const SDK_URL = 'https://bzrcdn.openai.com/sdk/oaiq.min.js';

const oaiq = createArgumentsQueue('oaiq', 'oaiq.q');

const DATA_SHAPES = {
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
  custom: 'custom'
};

function fail(message) {
  logToConsole('OpenAI Ads: ' + message);
  data.gtmOnFailure();
}

function isSet(value) {
  return value !== undefined && value !== null && value !== '';
}

function buildUser() {
  const user = {};
  if (isSet(data.emailSha256)) user.email_sha256 = data.emailSha256;
  if (isSet(data.phoneNumberSha256)) user.phone_number_sha256 = data.phoneNumberSha256;
  if (isSet(data.externalIdSha256)) user.external_id_sha256 = data.externalIdSha256;
  if (isSet(data.firstNameSha256)) user.first_name_sha256 = data.firstNameSha256;
  if (isSet(data.lastNameSha256)) user.last_name_sha256 = data.lastNameSha256;
  if (isSet(data.country)) user.country = data.country;
  if (isSet(data.city)) user.city = data.city;
  if (isSet(data.region)) user.region = data.region;
  if (isSet(data.postalCode)) user.postal_code = data.postalCode;
  return user;
}

function initialize() {
  if (!isSet(data.pixelId)) {
    return fail('a Pixel ID is required to initialize.');
  }

  const config = {pixelId: data.pixelId};
  const user = buildUser();

  // Identity goes on init, not on each measure call, as the SDK documents.
  for (const key in user) {
    config.user = user;
    break;
  }

  // Injecting first means the queue is defined before the script arrives, so
  // commands issued now are replayed in order rather than lost.
  injectScript(SDK_URL, function () {
    oaiq('init', config);
    data.gtmOnSuccess();
  }, function () {
    fail('the Pixel SDK failed to load.');
  }, 'oaiq');
}

function trackEvent() {
  const name = data.eventName;
  const shape = DATA_SHAPES[name];

  if (!shape) {
    // app_installed and app_opened reach here only if a container was edited by
    // hand: they are Conversions API only and the SDK would silently drop them.
    return fail('"' + name + '" cannot be sent from the browser.');
  }

  const eventData = {type: shape};

  if (isSet(data.amount)) {
    const amount = makeNumber(data.amount);

    if (amount !== amount || amount % 1 !== 0) {
      return fail('amount must be an integer in the minor unit of its currency. 12.99 euros is 1299.');
    }

    if (!isSet(data.currency)) {
      return fail('currency is required when amount is set.');
    }

    eventData.amount = amount;
    eventData.currency = data.currency;
  }

  const options = {};

  if (isSet(data.eventId)) {
    options.event_id = data.eventId;
  } else {
    // Not fatal - the event is still useful - but it can no longer be matched
    // to the server-side one, so the conversion may be counted twice.
    logToConsole('OpenAI Ads: no Event ID set, so this event cannot be deduplicated against the Conversions API.');
  }

  if (name === 'custom') {
    if (!isSet(data.customEventName)) {
      return fail('a custom event needs a custom event name.');
    }
    options.custom_event_name = data.customEventName;
  }

  if (data.optOut) {
    options.opt_out = true;
  }

  if (isSet(data.pixelId)) {
    // measureSingle rather than measure: measure broadcasts to every pixel
    // initialized at the time of the call, which double-counts on a container
    // running more than one Pixel ID.
    oaiq('measureSingle', data.pixelId, name, eventData, options);
  } else {
    oaiq('measure', name, eventData, options);
  }

  data.gtmOnSuccess();
}

if (data.tagType === 'init') {
  initialize();
} else {
  trackEvent();
}


___WEB_PERMISSIONS___

[
  {
    "instance": {
      "key": {"publicId": "inject_script", "versionId": "1"},
      "param": [
        {
          "key": "urls",
          "value": {
            "type": 2,
            "listItem": [{"type": 1, "string": "https://bzrcdn.openai.com/sdk/oaiq.min.js"}]
          }
        }
      ]
    },
    "clientAnnotations": {"isEditedByUser": true},
    "isRequired": true
  },
  {
    "instance": {
      "key": {"publicId": "access_globals", "versionId": "1"},
      "param": [
        {
          "key": "keys",
          "value": {
            "type": 2,
            "listItem": [
              {
                "type": 3,
                "mapKey": [{"type": 1, "string": "key"}, {"type": 1, "string": "read"}, {"type": 1, "string": "write"}, {"type": 1, "string": "execute"}],
                "mapValue": [{"type": 1, "string": "oaiq"}, {"type": 8, "boolean": true}, {"type": 8, "boolean": true}, {"type": 8, "boolean": true}]
              },
              {
                "type": 3,
                "mapKey": [{"type": 1, "string": "key"}, {"type": 1, "string": "read"}, {"type": 1, "string": "write"}, {"type": 1, "string": "execute"}],
                "mapValue": [{"type": 1, "string": "oaiq.q"}, {"type": 8, "boolean": true}, {"type": 8, "boolean": true}, {"type": 8, "boolean": false}]
              }
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
- name: Initializing loads the official SDK
  code: |-
    mockData.tagType = 'init';
    mockData.pixelId = 'px-1';
    runCode(mockData);
    assertApi('injectScript').wasCalledWith(
      'https://bzrcdn.openai.com/sdk/oaiq.min.js',
      any(), any(), 'oaiq'
    );

- name: An event is routed to one pixel rather than broadcast
  code: |-
    mockData.tagType = 'event';
    mockData.pixelId = 'px-1';
    mockData.eventName = 'lead_created';
    mockData.eventId = 'lead_9';
    runCode(mockData);
    assertApi('gtmOnSuccess').wasCalled();

- name: A major-unit amount is rejected
  code: |-
    mockData.tagType = 'event';
    mockData.pixelId = 'px-1';
    mockData.eventName = 'order_created';
    mockData.amount = '12.99';
    mockData.currency = 'EUR';
    runCode(mockData);
    assertApi('gtmOnFailure').wasCalled();

- name: An amount without a currency is rejected
  code: |-
    mockData.tagType = 'event';
    mockData.pixelId = 'px-1';
    mockData.eventName = 'order_created';
    mockData.amount = '1299';
    runCode(mockData);
    assertApi('gtmOnFailure').wasCalled();

- name: A custom event without a name is rejected
  code: |-
    mockData.tagType = 'event';
    mockData.pixelId = 'px-1';
    mockData.eventName = 'custom';
    runCode(mockData);
    assertApi('gtmOnFailure').wasCalled();

- name: A Conversions API only event is refused
  code: |-
    mockData.tagType = 'event';
    mockData.pixelId = 'px-1';
    mockData.eventName = 'app_installed';
    runCode(mockData);
    assertApi('gtmOnFailure').wasCalled();

setup: |-
  const mockData = {};


___NOTES___

Independent community integration. Not created, certified, endorsed or supported
by OpenAI.

Deduplication: the Event ID here must be the same value the Conversions API sends
for the same conversion, together with the same Pixel ID and event name. Without
that, one conversion is counted twice.

Never put a Conversions API key in a web container. It is server-side only.
