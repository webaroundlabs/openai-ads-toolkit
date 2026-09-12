#!/usr/bin/env bash
#
# Assemble the installable WordPress plugin.
#
# The plugin ships its own `vendor/` because WordPress has no autoloader and a
# site cannot be asked to run Composer. So the zip is the source plus the
# runtime dependencies, and nothing else: no tests, no dev tooling, no analyser
# configs, and no copy of the specification, which is a development-time asset
# and has no business on a production site.
#
# Run `composer install --no-dev` in packages/wordpress first - the release
# workflow does, and so should you.
#
#     bash scripts/build-plugin.sh
#
# Produces build/conversion-tracking-for-openai-ads.zip, everything under a
# single directory named for the plugin slug - which is what WordPress expects of
# an uploaded plugin, and what the plugin directory uses as its permanent
# identity. The slug does NOT begin with "openai", deliberately: the WordPress
# plugin directory refuses a slug that starts with somebody else's trademark.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source_dir="$root/packages/wordpress"
stage="$root/build/conversion-tracking-for-openai-ads"
archive="$root/build/conversion-tracking-for-openai-ads.zip"

if [ ! -d "$source_dir/vendor" ]; then
	echo "packages/wordpress/vendor is missing. Run:" >&2
	echo "  (cd packages/wordpress && composer install --no-dev)" >&2
	exit 1
fi

# A dev install leaves PHPUnit and PHPStan in vendor/, which would be shipped to
# every site. Catching it here is the difference between a 200KB plugin and a
# 20MB one containing an analyser.
if [ -d "$source_dir/vendor/phpunit" ]; then
	echo "vendor/ contains dev dependencies. Re-run composer install with --no-dev." >&2
	exit 1
fi

rm -rf "$stage" "$archive"
mkdir -p "$stage"

# An explicit allowlist rather than an exclude list: a file added later is
# absent from the zip until somebody names it, which is the safe direction. The
# opposite - shipping something nobody meant to - is how a .env or a test
# fixture reaches a production site.
for path in conversion-tracking-for-openai-ads.php uninstall.php readme.txt README.md LICENSE src assets languages vendor; do
	if [ ! -e "$source_dir/$path" ]; then
		echo "Expected $path in packages/wordpress, but it is missing." >&2
		exit 1
	fi

	cp -R "$source_dir/$path" "$stage/"
done

# The plugin's own LICENSE is GPLv2-or-later, matching its header and readme.txt -
# not the repository's MIT one. The MIT-licensed core it bundles keeps its own
# licence under vendor/, which is what MIT asks for.

# The core arrives through Composer's path repository, which on most systems is
# a symlink into packages/php - and on the ones where it is not, a full copy
# including that package's own dev dependencies. Either way what a site needs is
# two things, so the directory is rebuilt from scratch rather than filtered. The
# difference is a 200KB plugin instead of a 20MB one carrying an analyser.
core="$stage/vendor/webaround/openai-ads"

rm -rf "$core"
mkdir -p "$core"
cp -R "$root/packages/php/src" "$core/src"
cp "$root/packages/php/composer.json" "$core/composer.json"

find "$stage" -type d \( -name tests -o -name .github -o -name .phpunit.cache \) -prune -exec rm -rf {} + 2>/dev/null || true
find "$stage" -type f \( -name 'phpunit*' -o -name 'phpstan*' -o -name '.php-cs-fixer*' -o -name '*.dist' \) -delete 2>/dev/null || true

# `zip` is not installed everywhere a maintainer might run this; Python is,
# because the specification checks already need it.
if command -v zip > /dev/null 2>&1; then
	( cd "$root/build" && zip -qr conversion-tracking-for-openai-ads.zip conversion-tracking-for-openai-ads )
else
	python -c "import shutil, sys; shutil.make_archive(sys.argv[1], 'zip', sys.argv[2], 'conversion-tracking-for-openai-ads')" 		"${archive%.zip}" "$root/build"
fi

size="$(du -sh "$stage" | cut -f1)"

echo "Built $archive from $size of files"

# A plugin this size means something unintended came along - almost always a dev
# dependency. Better to fail the release than to ship it to every site.
if [ "$(du -sk "$stage" | cut -f1)" -gt 5120 ]; then
	echo "The staged plugin is over 5MB. Something unintended is in it." >&2
	exit 1
fi
