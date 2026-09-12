"""Check that a release tag agrees with every manifest that carries a version.

A tag saying v0.2.0 while `package.json` still says 0.1.0 publishes an npm
package whose version nobody can trace back to a commit, and a WordPress plugin
header that disagrees with `Stable tag` in readme.txt makes the directory serve
one version while reporting another. Both are cheaper to catch here than to
explain afterwards.

    python scripts/check-version.py 0.2.0

Exits non-zero listing every file that disagrees.
"""

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

SEMVER = re.compile(r'^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$')


def js_version(path):
    return json.loads(path.read_text(encoding='utf-8')).get('version')


def php_constant(path, name):
    match = re.search(r"const\s+%s\s*=\s*'([^']+)'" % name, path.read_text(encoding='utf-8'))
    return match.group(1) if match else None


def plugin_header(path, field):
    match = re.search(r'^\s*\*?\s*%s:\s*(\S+)' % field, path.read_text(encoding='utf-8'), re.M)
    return match.group(1) if match else None


def readme_field(path, field):
    match = re.search(r'^%s:\s*(\S+)' % field, path.read_text(encoding='utf-8'), re.M)
    return match.group(1) if match else None


def main(expected):
    if not SEMVER.match(expected):
        print('Not a semantic version: %r' % expected)
        return 1

    wordpress = ROOT / 'packages/wordpress/conversion-tracking-for-openai-ads.php'

    checks = [
        ('packages/js/package.json', js_version(ROOT / 'packages/js/package.json')),
        ('the plugin header (Version)', plugin_header(wordpress, 'Version')),
        ('the plugin header (OPENAI_ADS_VERSION)',
         php_constant(wordpress, 'OPENAI_ADS_VERSION')),
        ('packages/wordpress/readme.txt (Stable tag)',
         readme_field(ROOT / 'packages/wordpress/readme.txt', 'Stable tag')),
    ]

    # Composer packages carry no version field on purpose - Packagist reads the
    # tag - so there is nothing to compare for packages/php and packages/laravel.

    failures = [
        '%s says %r, expected %r' % (where, found, expected)
        for where, found in checks
        if found != expected
    ]

    print('checked %d version declarations against %s' % (len(checks), expected))

    if failures:
        print('\nFAILURES:')
        for failure in failures:
            print('  -', failure)
        return 1

    print('PASS: every manifest agrees with the tag')
    return 0


if __name__ == '__main__':
    if len(sys.argv) != 2:
        print(__doc__)
        sys.exit(2)

    sys.exit(main(sys.argv[1]))
