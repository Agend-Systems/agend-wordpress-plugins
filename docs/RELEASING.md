# Releasing

This repo ships eight WordPress plugins from one place. Each plugin gets
its own GitHub Release, cut automatically by
`.github/workflows/release.yml` when its header `Version` changes on
`main`. A static `manifest.json`, republished to the `gh-pages` branch
after every release, is what the WordPress-side updater (built
separately) polls to check for and install updates.

## Tag format

`<slug>-v<version>`, e.g. `agend-apps-core-v1.12.0`. A release is cut for
a plugin whenever its header `Version` has no tag of this form pointing
at it yet. There is no separate "release PR" or manual trigger step:
merging a version bump to `main` is the trigger.

## Cutting a release

1. Bump the plugin's header `Version` in `agend-<slug>/agend-<slug>.php`.
2. If the plugin also defines a `define( '<PREFIX>_VERSION', '...' )`
   constant in that same file, bump it to match. The `versions` job in
   `.github/workflows/tests.yml` fails the PR if the two disagree, or if
   the version isn't valid semver (`MAJOR.MINOR.PATCH[-prerelease]`).
3. Open a PR, get it reviewed, merge to `main`.
4. `.github/workflows/release.yml` runs on the push to `main`, detects
   the plugin has a Version with no matching tag, builds
   `dist/<slug>.zip`, generates release notes from the commits that
   touched `agend-<slug>/` since that plugin's previous tag, and creates
   the GitHub Release and tag.
5. Once the release succeeds, the `manifest` job rebuilds
   `manifest.json` and the rest of the `gh-pages` site and publishes it.

Bumping several plugins' versions in one PR/push releases all of them;
the `release` job matrix runs one plugin at a time (`max-parallel: 1`)
so the manifest step never races a half-finished release.

Release notes only include commits under that plugin's own directory --
not repo-wide noise from the other seven plugins.

### Every PR must bump the Version it ships

The `bump` job in `.github/workflows/tests.yml` runs on every pull
request targeting `main` and fails the PR if it changes a plugin's
shipped files without bumping that plugin's header `Version`. Without
this, a PR can merge to `main`, change plugin code, and release
nothing, silently, because `release.yml` only fires on an unmatched
header `Version`.

"Shipped files" means whatever `.github/scripts/build-zip.sh` actually
packs into the release zip. The following are exempt, so a PR that only
touches them never needs a bump:

- anything under `<slug>/tests/`
- anything under `<slug>/node_modules/`
- for `agend-apps-core` and `agend-content-access` only, anything under
  that plugin's `src/` (the built output that ships lives in `build/`).
  Every other plugin keeps `src/` in the zip, and a change there needs a bump
- any `*.md` file other than a `README.md`, which build-zip keeps at
  any depth
- dotfiles and build artefacts build-zip.sh also drops: `.git*`,
  `phpunit*`, `.phpunit.cache`, `.DS_Store`, `composer.lock`

The check also rejects a `Version` that goes down instead of up (an
accidental downgrade), and enforces two more rules for each plugin the
PR bumps.

**The Version must be above the latest release.** The highest released
version is read from the plugin's git tags (`<slug>-v*`, the glob built
from `agend_tag_for` in `.github/scripts/lib.sh`), and the PR's `Version`
must be strictly greater. This catches a branch cut before a release:
cut at `1.20.0`, main has since released `1.25.0`, and the PR bumps to
`1.21.0`. That is greater than where the branch started but already
behind what shipped, so it fails. A `Version` equal to the latest tag
fails as well (that version already shipped, so the release workflow
would skip it and this change would ship nowhere). A plugin with no tags
yet has no floor. The job needs tags fetched (`fetch-tags: true` on
checkout); run locally without tags, the script prints a notice instead
of passing silently.

**No other open PR may bump the same plugin to the same version.** The
check lists the other open PRs whose base is `main` (drafts included,
this PR excluded) and reads each one's header `Version` for the plugin
from `refs/pull/<n>/head`. A PR counts as bumping the plugin only when
its `Version` differs from `main`'s current one. Then:

- the same `Version` is an error naming the other PR;
- a higher `Version` is a warning annotation only, since the released
  version rule already stops the higher PR merging first;
- a lower `Version` is ignored.

A PR stacked on another PR's branch (base is not `main`) is not listed,
so it is ignored until it is retargeted to `main`.

The job gets `pull-requests: read` for this, and `gh` is given
`GH_TOKEN`, `PR_NUMBER` and `GITHUB_REPOSITORY` through `env`. When `gh`
or the token is missing (a local run) the comparison is skipped with a
notice; pass `--skip-open-prs` to skip it deliberately, or set
`GH_TOKEN=$(gh auth token) PR_NUMBER=<n> GITHUB_REPOSITORY=<owner/repo>`
to run it locally.

Known limitation: the job runs only on events for its own PR. If two
PRs end up at the same version because one of them was bumped later,
the PR that was not touched does not re-run, so its check can be stale.
Re-run the `bump` job on the other PR (or push to it) to refresh it. The
check on the PR you are about to merge is the one that counts.

The script has its own tests, run by the `versions` job:
`.github/scripts/tests/check-version-bumps.test.sh`.

For the rare PR that legitimately changes shipped files without a
release, e.g. CI or tooling changes that live inside a plugin directory,
a maintainer can apply the `skip-version-bump` label to the PR and the
`bump` job is skipped.

## The manifest and gh-pages

`manifest.json` describes the latest non-prerelease release of every
plugin: version, requirements, changelog/description HTML, and a
`package` URL the updater downloads. It's built by
`.github/scripts/build-manifest.py` from the repo's GitHub Releases
(fetched via `.github/scripts/fetch-releases.sh`) and published to the
`gh-pages` branch (`force_orphan: true`, so the branch never accumulates
history -- each publish is a fresh orphan commit). The published site
also carries a plain `index.html` and, per plugin, a
`<slug>/index.html` landing page so each plugin's `Update URI` header
(`https://agend-systems.github.io/agend-wordpress-plugins/<slug>`)
resolves to something.

### `AGEND_PACKAGE_HOST` (private vs. public repo)

While this repository is **private**, release assets are not publicly
downloadable even with a direct link, so the manifest job (with the
`AGEND_PACKAGE_HOST` repository variable at its default, `pages`) also
copies each plugin's latest release zip into the `gh-pages` site under
`packages/<slug>.zip` and points `manifest.json`'s `package` field at
that instead of the release asset.

**Once the repo goes public**, set the `AGEND_PACKAGE_HOST` repository
variable (Settings -> Secrets and variables -> Actions -> Variables) to
`releases`. The manifest job will then point `package` straight at the
GitHub Release asset (`.../releases/download/<tag>/<slug>.zip`) and stop
mirroring zips into `gh-pages`, since a public repo's release assets are
downloadable directly.

### Re-running the manifest without a release

Use the workflow's `workflow_dispatch` trigger (Actions ->
Release -> Run workflow) with `force_manifest` set to `true` (and
`plugin` left blank) to rebuild and republish `manifest.json` /
`gh-pages` on demand, e.g. after flipping `AGEND_PACKAGE_HOST`, without
needing to cut a new release first.

## One-time maintainer setup

A few things only need doing once, in GitHub's own settings, not in
this repo:

- **Enable Pages**: Settings -> Pages -> Source: "Deploy from a branch",
  branch `gh-pages`, folder `/ (root)`. The first successful `manifest`
  job run creates the `gh-pages` branch; Pages can't be enabled against
  it until that branch exists.
- **Actions workflow permissions**: Settings -> Actions -> General ->
  Workflow permissions. The release workflow sets `contents: write` at
  the job level for the jobs that need it, so the repository default can
  stay "Read repository contents permission", but if org policy forces a
  stricter default, confirm it isn't clamped below what the job-level
  permission requests.
- **Protect `main`**: Settings -> Branches -> add a protection rule for
  `main` (require PRs, required status checks including the `tests.yml`
  jobs) so a version bump only reaches `main` -- and therefore only
  triggers a release -- through review.
- **Once the repo goes public**, also turn on "Require approval for
  first-time contributors" under Settings -> Actions -> General, so a
  first PR from an unknown contributor can't run workflows (including
  this one) without a maintainer approving the run.
