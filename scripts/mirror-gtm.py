"""Generate the single-template repositories the GTM Community Template Gallery needs.

Google indexes a gallery template from a repository whose root *is* the template:
one template per repository, and `template.tpl`, `metadata.yaml`, `LICENSE` and
`README.md` directly in the root, with `LICENSE` carrying the Apache 2.0 text and
nothing else. Two of the templates here live in subdirectories of a monorepo that
is MIT, so each is mirrored into a repository shaped the way the gallery reads.

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

# The canonical Apache License 2.0, byte for byte, from
# https://www.apache.org/licenses/LICENSE-2.0.txt. The gallery checks the file,
# and the usual way to fail that check is somebody helpfully adding a copyright
# line to the top of it.
APACHE_2_SHA256 = 'cfc7749b96f63bd31c3c42b5c471bf756814053e847c10f3eb003417bc523d30'

RELATIVE_LINK = re.compile(r'\]\((\.\.\/[^)\s]+)\)')
REFERENCE_LINK = re.compile(r'^\[[^\]]+\]:\s*\.\.\/', re.M)

VERSION_ENTRY = re.compile(r'^- sha: ([0-9a-f]{40})$')

FOOTER = """
---

This repository is generated from [`packages/{package}`]({monorepo}/tree/main/packages/{package})
in the OpenAI Ads Toolkit, so the Community Template Gallery has the
single-template repository it requires. Issues and pull requests belong in the
monorepo; anything committed here is replaced on the next sync.
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


def render_metadata(versions):
    """The gallery's metadata.yaml. The last entry is the published version."""
    lines = ['homepage: %s' % HOMEPAGE]

    if not versions:
        lines.append('versions: []')
        return '\n'.join(lines) + '\n'

    lines.append('versions:')

    for version in versions:
        lines.append('- sha: %s' % version['sha'])
        lines.append('  changeNotes: |-')
        for line in version['changeNotes'].splitlines():
            lines.append(('    ' + line).rstrip())

    return '\n'.join(lines) + '\n'


def parse_metadata(text):
    """Read back what render_metadata wrote, strictly.

    Strictly, because the alternative to failing on an unexpected line is
    silently dropping a published version - and a version the gallery still
    serves but the file no longer lists is unrecoverable without git archaeology.
    """
    versions, current = [], None

    for line in text.splitlines():
        entry = VERSION_ENTRY.match(line)

        if entry:
            current = {'sha': entry.group(1), 'changeNotes': []}
            versions.append(current)
        elif line.startswith('    ') and current is not None:
            current['changeNotes'].append(line[4:])
        elif line == '' and current is not None:
            current['changeNotes'].append('')
        elif line.startswith('homepage:') or line in ('versions:', 'versions: []', ''):
            continue
        elif line == '  changeNotes: |-':
            continue
        else:
            raise Failure('metadata.yaml is not in the shape this script writes: %r' % line)

    for version in versions:
        version['changeNotes'] = '\n'.join(version['changeNotes']).strip('\n')

    return versions


def write_tree(package, into, versions):
    """Write the four gallery files into `into`, and remove anything else."""
    source = ROOT / 'packages' / package

    present = {p.name for p in source.iterdir() if p.is_file()}

    if present != SOURCE_FILES:
        raise Failure('packages/%s holds %s; the gallery mirror carries exactly %s'
                      % (package, sorted(present), sorted(SOURCE_FILES)))

    licence = (source / 'LICENSE').read_bytes()

    if hashlib.sha256(licence).hexdigest() != APACHE_2_SHA256:
        raise Failure('packages/%s/LICENSE is not the Apache 2.0 text; the gallery '
                      'rejects the repository without it' % package)

    into.mkdir(parents=True, exist_ok=True)

    shutil.copyfile(source / 'template.tpl', into / 'template.tpl')
    shutil.copyfile(source / 'LICENSE', into / 'LICENSE')

    readme = absolutize((source / 'README.md').read_text(encoding='utf-8'), package)
    readme = readme.rstrip('\n') + '\n' + FOOTER.format(package=package, monorepo=MONOREPO)

    (into / 'README.md').write_text(readme, encoding='utf-8', newline='\n')
    (into / 'metadata.yaml').write_text(render_metadata(versions), encoding='utf-8', newline='\n')

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
    """What changed in this package since the previous tag."""
    previous = git('describe', '--tags', '--abbrev=0', '--match', 'v*',
                   tag + '^', cwd=ROOT, check=False)

    if previous is None:
        return 'Initial release (%s).' % tag

    subjects = git('log', '--format=%s', '%s..%s' % (previous, tag),
                   '--', 'packages/' + package, cwd=ROOT).splitlines()

    if not subjects:
        return tag

    notes = [tag, '']
    notes += ['- %s' % subject for subject in subjects[:20]]

    if len(subjects) > 20:
        notes.append('- ...and %d more.' % (len(subjects) - 20))

    return '\n'.join(notes)


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

    if versions and not differs_from_published(work, versions[-1]['sha']):
        print('%s serves the same template as the last published version; no new entry' % tag)
        return

    versions.append({'sha': head, 'changeNotes': change_notes(package, tag)})

    (work / 'metadata.yaml').write_text(
        render_metadata(versions), encoding='utf-8', newline='\n')

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
