"""Extract the WordPress plugin's translatable strings into a .pot catalogue.

`wp i18n make-pot` is the usual tool and it is better than this one, but it needs
a WP-CLI installation and a WordPress to point at. This produces the same file
for the subset of gettext calls the plugin actually uses, from a bare checkout,
so regenerating the catalogue is never a reason to go and install something.

    python scripts/make-pot.py

Writes packages/wordpress/languages/<text-domain>.pot. Run it whenever a string
changes; a catalogue that has drifted silently mistranslates a settings screen.
"""

import datetime
import io
import os
import re
import sys

DOMAIN = 'conversion-tracking-for-openai-ads'
ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')
PLUGIN = os.path.join(ROOT, 'packages', 'wordpress')

# The five gettext functions this plugin uses, each taking the literal first and
# the domain second. Anything with a plural or a context would need its own
# pattern, and adding one is the moment to reach for wp i18n make-pot instead.
CALL = re.compile(
    r"\\?\b(?:esc_html__|esc_attr__|esc_html_e|esc_attr_e|__|_e)\s*\(\s*"
    r"('(?:[^'\\]|\\.)*'|\"(?:[^\"\\]|\\.)*\")\s*,\s*'" + re.escape(DOMAIN) + r"'",
    re.S,
)

TRANSLATORS = re.compile(r"/\*\s*translators:\s*(.*?)\*/", re.S)


def php_literal(literal):
    """Decode a PHP string literal the way PHP would."""
    body = literal[1:-1]

    if literal.startswith("'"):
        # Single quotes: only \' and \\ mean anything.
        return body.replace("\\'", "'").replace('\\\\', '\\')

    return (
        body.replace('\\"', '"')
        .replace('\\n', '\n')
        .replace('\\t', '\t')
        .replace('\\\\', '\\')
    )


def po_escape(value):
    return (
        value.replace('\\', '\\\\')
        .replace('"', '\\"')
        .replace('\n', '\\n')
        .replace('\t', '\\t')
    )


def php_files():
    for directory, _, names in os.walk(PLUGIN):
        parts = directory.replace('\\', '/').split('/')

        if 'vendor' in parts or 'tests' in parts or 'node_modules' in parts:
            continue

        for name in sorted(names):
            if name.endswith('.php'):
                yield os.path.join(directory, name)


def main():
    entries = {}
    scanned = 0

    for path in sorted(php_files()):
        source = io.open(path, encoding='utf-8').read()
        lines = source.split('\n')
        relative = os.path.relpath(path, ROOT).replace('\\', '/')
        scanned += 1

        for match in CALL.finditer(source):
            text = php_literal(match.group(1))
            line_no = source.count('\n', 0, match.start()) + 1

            # A translators: comment, if one sits within a few lines above. It is
            # the only thing standing between a translator and a bare "%s".
            window = '\n'.join(lines[max(0, line_no - 6):line_no])
            comments = TRANSLATORS.findall(window)

            entry = entries.setdefault(text, {'refs': [], 'comment': None})
            entry['refs'].append('%s:%d' % (relative, line_no))

            if comments and entry['comment'] is None:
                entry['comment'] = ' '.join(comments[-1].split())

    today = datetime.date.today().isoformat()

    out = [
        '# Copyright (C) %s Webaround' % today[:4],
        '# This file is distributed under the GPL-2.0-or-later license.',
        '#, fuzzy',
        'msgid ""',
        'msgstr ""',
        '"Project-Id-Version: Conversion Tracking for OpenAI Ads\\n"',
        '"Report-Msgid-Bugs-To: '
        'https://github.com/webaroundlabs/openai-ads-toolkit/issues\\n"',
        '"MIME-Version: 1.0\\n"',
        '"Content-Type: text/plain; charset=UTF-8\\n"',
        '"Content-Transfer-Encoding: 8bit\\n"',
        '"POT-Creation-Date: %sT00:00:00+00:00\\n"' % today,
        '"X-Generator: scripts/make-pot.py\\n"',
        '"X-Domain: %s\\n"' % DOMAIN,
        '',
    ]

    for text in sorted(entries):
        entry = entries[text]

        if entry['comment']:
            out.append('#. translators: %s' % entry['comment'])

        for ref in entry['refs']:
            out.append('#: %s' % ref)

        out.append('msgid "%s"' % po_escape(text))
        out.append('msgstr ""')
        out.append('')

    target_dir = os.path.join(PLUGIN, 'languages')
    os.makedirs(target_dir, exist_ok=True)
    target = os.path.join(target_dir, '%s.pot' % DOMAIN)

    io.open(target, 'w', encoding='utf-8', newline='\n').write('\n'.join(out))

    print('scanned %d PHP files' % scanned)
    print('wrote %d strings to %s' % (len(entries), os.path.relpath(target, ROOT)))

    if not entries:
        print('\nNo strings found. Has the text domain changed?')
        return 1

    return 0


if __name__ == '__main__':
    sys.exit(main())
