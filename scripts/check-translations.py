"""Check the WordPress translation catalogues against the .pot.

The plugin does not ship these. Translations reach a site from
translate.wordpress.org, which the plugin directory asks every hosted plugin to
use, and WordPress has loaded them on demand since 4.6. What lives in
`packages/wordpress/translations-source/` is the ten hand-made catalogues,
kept so they can be imported into GlotPress rather than retyped.

    python scripts/check-translations.py

Kept honest because an import is only worth as much as the file behind it. Per
catalogue:

* every string in the catalogue still exists in the .pot, and every string in
  the .pot is in the catalogue - a msgid that drifted is a screen that silently
  falls back to English;
* no translation is empty;
* printf placeholders survive translation. `%s` dropped from a translated string
  is not a cosmetic bug: `sprintf()` then renders the sentence without the value
  it exists to carry, and a swapped `%d` for `%s` is a TypeError on a settings
  screen.

`gettext` is not needed, and neither is a `.mo`: nothing compiled is shipped or
read any more.
"""

import os
import re
import sys

DOMAIN = 'webaround-pixel-conversions-api-for-openai-ads'
ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')
PLUGIN = os.path.join(ROOT, 'packages', 'wordpress')
CATALOGUES = os.path.join(PLUGIN, 'translations-source')
POT = os.path.join(PLUGIN, 'languages', DOMAIN + '.pot')

PLACEHOLDER = re.compile(r'%(?:\d+\$)?[sdf]')


class Failure(Exception):
    """A catalogue that is not fit to be imported as it stands."""


def unescape(text):
    """Decode a .po string literal."""
    out = []
    i = 0

    while i < len(text):
        char = text[i]

        if char == '\\' and i + 1 < len(text):
            following = text[i + 1]
            out.append({'n': '\n', 't': '\t', 'r': '\r', '"': '"', '\\': '\\'}.get(following, following))
            i += 2
            continue

        out.append(char)
        i += 1

    return ''.join(out)


def parse(path):
    """Read a .po or .pot into {msgid: msgstr}, headers included as ''."""
    entries = {}
    msgid = None
    target = None
    buffer = []

    def flush():
        if msgid is not None and target is not None:
            entries[msgid] = ''.join(buffer)

    with open(path, encoding='utf-8') as handle:
        for line in handle:
            line = line.strip()

            if line.startswith('msgid "'):
                flush()
                msgid = unescape(line[7:-1])
                target = None
                buffer = []
            elif line.startswith('msgstr "'):
                target = True
                buffer = [unescape(line[8:-1])]
            elif line.startswith('"') and line.endswith('"'):
                text = unescape(line[1:-1])
                if target:
                    buffer.append(text)
                else:
                    msgid += text

    flush()

    return entries


def placeholders(text):
    return sorted(PLACEHOLDER.findall(text))


def verify(locale, catalogue, source):
    """Everything that makes a catalogue unfit to import, reported at once."""
    problems = []

    missing = set(source) - set(catalogue)
    unknown = set(catalogue) - set(source)

    for msgid in sorted(missing):
        problems.append('missing: %r' % msgid[:60])

    for msgid in sorted(unknown):
        problems.append('not in the .pot, so it translates nothing: %r' % msgid[:60])

    for msgid, msgstr in sorted(catalogue.items()):
        if msgid == '':
            continue

        if msgstr.strip() == '':
            problems.append('untranslated: %r' % msgid[:60])
            continue

        if placeholders(msgid) != placeholders(msgstr):
            problems.append(
                'placeholders differ (%s vs %s): %r'
                % (placeholders(msgid), placeholders(msgstr), msgid[:60])
            )

    if problems:
        raise Failure('%s\n  %s' % (locale, '\n  '.join(problems)))


def main():
    if not os.path.exists(POT):
        raise Failure('%s is missing. Run scripts/make-pot.py first.' % POT)

    source = {k: v for k, v in parse(POT).items() if k != ''}

    catalogues = sorted(
        name for name in os.listdir(CATALOGUES)
        if name.startswith(DOMAIN + '-') and name.endswith('.po')
    ) if os.path.isdir(CATALOGUES) else []

    # A hard failure rather than a shrug. Finding nothing used to mean the
    # domain had been renamed without the catalogues, and the run passed
    # silently - which is how a rename ships with ten dead translations.
    if not catalogues:
        raise Failure(
            'no .po catalogues in %s. Has the text domain changed without them?' % CATALOGUES
        )

    failures = []

    for name in catalogues:
        locale = name[len(DOMAIN) + 1:-3]

        try:
            entries = parse(os.path.join(CATALOGUES, name))
            verify(locale, {k: v for k, v in entries.items() if k != ''}, source)
        except Failure as failure:
            failures.append(str(failure))

    if failures:
        raise Failure('\n'.join(failures))

    print(
        'PASS: %d catalogues, %d strings each, placeholders intact'
        % (len(catalogues), len(source))
    )

    return 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except Failure as failure:
        print('FAIL: %s' % failure, file=sys.stderr)
        sys.exit(1)
