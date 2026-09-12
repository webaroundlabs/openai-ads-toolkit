# Releasing

Four registries, four different ways to be stuck with a mistake:

| Registry | What it publishes | If it is wrong |
|---|---|---|
| Packagist | `webaround/openai-ads`, `…-laravel`, from the generated mirrors | A tag cannot be reused. Yanking it breaks anyone who pinned it. |
| npm | `@webaround/openai-ads` | Unpublishing is allowed for 72 hours and then only by support. |
| WordPress plugin directory | `conversion-tracking-for-openai-ads` | SVN trunk reaches every installed site on its next update check, usually within hours. |
| GTM Community Gallery | the two templates | Reviewed by a human; a correction is another review. |

So publishing is started by pushing a tag — a deliberate act — never by merging
to `main`. `.github/workflows/release.yml` does the rest, and it re-runs every
check against the tagged commit first: CI having been green on a commit is not
the same as it being green on the artefact, and the artefact is what people
install.

A tag is still not the last word. The npm job runs in the `npm` environment,
which requires a maintainer's approval, so a tag builds and verifies and then
waits. The tag is the intent; the approval is the point of no return. It exists
because the table above is one-directional: every other mistake in this
repository can be fixed with another commit, and this one cannot.

npm authentication is Trusted Publishing — the job exchanges its OIDC token for
a short-lived credential — so there is no `NPM_TOKEN` to leak or rotate. It is
configured once on npmjs.com, on the package's settings, as:

| Field | Value |
|---|---|
| Organization or username | `webaroundlabs` |
| Repository | `openai-ads-toolkit` |
| Workflow filename | `release.yml` |
| Environment | `npm` |

npm registers a trusted publisher **against a package**, and a package does not
exist until something publishes it. So the first release cannot use Trusted
Publishing, and bootstraps instead: a granular access token scoped to
`@webaround/*`, stored as the `NPM_TOKEN` secret **of the `npm` environment**
(not of the repository — only a job that declares the environment can read it,
and that job is the one behind the approval gate). `release.yml` passes it as
`NODE_AUTH_TOKEN` for exactly that release.

Afterwards: configure the trusted publisher, delete the `NPM_TOKEN` secret and
the `env:` block that reads it, and turn on **Require two-factor authentication
and disallow tokens** on the package. Provenance does not depend on any of this
— it comes from the workflow's OIDC identity either way — so `0.1.0` is signed
like every version after it.

## Cutting a release

1. **Decide the version.** `0.x` while the public API can still move. A change
   to identity normalization, hashing or event-id generation is a behavioural
   change to deduplication and gets a major bump once `1.0` exists — see
   `CLAUDE.md` §13 and §15.

2. **Update the changelog.** Move `[Unreleased]` to the new version with a date,
   and add a fresh `[Unreleased]`. Anything under **Changed** that touches
   normalization or hashing belongs at the top of the section; that is what an
   integrator needs to read before upgrading.

3. **Set the version everywhere it is written down:**

   - `packages/js/package.json`
   - `packages/wordpress/conversion-tracking-for-openai-ads.php` — both the
     `Version:` header and `OPENAI_ADS_VERSION`
   - `packages/wordpress/readme.txt` — `Stable tag`

   The Composer packages carry no `version` field, deliberately: Packagist reads
   the tag, and a hand-written one is a second source of truth that will
   eventually disagree.

   ```bash
   python scripts/check-version.py 0.2.0
   ```

   The release workflow runs the same check, so a mismatch fails the release
   rather than publishing a version nobody can trace.

4. **Run everything locally.** `composer check` in each PHP package,
   `npm run check` in `packages/js`, plus `verify.py` and `check-secrets.py`.

5. **Tag and push.**

   ```bash
   git tag -a v0.2.0 -m "v0.2.0"
   git push origin v0.2.0
   ```

6. **The workflow then:** verifies, waits for the `npm` environment to be
   approved, publishes to npm with provenance, builds the WordPress plugin zip,
   and opens a **draft** GitHub release with the zip attached. Draft rather than
   published, so somebody reads the notes before the world does.

7. **Packagist is fed by the mirrors, not by this repository.** Packagist reads
   the `composer.json` at a repository root and has no concept of a package in a
   subdirectory, so submitting this repository to it does not work. The `Split`
   workflow mirrors `packages/php` and `packages/laravel` into
   `openai-ads-php` and `openai-ads-laravel`, carries the tag over to each, and
   Packagist watches those two.

   The php mirror has to be indexed before the laravel one resolves, because
   `openai-ads-laravel` requires `webaround/openai-ads` from Packagist rather
   than from the path repository it uses here. Both are pushed by the same
   workflow run, so the window is seconds — but if a `composer require` of the
   Laravel package right after a release cannot find the core, that is what it
   is, and it fixes itself.

## The Packagist mirrors

`.github/workflows/split.yml` runs `git subtree split` on every push to `main`
and on every tag, and force-pushes the result. History is preserved and the
split is deterministic, so each run republishes the same commits rather than
inventing new ones. Nothing is ever developed in a mirror; anything committed
there directly is lost on the next push.

Two things are set up once and then never again:

1. **`SPLIT_TOKEN`.** `GITHUB_TOKEN` is scoped to this repository and cannot
   push to another one, so the workflow needs a token of its own: a fine-grained
   personal access token, granted **Contents: read and write** on
   `openai-ads-php` and `openai-ads-laravel` and nothing else, stored as the
   repository secret `SPLIT_TOKEN`. Until it exists the workflow fails loudly,
   which is preferable to mirrors that quietly drift.

2. **Submit each mirror to Packagist** — `openai-ads-php` first, since the other
   requires it. Packagist picks up later tags itself through the GitHub hook it
   installs when you submit.

`packages/laravel/composer.json` points its path repository at `../php*` rather
than `../php`. The glob is deliberate and load-bearing: Composer **errors** on a
non-glob path repository whose directory does not exist, and in the mirror it
does not exist. A glob that matches nothing is skipped silently, so the same
`composer.json` resolves the core from `../php` here and from Packagist there.
Removing the asterisk makes the published Laravel package impossible to install.

## The WordPress plugin directory

Not wired into the workflow, because the plugin has not been approved there yet
and a deploy step pointing at an SVN repository that does not exist is worse
than no step at all.

Once it is approved, add a job using `10up/action-wordpress-plugin-deploy` with
`SVN_USERNAME` / `SVN_PASSWORD` secrets, `BUILD_DIR: build/conversion-tracking-for-openai-ads`, and
`.distignore` honoured. Until then, upload `build/conversion-tracking-for-openai-ads.zip` by hand.

Build it locally with:

```bash
python scripts/make-pot.py                        # refresh the translation catalogue
(cd packages/wordpress && composer install --no-dev)
bash scripts/build-plugin.sh
```

The slug is **`conversion-tracking-for-openai-ads`**, and it matters: the plugin
directory refuses a slug that begins with somebody else's trademark, and the slug
is permanent once published. The text domain matches it, because translations
from translate.wordpress.org are keyed on the slug.

The script uses an **allowlist**, not an exclude list: a file added later is
absent from the zip until somebody names it. That is the safe direction — the
opposite is how a `.env` or a test fixture reaches a production site. It also
rebuilds the bundled core from `packages/php/src` rather than copying whatever
Composer's path repository left behind, and refuses to build anything over 5MB,
because a plugin that size means a dev dependency came along.

## The GTM templates

Submitted by hand to the Community Template Gallery, which needs a public
repository containing `template.tpl` and a `metadata.yaml`. Each update is
reviewed. Before submitting either one, confirm:

- `python packages/spec/verify.py` passes — it checks the event dropdowns match
  the catalogue and that the **web** template contains no credential.
- `packages/js/tests/gtmParity.test.ts` passes — it checks the server template's
  hand-written identity normalization still matches the browser package exactly.

## After publishing

Install what you shipped, not what you built. `composer require` the tag into a
scratch project and `npm install` the published version; a package that works in
the monorepo and fails from a registry is a `files` or `autoload` mistake, and
the only way to see it is from outside.
