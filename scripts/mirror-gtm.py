"""Generate the single-template repositories the GTM Community Template Gallery needs.

Google indexes a gallery template from a repository whose root *is* the template:
one template per repository, and `template.tpl`, `metadata.yaml`, `LICENSE` and
`README.md` directly in the root, with `LICENSE` carrying the Apache 2.0 text -
nothing but that text, and its copyright line filled in. Two of the templates
here live in subdirectories of a monorepo that is MIT, so each is mirrored into a
repository shaped the way the gallery reads.

    python scripts/mirror-gtm.py --into build/gtm-mirrors            # build both, no network
    python scripts/mirror-gtm.py --package gtm-web --push URL        # sync the mirror
    python scripts/mirror-gtm.py --package gtm-web --push URL --tag v0.2.0

These are not the Packagist mirrors, and they are not built the same way.
`split.yml` force-pushes a `git subtree split`, which is safe there because
Packagist only ever reads the tag. The gallery instead reads a **commit sha** out
of `metadata.yaml` and serves the template exactly as it stood at that commit, for
as long as that version is published. So this history is append-only and never
force-pushed: rewriting it would unpublish every version ever submitted.

That is also why a release is two commits. A version entry names the commit
holding the template it publishes, and no commit can contain its own sha, so the
sync lands first and the entry naming it lands second. The gallery reads the
metadata at the tip and the template at the sha it names, which is what those two
commits are.
"""

import argparse
import hashlib
import json
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

MONOREPO = 'https://github.com/webaroundlabs/openai-ads-toolkit'

# The homepage the gallery links to. The monorepo rather than the mirror: the
# mirror has nothing in it that the monorepo does not, and everything the
# monorepo has that the mirror does not - the other packages, the specification,
# the issue tracker people should actually use.
HOMEPAGE = MONOREPO

# The gallery wants documentation separately, and here it genuinely is separate:
# the package README carries the field rules, the deduplication contract and what
# each template deliberately refuses.
DOCUMENTATION = MONOREPO + '/blob/main/packages/%s/README.md'

MIRRORS = {
    'gtm-web': 'openai-ads-gtm-web',
    'gtm-server': 'openai-ads-gtm-server',
}

# gtm-collect is deliberately absent. It posts to a collection endpoint that only
# exists once this project's WordPress plugin is installed and switched on, so in
# the gallery it would be a tag that does nothing for almost everyone who found
# it there. It stays MIT, and installs by importing the file.

# The four files the gallery expects, and nothing else. An allowlist rather than
# an exclude list, for the same reason build-plugin.sh uses one: a file added to
# the package later is absent from the mirror until somebody names it here, which
# is the safe direction to fail in.
GALLERY_FILES = ('template.tpl', 'metadata.yaml', 'LICENSE', 'README.md')

# The files the package itself must carry. metadata.yaml is generated, so it is
# not one of them - it is bookkeeping that only means anything in the mirror,
# where the shas it names are reachable.
SOURCE_FILES = {'template.tpl', 'LICENSE', 'README.md'}

# The gallery asks for two things that pull against each other: the licence file
# must be "only Apache 2.0", and the appendix's `Copyright [yyyy] [name of
# copyright owner]` must be filled in. So the file is the canonical text from
# https://www.apache.org/licenses/LICENSE-2.0.txt with exactly one line changed,
# and that is what is checked - put the placeholder back and the digest must be
# the canonical one. Checking it this way rather than pinning the digest of our
# own file says which of the two rules was broken.
APACHE_2_SHA256 = 'cfc7749b96f63bd31c3c42b5c471bf756814053e847c10f3eb003417bc523d30'

APACHE_2_PLACEHOLDER = b'   Copyright [yyyy] [name of copyright owner]\n'
COPYRIGHT = b'   Copyright 2026 Webaround Labs\n'

# Agreeing to the gallery's developer terms is done by a person, in the Tag
# Manager template editor, and the editor writes this section into the exported
# file. It cannot be forged here, and a template without it cannot be submitted.
TERMS_OF_SERVICE = '___TERMS_OF_SERVICE___'

RELATIVE_LINK = re.compile(r'\]\((\.\.\/[^)\s]+)\)')
REFERENCE_LINK = re.compile(r'^\[[^\]]+\]:\s*\.\.\/', re.M)

VERSION_ENTRY = re.compile(r'^  - sha: ([0-9a-f]{40})$')
CHANGE_NOTES = re.compile(r'^    changeNotes: (".*")$')

# Goes directly under the title, not at the foot of the page. The gallery links
# people straight here, and somebody who arrived with a problem needs to know
# where it goes before they scroll - especially since a mirror has no issue
# tracker of its own.
HEADER = """
> **Generated.** This repository is [`packages/{package}`]({monorepo}/tree/main/packages/{package})
> of the OpenAI Ads Toolkit, published on its own because the Community Template
> Gallery indexes one template per repository. **Bugs, questions and pull requests
> belong in [the monorepo]({monorepo}/issues)** — anything committed here is
> replaced on the next sync.
"""


class Failure(Exception):
    """Something that must stop a publish rather than reach the gallery."""


# --- the mirror tree --------------------------------------------------------

def absolutize(readme, package):
    """Rewrite the package README's relative links to absolute monorepo ones.

    In the monorepo `../spec/user.json` resolves. In a repository whose root is
    the package it is a 404, and the gallery links people straight at it.
    """
    def replace(match):
        target = (ROOT / 'packages' / package / match.group(1)).resolve()

        try:
            path = target.relative_to(ROOT).as_posix()
        except ValueError:
            raise Failure('packages/%s/README.md links to %s, which is outside the repository'
                          % (package, match.group(1)))

        if not target.exists():
            raise Failure('packages/%s/README.md links to %s, which does not exist'
                          % (package, match.group(1)))

        return ']({monorepo}/{kind}/main/{path})'.format(
            monorepo=MONOREPO,
            kind='tree' if target.is_dir() else 'blob',
            path=path,
        )

    rewritten = RELATIVE_LINK.sub(replace, readme)

    # Whatever the regex could not reach would be a broken link in the gallery,
    # so it is a failure rather than a link left as it was.
    leftover = REFERENCE_LINK.search(rewritten) or ('](../' in rewritten)

    if leftover:
        raise Failure('packages/%s/README.md still has a relative link after rewriting' % package)

    return rewritten


def render_metadata(package, versions):
    """The gallery's metadata.yaml.

    Newest version first: the gallery reads the list in reverse chronological
    order, so appending would publish the oldest template forever.

    Every value is written as a JSON string, which is also a valid YAML
    double-quoted scalar. Plain scalars would be fine until the day a change note
    contains ": ", which YAML reads as a nested mapping and rejects.
    """
    lines = [
        'homepage: %s' % json.dumps(HOMEPAGE),
        'documentation: %s' % json.dumps(DOCUMENTATION % package),
    ]

    if not versions:
        lines.append('versions: []')
        return '\n'.join(lines) + '\n'

    lines.append('versions:')

    for version in versions:
        lines.append('  - sha: %s' % version['sha'])
        lines.append('    changeNotes: %s' % json.dumps(version['changeNotes']))

    return '\n'.join(lines) + '\n'


def parse_metadata(text):
    """Read back what render_metadata wrote, strictly.

    Strictly, because the alternative to failing on an unexpected line is
    silently dropping a published version - and a version the gallery still
    serves but the file no longer lists is unrecoverable without git archaeology.
    """
    versions = []

    for line in text.splitlines():
        entry = VERSION_ENTRY.match(line)
        notes = CHANGE_NOTES.match(line)

        if entry:
            versions.append({'sha': entry.group(1), 'changeNotes': ''})
        elif notes:
            if not versions:
                raise Failure('metadata.yaml has change notes before any version')
            versions[-1]['changeNotes'] = json.loads(notes.group(1))
        elif line.startswith(('homepage:', 'documentation:')) or line in ('versions:', 'versions: []', ''):
            continue
        else:
            raise Failure('metadata.yaml is not in the shape this script writes: %r' % line)

    return versions


def write_tree(package, into, versions):
    """Write the four gallery files into `into`, and remove anything else."""
    source = ROOT / 'packages' / package

    present = {p.name for p in source.iterdir() if p.is_file()}

    if present != SOURCE_FILES:
        raise Failure('packages/%s holds %s; the gallery mirror carries exactly %s'
                      % (package, sorted(present), sorted(SOURCE_FILES)))

    licence = (source / 'LICENSE').read_bytes()

    if COPYRIGHT not in licence:
        raise Failure('packages/%s/LICENSE does not carry the copyright line the gallery '
                      'asks for: %r' % (package, COPYRIGHT.decode().strip()))

    canonical = licence.replace(COPYRIGHT, APACHE_2_PLACEHOLDER)

    if hashlib.sha256(canonical).hexdigest() != APACHE_2_SHA256:
        raise Failure('packages/%s/LICENSE is not the Apache 2.0 text with only its copyright '
                      'line filled in; the gallery wants that file and nothing else' % package)

    into.mkdir(parents=True, exist_ok=True)

    shutil.copyfile(source / 'template.tpl', into / 'template.tpl')
    shutil.copyfile(source / 'LICENSE', into / 'LICENSE')

    readme = absolutize((source / 'README.md').read_text(encoding='utf-8'), package)
    title, _, body = readme.partition('\n')

    if not title.startswith('# '):
        raise Failure('packages/%s/README.md must open with its title, so the '
                      'generated notice has somewhere to go' % package)

    readme = title + '\n' + HEADER.format(package=package, monorepo=MONOREPO) + body

    (into / 'README.md').write_text(readme, encoding='utf-8', newline='\n')
    (into / 'metadata.yaml').write_text(
        render_metadata(package, versions), encoding='utf-8', newline='\n')

    for path in into.iterdir():
        if path.name != '.git' and path.name not in GALLERY_FILES:
            shutil.rmtree(path) if path.is_dir() else path.unlink()


# --- git --------------------------------------------------------------------

def git(*args, cwd, check=True):
    done = subprocess.run(['git'] + list(args), cwd=str(cwd),
                          capture_output=True, text=True)

    if check and done.returncode != 0:
        raise Failure('git %s failed: %s' % (' '.join(args), done.stderr.strip()))

    return done.stdout.strip() if done.returncode == 0 else None


def anything_staged(work):
    done = subprocess.run(['git', 'diff', '--cached', '--quiet'],
                          cwd=str(work), capture_output=True, text=True)
    return done.returncode != 0


def differs_from_published(work, sha):
    """Has anything the gallery serves changed since the version at `sha`?

    metadata.yaml is excluded, and has to be: every published version adds an
    entry to it, so comparing it would say "changed" forever and each tag would
    republish a file the reviewer has already seen.
    """
    done = subprocess.run(
        ['git', 'diff', '--quiet', sha, 'HEAD', '--'] + [f for f in GALLERY_FILES if f != 'metadata.yaml'],
        cwd=str(work), capture_output=True, text=True)
    return done.returncode != 0


def redact(remote):
    return re.sub(r'//[^@/]+@', '//', remote)


def change_notes(package, tag):
    """What changed in this package since the previous tag, on one line.

    One line because that is what the gallery shows, and what every template
    published there writes. A version whose notes need a paragraph is a version
    whose notes belong in the changelog that `documentation` already points at.
    """
    previous = git('describe', '--tags', '--abbrev=0', '--match', 'v*',
                   tag + '^', cwd=ROOT, check=False)

    if previous is None:
        return 'Initial release (%s).' % tag

    subjects = git('log', '--format=%s', '%s..%s' % (previous, tag),
                   '--', 'packages/' + package, cwd=ROOT).splitlines()

    if not subjects:
        return tag

    notes = '%s - %s' % (tag, '; '.join(subjects))

    return notes if len(notes) <= 300 else notes[:297].rstrip(' ;-') + '...'


def push(package, remote, tag):
    with tempfile.TemporaryDirectory() as scratch:
        work = Path(scratch) / 'mirror'

        print('Cloning %s' % redact(remote))
        git('clone', '--quiet', remote, str(work), cwd=ROOT)

        # An empty mirror has no commits and no branch, only the HEAD the clone
        # guessed - from the server where git is new enough to ask, and from the
        # client's init.defaultBranch where it is not. Naming it outright rather
        # than trusting the guess: this runs once per mirror, and a first push
        # that landed on master is not something the next run repairs.
        if git('rev-parse', '--verify', 'HEAD', cwd=work, check=False) is None:
            git('symbolic-ref', 'HEAD', 'refs/heads/main', cwd=work)
        else:
            branch = git('symbolic-ref', '--short', 'HEAD', cwd=work)

            if branch != 'main':
                raise Failure("the mirror's branch is %r; the gallery mirrors track main" % branch)

        # The mirrors are generated, so their authorship carries no information.
        # Pinning it keeps a local run and a CI run producing the same commits.
        git('config', 'user.name', 'github-actions[bot]', cwd=work)
        git('config', 'user.email',
            '41898282+github-actions[bot]@users.noreply.github.com', cwd=work)

        metadata = work / 'metadata.yaml'
        versions = parse_metadata(metadata.read_text(encoding='utf-8')) if metadata.exists() else []

        write_tree(package, work, versions)
        git('add', '--all', cwd=work)

        source_sha = git('rev-parse', '--short', 'HEAD', cwd=ROOT)

        if anything_staged(work):
            git('commit', '--quiet', '-m',
                'Sync %s from openai-ads-toolkit %s' % (package, source_sha), cwd=work)
            print('Synced packages/%s' % package)
        else:
            print('Nothing changed in packages/%s' % package)

        if tag:
            publish(package, work, tag, versions)

        # Never --force. The gallery serves each published version from the sha
        # metadata.yaml names, and a sha that is no longer reachable is a
        # template that no longer loads.
        git('push', 'origin', 'HEAD:refs/heads/main', cwd=work)
        print('Pushed to %s' % redact(remote))


def publish(package, work, tag, versions):
    head = git('rev-parse', 'HEAD', cwd=work, check=False)

    if head is None:
        raise Failure('the mirror has no commit to publish')

    if TERMS_OF_SERVICE not in (work / 'template.tpl').read_text(encoding='utf-8'):
        raise Failure(
            'packages/%s/template.tpl has no %s section, so the gallery cannot accept it. '
            'Tick "Agree to the Community Template Gallery Terms of Service" on the Info '
            'tab of the Tag Manager template editor, export the template, and commit that.'
            % (package, TERMS_OF_SERVICE))

    # versions[0], not versions[-1]: the gallery reads the list newest first.
    if versions and not differs_from_published(work, versions[0]['sha']):
        print('%s serves the same template as the published version; no new entry' % tag)
        return

    versions.insert(0, {'sha': head, 'changeNotes': change_notes(package, tag)})

    (work / 'metadata.yaml').write_text(
        render_metadata(package, versions), encoding='utf-8', newline='\n')

    git('add', 'metadata.yaml', cwd=work)
    git('commit', '--quiet', '-m',
        'Publish %s to the Community Template Gallery' % tag, cwd=work)

    print('Published %s at %s' % (tag, head))


# --- entry point ------------------------------------------------------------

def main(argv):
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument('--package', choices=sorted(MIRRORS),
                        help='one package; the default is every gallery package')
    parser.add_argument('--into', type=Path,
                        help='build the mirror trees into this directory and stop')
    parser.add_argument('--push', metavar='REMOTE',
                        help='clone that remote, sync it and push; needs one --package')
    parser.add_argument('--tag', help='with --push, publish this tag as a gallery version')

    args = parser.parse_args(argv)
    packages = [args.package] if args.package else sorted(MIRRORS)

    if bool(args.into) == bool(args.push):
        parser.error('pass either --into or --push')

    if args.push and len(packages) != 1:
        parser.error('--push needs one --package')

    if args.tag and not args.push:
        parser.error('--tag only means something with --push')

    for package in packages:
        if args.push:
            push(package, args.push, args.tag)
        else:
            into = args.into / MIRRORS[package]
            write_tree(package, into, [])
            print('%s -> %s' % (package, into))

    return 0


if __name__ == '__main__':
    try:
        sys.exit(main(sys.argv[1:]))
    except Failure as failure:
        print('FAILED:', failure)
        sys.exit(1)
