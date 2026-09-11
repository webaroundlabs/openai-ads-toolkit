# Releasing

Four registries, four different ways to be stuck with a mistake:

| Registry | What it publishes | If it is wrong |
|---|---|---|
| Packagist | `webaroundlabs/openai-ads`, `…-laravel` | A tag cannot be reused. Yanking it breaks anyone who pinned it. |
| npm | `@webaroundlabs/openai-ads` | Unpublishing is allowed for 72 hours and then only by support. |
| WordPress plugin directory | `openai-ads` | SVN trunk reaches every installed site on its next update check, usually within hours. |
| GTM Community Gallery | the two templates | Reviewed by a human; a correction is another review. |

So publishing is started by pushing a tag — a deliberate act — never by merging
to `main`. `.github/workflows/release.yml` does the rest, and it re-runs every
check against the tagged commit first: CI having been green on a commit is not
the same as it being green on the artefact, and the artefact is what people
install.

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
   - `packages/wordpress/openai-ads.php` — both the `Version:` header and
     `OPENAI_ADS_VERSION`
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

6. **The workflow then:** verifies, publishes to npm with provenance, builds the
   WordPress plugin zip, and opens a **draft** GitHub release with the zip
   attached. Draft rather than published, so somebody reads the notes before the
   world does.

7. **Packagist needs nothing** — it watches the repository and picks the tag up
   itself, provided the GitHub service hook is configured once.

## The WordPress plugin directory

Not wired into the workflow, because the plugin has not been approved there yet
and a deploy step pointing at an SVN repository that does not exist is worse
than no step at all.

Once it is approved, add a job using `10up/action-wordpress-plugin-deploy` with
`SVN_USERNAME` / `SVN_PASSWORD` secrets, `BUILD_DIR: build/openai-ads`, and
`.distignore` honoured. Until then, upload `build/openai-ads.zip` by hand.

Build it locally with:

```bash
(cd packages/wordpress && composer install --no-dev)
bash scripts/build-plugin.sh
```

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
