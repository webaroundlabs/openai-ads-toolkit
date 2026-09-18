"""Check the WordPress translation catalogues, and compile them to .mo.

WordPress reads `.mo`, not `.po`. A site installing from the plugin directory
gets its translations from translate.wordpress.org, but the copies installed by
hand - and this repository's own plugin zip - carry the compiled files, so a
`.po` edited without a rebuild is a translation nobody sees.

    python scripts/make-mo.py            # verify, then write every .mo
    python scripts/make-mo.py --check    # verify only; fails if a .mo is stale

What is verified, per catalogue:

* every string in the catalogue still exists in the .pot, and every string in
  the .pot is in the catalogue - a msgid that drifted is a screen that silently
  falls back to English;
* no translation is empty;
* printf placeholders survive translation. `%s` dropped from a translated string
  is not a cosmetic bug: `sprintf()` then renders the sentence without the value
  it exists to carry, and a swapped `%d` for `%s` is a TypeError on a settings
  screen.

`gettext` itself is not needed - msgfmt is a few hundred bytes of struct
packing, and requiring a toolchain to rebuild a translation is how translations
stop being rebuilt.
"""

import os
import re
import struct
import sys

DOMAIN = 'conversion-tracking-for-openai-ads'
ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')
LANGUAGES = os.path.join(ROOT, 'packages', 'wordpress', 'languages')
POT = os.path.join(LANGUAGES, DOMAIN + '.pot')

PLACEHOLDER = re.compile(r'%(?:\d+\$)?[sdf]')


class Failure(Exception):
    """A catalogue that must not ship as it stands."""


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
    """Everything that makes a catalogue unfit to ship, reported at once."""
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


def compile_mo(entries):
    """The .mo binary, as msgfmt writes it.

    The empty msgid carries the headers, and WordPress reads the charset from
    them, so it is written like any other entry rather than dropped.
    """
    items = sorted((k, v) for k, v in entries.items() if v != '')

    originals = b''
    translations = b''
    original_table = []
    translation_table = []

    for msgid, msgstr in items:
        original = msgid.encode('utf-8')
        translated = msgstr.encode('utf-8')

        original_table.append((len(original), len(originals)))
        translation_table.append((len(translated), len(translations)))

        originals += original + b'\x00'
        translations += translated + b'\x00'

    count = len(items)
    header_size = 28
    original_offset = header_size
    translation_offset = original_offset + count * 8
    strings_offset = translation_offset + count * 8

    out = struct.pack(
        '<Iiiiiii',
        0x950412DE,  # magic, little-endian
        0,           # revision
        count,
        original_offset,
        translation_offset,
        0,           # no hash table; every reader this targets scans the tables
        strings_offset,
    )

    for length, offset in original_table:
        out += struct.pack('<ii', length, strings_offset + offset)

    for length, offset in translation_table:
        out += struct.pack('<ii', length, strings_offset + len(originals) + offset)

    return out + originals + translations


def main(argv):
    check_only = '--check' in argv[1:]

    if not os.path.exists(POT):
        raise Failure('%s is missing. Run scripts/make-pot.py first.' % POT)

    source = {k: v for k, v in parse(POT).items() if k != ''}

    catalogues = sorted(
        name for name in os.listdir(LANGUAGES)
        if name.startswith(DOMAIN + '-') and name.endswith('.po')
    )

    if not catalogues:
        print('no .po catalogues in %s' % LANGUAGES)
        return 0

    failures = []
    written = 0

    for name in catalogues:
        locale = name[len(DOMAIN) + 1:-3]
        po = os.path.join(LANGUAGES, name)
        mo = po[:-3] + '.mo'

        try:
            entries = parse(po)
            verify(locale, {k: v for k, v in entries.items() if k != ''}, source)
        except Failure as failure:
            failures.append(str(failure))
            continue

        compiled = compile_mo(entries)

        if check_only:
            current = open(mo, 'rb').read() if os.path.exists(mo) else None

            if current != compiled:
                failures.append('%s: %s is stale. Run scripts/make-mo.py.' % (locale, os.path.basename(mo)))

            continue

        with open(mo, 'wb') as handle:
            handle.write(compiled)

        written += 1
        print('%s -> %s (%d strings)' % (locale, os.path.basename(mo), len(entries) - 1))

    if failures:
        raise Failure('\n'.join(failures))

    print(
        'PASS: %d catalogues, %d strings each, placeholders intact'
        % (len(catalogues), len(source))
        if check_only else
        'wrote %d catalogues' % written
    )

    return 0


if __name__ == '__main__':
    try:
        sys.exit(main(sys.argv))
    except Failure as failure:
        print('FAIL: %s' % failure, file=sys.stderr)
        sys.exit(1)
