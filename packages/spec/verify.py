"""Verify the shared specification.

Checks that every internal cross-reference resolves, that the documented OpenAI Ads
facts the toolkit depends on still hold, and that the golden fixtures agree with the
spec files they are derived from.

This is the Phase 1 stand-in for the cross-runtime parity tests: once the PHP and
TypeScript packages exist, each asserts the same properties against its own
implementation. Run from the repository root:

    python packages/spec/verify.py

Exits non-zero on the first set of failures, listing each one.
"""

import json, glob, sys, re, os

files = sorted(glob.glob('packages/spec/**/*.json', recursive=True))
docs = {}
for f in files:
    key = f.replace('\\', '/')
    docs[key] = json.load(open(f, encoding='utf-8'))

ev = docs['packages/spec/events.json']
us = docs['packages/spec/user.json']
fx_lead = docs['packages/spec/fixtures/lead_created.minimal.capi.json']
fx_capi = docs['packages/spec/fixtures/user.full.capi.json']
fx_pix  = docs['packages/spec/fixtures/user.full.pixel.json']
fx_norm = docs['packages/spec/fixtures/normalization.cases.json']

fail = []

shapes  = set(ev['data_shapes'])
objects = set(ev['objects'])
actions = set(ev['action_sources'])
events  = set(ev['events'])
patterns = set(ev['patterns'])

# --- internal reference resolution -----------------------------------------
for name, e in ev['events'].items():
    if e['data_shape'] not in shapes:
        fail.append("events.%s.data_shape -> %s missing" % (name, e['data_shape']))
    for a in e.get('action_sources', []):
        if a not in actions:
            fail.append("events.%s.action_sources -> %s missing" % (name, a))

def check_field(path, f):
    if not isinstance(f, dict):
        return
    if isinstance(f.get('pattern'), str) and f['pattern'] not in patterns:
        fail.append("%s.pattern -> %s missing" % (path, f['pattern']))
    if 'items' in f and f['items'] not in objects and f['items'] != 'event_fields':
        fail.append("%s.items -> %s missing" % (path, f['items']))
    if 'enum_source' in f and f['enum_source'] not in ('events', 'action_sources'):
        fail.append("%s.enum_source -> %s missing" % (path, f['enum_source']))
    rw = f.get('required_when')
    if isinstance(rw, dict) and set(rw) not in ({'field', 'equals'}, {'field', 'present'}):
        fail.append("%s.required_when uses an undeclared form: %s" % (path, sorted(rw)))

for k, f in ev['event_fields'].items():
    check_field("event_fields.%s" % k, f)
for s, fields in ev['data_shapes'].items():
    for k, f in fields.items():
        check_field("data_shapes.%s.%s" % (s, k), f)
for o, fields in ev['objects'].items():
    for k, f in fields.items():
        check_field("objects.%s.%s" % (o, k), f)
for k, f in ev['envelope']['fields'].items():
    check_field("envelope.fields.%s" % k, f)

norms = set(us['normalizations'])
user_patterns = set(us.get('patterns', {}))
for name, f in us['fields'].items():
    if f['normalization'] not in norms:
        fail.append("user.fields.%s.normalization -> %s missing" % (name, f['normalization']))
for name, n in us['normalizations'].items():
    if isinstance(n.get('pattern'), str) and n['pattern'] not in user_patterns:
        fail.append("user.normalizations.%s.pattern -> %s missing" % (name, n['pattern']))

# --- documented facts -------------------------------------------------------
expected_events = {"page_viewed","contents_viewed","items_added","checkout_started","order_created",
    "lead_created","registration_completed","appointment_scheduled","subscription_created",
    "trial_started","custom","app_installed","app_opened"}
if events != expected_events:
    fail.append("event catalogue mismatch: %s" % (events ^ expected_events))

expected_actions = {"web","mobile_app","offline","physical_store","phone_call","email","other"}
if actions != expected_actions:
    fail.append("action_sources mismatch: %s" % (actions ^ expected_actions))
if shapes != {"contents","customer_action","plan_enrollment","custom"}:
    fail.append("data shape set mismatch")

ca = ev['data_shapes']['customer_action']
for forbidden in ('contents', 'plan_id'):
    if forbidden in ca:
        fail.append("customer_action must not accept %s" % forbidden)

for n in ('app_installed', 'app_opened'):
    if ev['events'][n]['pixel'] is not False:
        fail.append("%s must be pixel:false" % n)
    if ev['events'][n].get('action_sources') != ['mobile_app']:
        fail.append("%s must pin mobile_app" % n)

for n in ('group_id', 'variant_dict'):
    if not ev['objects']['content'][n].get('capi_only'):
        fail.append("content.%s must be capi_only" % n)

# oppref is an EVENT field; obref is a USER field. Neither may cross over.
if 'oppref' not in ev['event_fields']: fail.append("events.json must define event-level oppref")
if 'obref' in ev['event_fields']:      fail.append("obref must NOT be an event field")
if 'obref' not in us['fields']:        fail.append("user.json must define obref")
if 'oppref' in us['fields']:           fail.append("oppref must NOT be a user field")
if ev['event_fields']['oppref'].get('cookie') != '__oppref':
    fail.append("oppref cookie must be __oppref")
if us['fields']['obref']['captured_by_pixel']['cookie'] != '__obref':
    fail.append("obref cookie must be __obref")

hashed = ['email','phone','external_id','first_name','last_name']
geo    = ['country','city','region','postal_code']
for n in hashed + geo:
    f = us['fields'][n]
    if f['capi']['cardinality'] != 'list':
        fail.append("user.%s capi must be list" % n)
    if not f['pixel'] or f['pixel']['cardinality'] != 'scalar':
        fail.append("user.%s pixel must be scalar" % n)
for n in ['obref','ip_address','user_agent','android_advertising_id']:
    if us['fields'][n]['pixel'] is not None:
        fail.append("user.%s pixel must be null" % n)
    if us['fields'][n]['capi']['cardinality'] != 'scalar':
        fail.append("user.%s capi must be scalar" % n)

expected_capi_keys = {"emails_sha256","phone_numbers_sha256","external_ids_sha256","first_names_sha256",
    "last_names_sha256","countries","cities","regions","postal_codes","obref","ip_address",
    "user_agent","android_advertising_id"}
actual_capi_keys = set(f['capi']['key'] for f in us['fields'].values())
if actual_capi_keys != expected_capi_keys:
    fail.append("CAPI key set mismatch: %s" % (actual_capi_keys ^ expected_capi_keys))

if ev['limits']['batch_max_events'] != 1000: fail.append("batch max must be 1000")
if ev['limits']['timestamp_max_age_ms'] != 7*24*60*60*1000: fail.append("age window must be 7 days")
if ev['limits']['timestamp_max_future_ms'] != 10*60*1000: fail.append("future window must be 10 min")
if us['list_field_max_values'] != 3: fail.append("user list cap must be 3")

# --- pattern behaviour ------------------------------------------------------
p = re.compile(ev['patterns']['custom_event_name'])
for good in ('a', 'whatsapp_lead', 'lead-2', 'a'*64):
    if not p.fullmatch(good): fail.append("custom_event_name should accept %r" % good)
for bad in ('', '_lead', 'lead_', 'a'*65, 'lead lead', 'lead!'):
    if p.fullmatch(bad): fail.append("custom_event_name should reject %r" % bad)

isrc = re.compile(ev['patterns']['integration_source'])
if not isrc.fullmatch('webaroundlabs-openai-ads-toolkit'):
    fail.append("the default integration_source does not satisfy its own pattern")

# --- fixtures agree with the spec -------------------------------------------
# parity assertion 3, run against the fixtures rather than a runtime
if set(fx_capi['expected']) != expected_capi_keys:
    fail.append("user.full.capi fixture key set != user.json capi keys")
pixel_keys = set(f['pixel']['key'] for f in us['fields'].values() if f['pixel'])
if set(fx_pix['expected']) != pixel_keys:
    fail.append("user.full.pixel fixture key set != user.json pixel keys")
if fx_capi['input'] != fx_pix['input']:
    fail.append("the two user fixtures must share identical input")
# same digests either side of the asymmetry
for logical in hashed:
    c = fx_capi['expected'][us['fields'][logical]['capi']['key']][0]
    x = fx_pix['expected'][us['fields'][logical]['pixel']['key']]
    if c != x:
        fail.append("digest for %s differs between CAPI and Pixel fixtures" % logical)

lead = fx_lead['expected_event']
if lead['type'] != 'lead_created': fail.append("lead fixture type wrong")
if lead['data'] != {'type': 'customer_action'}: fail.append("lead fixture data shape wrong")
if any(k in lead for k in ('amount','currency','oppref','opt_out','custom_event_name')):
    fail.append("lead fixture must omit absent optional fields, not emit them")
if 'validate_only' in fx_lead['expected_request']:
    fail.append("validate_only must be absent when false")
sha = re.compile(ev['patterns']['sha256_hex'])
for k, v in lead['user'].items():
    for d in (v if isinstance(v, list) else [v]):
        if not sha.fullmatch(d): fail.append("lead fixture digest %r is not lowercase 64-hex" % d)

# --- normalization fixture is internally consistent -------------------------
import hashlib
for case in fx_norm['valid']:
    actual = hashlib.sha256(case['normalized'].encode('utf-8')).hexdigest()
    if actual != case['sha256']:
        fail.append("normalization case %r: pinned digest does not match its own normalized value" % case['raw'])
g = fx_norm['divergence_guard']
for side in ('wrong', 'correct'):
    actual = hashlib.sha256(g[side]['normalized'].encode('utf-8')).hexdigest()
    if actual != g[side]['sha256']:
        fail.append("divergence_guard.%s digest does not match its normalized value" % side)
if g['wrong']['sha256'] == g['correct']['sha256']:
    fail.append("divergence_guard is pointless: both digests are equal")

# --- geographic normalization is declared, not "none" -----------------------
# The Conversions API documents a rule for each geographic field and drops a
# value that does not satisfy it, without any error. Leaving these on the "none"
# normalization is how a country named "Romania" gets sent and silently ignored.
for field, expected_rule in (('country', 'country'), ('city', 'city_region'),
                             ('region', 'city_region'), ('postal_code', 'postal_code')):
    if us['fields'][field]['normalization'] != expected_rule:
        fail.append("user.%s must use the %r normalization, not %r"
                    % (field, expected_rule, us['fields'][field]['normalization']))

cc = re.compile(us['patterns']['country_code'])
for good in ('RO', 'us'):
    if not cc.fullmatch(good): fail.append("country_code should accept %r" % good)
for bad in ('Romania', 'R', 'R0', ''):
    if cc.fullmatch(bad): fail.append("country_code should reject %r" % bad)

geo = fx_norm['geographic']
city_max = us['normalizations']['city_region']['max_length']
postal_max = us['normalizations']['postal_code']['max_length']
postal_allowed = re.compile(r'^[A-Za-z0-9 -]*$')

for case in geo['valid']:
    field, raw, norm = case['field'], case['raw'], case['normalized']

    if norm == '':
        fail.append("geographic case %r normalizes to nothing but is listed as valid" % raw)

    if field == 'country':
        if not cc.fullmatch(norm) or norm != norm.upper():
            fail.append("geographic country case %r must normalize to an uppercase two-letter code" % raw)
    elif field in ('city', 'region'):
        if norm != norm.lower():
            fail.append("geographic %s case %r must normalize to lowercase" % (field, raw))
        if len(norm) > city_max:
            fail.append("geographic %s case %r exceeds the %d-character cap" % (field, raw, city_max))
        # Truncation is measured in characters, so a long raw value must land
        # exactly on the cap rather than somewhere near it.
        if len(raw.strip()) > city_max and len(norm) != city_max:
            fail.append("geographic %s case must truncate to exactly %d characters, got %d"
                        % (field, city_max, len(norm)))
    elif field == 'postal_code':
        if not postal_allowed.fullmatch(norm):
            fail.append("geographic postal_code case %r kept a character outside the documented set" % raw)
        if len(norm) > postal_max:
            fail.append("geographic postal_code case %r exceeds the %d-character cap" % (raw, postal_max))
    else:
        fail.append("geographic case names an unknown field %r" % field)

if not any(c['field'] == 'country' for c in geo['rejected']):
    fail.append("the geographic fixtures must pin a rejected country, or nothing stops 'Romania' being sent")

# The phone rule removes four documented separators, NOT every non-digit.
# Stripping every non-digit turns an extension into extra digits of a plausible
# but wrong number, so a case proving that is refused is load-bearing.
if us['normalizations']['phone']['steps'] == ['trim', 'strip_non_digits', 'strip_leading_zeroes']:
    fail.append("the phone rule must remove only the documented separators, not every non-digit")
if not any('ext' in c['raw'] for c in fx_norm['rejected'] if c['field'] == 'phone'):
    fail.append("the phone fixtures must pin a rejected extension, or the strip-every-non-digit bug can return")

# --- the GTM templates agree with the catalogue -----------------------------
# The templates themselves cannot be executed here; that needs Google Tag
# Manager. But the part most likely to drift silently is the event list in each
# dropdown, and that can be checked exactly.

def gtm_sections(path):
    """Split a .tpl file into its ___SECTION___ blocks."""
    raw = open(path, encoding='utf-8').read()
    blocks, name, buffer = {}, None, []

    for line in raw.splitlines():
        stripped = line.strip()

        if stripped.startswith('___') and stripped.endswith('___') and len(stripped) > 6:
            if name is not None:
                blocks[name] = '\n'.join(buffer)
            name, buffer = stripped.strip('_'), []
        else:
            buffer.append(line)

    if name is not None:
        blocks[name] = '\n'.join(buffer)

    return blocks


for path, channel in [('packages/gtm-web/template.tpl', 'pixel'),
                      ('packages/gtm-server/template.tpl', 'capi')]:
    if not os.path.exists(path):
        continue

    try:
        blocks = gtm_sections(path)
    except Exception as exc:
        fail.append('%s could not be parsed: %s' % (path, exc))
        continue

    for section in ('INFO', 'TEMPLATE_PARAMETERS', 'TESTS', 'NOTES'):
        if section not in blocks:
            fail.append('%s is missing the %s section' % (path, section))

    if 'INFO' in blocks:
        try:
            info = json.loads(blocks['INFO'])
            expected = 'WEB' if channel == 'pixel' else 'SERVER'

            if info.get('containerContexts') != [expected]:
                fail.append('%s should declare containerContexts ["%s"]' % (path, expected))
        except Exception as exc:
            fail.append('%s has unparseable INFO: %s' % (path, exc))

    if 'TEMPLATE_PARAMETERS' not in blocks:
        continue

    try:
        params = json.loads(blocks['TEMPLATE_PARAMETERS'])
    except Exception as exc:
        fail.append('%s has unparseable TEMPLATE_PARAMETERS: %s' % (path, exc))
        continue

    offered = set()

    for param in params:
        if param.get('name') == 'eventName':
            offered = set(item['value'] for item in param['selectItems'])

    if offered == set():
        fail.append('%s has no eventName parameter' % path)
        continue

    supported = set(n for n, e in ev['events'].items() if e[channel])

    if offered != supported:
        fail.append('%s offers %s but the spec says %s' % (
            path, sorted(offered ^ supported), sorted(supported)))

    if channel == 'pixel':
        # The browser SDK would accept and silently discard these.
        for capi_only in ('app_installed', 'app_opened'):
            if capi_only in offered:
                fail.append('%s offers "%s", which the browser cannot send' % (path, capi_only))

        # A Conversions API key in a web container is the one mistake that
        # cannot be walked back once a container is published.
        body = open(path, encoding='utf-8').read().lower()

        for forbidden in ('apikey', 'api_key', 'bearer '):
            if forbidden in body:
                fail.append('%s references a credential (%r) in a web template' % (path, forbidden))

print("checked %d spec files" % len(docs))
if fail:
    print("\nFAILURES:")
    for x in fail:
        print("  -", x)
    sys.exit(1)
print("PASS: cross-references resolve, documented facts hold, fixtures agree with the spec")
