"""Fail the build if a credential could reach a browser.

The Conversions API key is the one value in this toolkit that must never leave a
server. Everything else can be fixed after the fact; a key published in a page,
a bundle or a public container cannot be un-published, only rotated.

This runs over tracked files and, when it exists, over the built JavaScript
bundle - which is the artefact that actually ships to browsers.

    python scripts/check-secrets.py

Exits non-zero on the first set of problems, listing each one.
"""

import os
import re
import subprocess
import sys

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# Shapes that look like a real credential rather than a placeholder. Test
# fixtures deliberately use obviously-fake values such as
# "secret-key-must-never-be-rendered", which must NOT trip this.
CREDENTIAL_SHAPES = [
    (r'sk-[A-Za-z0-9]{16,}', 'an OpenAI-style secret key'),
    (r'Bearer\s+[A-Za-z0-9_\-]{20,}', 'a hard-coded bearer token'),
    (r'["\']?(?:api[_-]?key|capi[_-]?key)["\']?\s*[:=]\s*["\'][A-Za-z0-9_\-]{20,}["\']',
     'an assigned API key literal'),
]

# Files that legitimately discuss credentials without containing one.
ALLOWED_PATHS = {
    'scripts/check-secrets.py',
}

# Anything the browser can read. A credential here is unrecoverable.
BROWSER_ARTEFACTS = [
    'packages/js/dist',
    'packages/wordpress/assets',
    'packages/gtm-web',
]

BROWSER_FORBIDDEN = ['apikey', 'api_key', 'capi_key', 'capikey', 'authorization', 'bearer ']

failures = []


def tracked_files():
    output = subprocess.run(
        ['git', 'ls-files'],
        cwd=REPO, capture_output=True, text=True, check=True,
    ).stdout
    return [line for line in output.splitlines() if line]


def read(path):
    try:
        with open(os.path.join(REPO, path), encoding='utf-8') as handle:
            return handle.read()
    except (UnicodeDecodeError, IsADirectoryError, FileNotFoundError):
        return None


# --- 1. no credential-shaped literal anywhere in the repository -------------
for path in tracked_files():
    if path in ALLOWED_PATHS:
        continue

    content = read(path)

    if content is None:
        continue

    for pattern, description in CREDENTIAL_SHAPES:
        match = re.search(pattern, content)

        if match:
            failures.append('%s contains %s: %r' % (path, description, match.group(0)[:40]))

# --- 2. nothing the browser receives may even mention a credential ----------
# Not because a mention is itself a leak, but because it means somebody has
# started plumbing one towards the front end, which is the mistake worth
# catching on the commit that introduces it rather than the release that ships
# it.
for root_dir in BROWSER_ARTEFACTS:
    absolute = os.path.join(REPO, root_dir)

    if not os.path.isdir(absolute):
        continue

    for directory, _, filenames in os.walk(absolute):
        for filename in filenames:
            full = os.path.join(directory, filename)
            relative = os.path.relpath(full, REPO).replace(os.sep, '/')
            content = read(relative)

            if content is None:
                continue

            lowered = content.lower()

            for forbidden in BROWSER_FORBIDDEN:
                if forbidden in lowered:
                    failures.append(
                        '%s reaches the browser and mentions %r' % (relative, forbidden)
                    )

# --- 3. no environment file was ever committed ------------------------------
for path in tracked_files():
    name = os.path.basename(path)

    if name == '.env' or (name.startswith('.env.') and not name.endswith('.example')):
        failures.append('%s is tracked; environment files must never be committed' % path)

built = os.path.isdir(os.path.join(REPO, 'packages/js/dist'))

print('scanned %d tracked files%s' % (
    len(tracked_files()),
    ' and the built JavaScript bundle' if built else ' (JavaScript bundle not built)',
))

if failures:
    print('\nFAILURES:')
    for failure in failures:
        print('  -', failure)
    sys.exit(1)

print('PASS: no credential can reach a browser from this repository')
