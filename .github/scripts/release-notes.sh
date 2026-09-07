#!/usr/bin/env bash
# Generate release notes for one plugin's release: commits touching that
# plugin's directory since the plugin's own previous tag (not the whole
# repo's history), plus a full-changelog compare link.
#
# Usage: release-notes.sh <slug> <new-tag> [output-file]
#   output-file defaults to stdout.

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

slug="${1:?usage: release-notes.sh <slug> <new-tag> [output-file]}"
new_tag="${2:?usage: release-notes.sh <slug> <new-tag> [output-file]}"
out_file="${3:-}"

repo="${GITHUB_REPOSITORY:-Agend-Systems/agend-wordpress-plugins}"

# Newest-first list of this plugin's own previous tags. `new_tag` should not
# exist yet, but filter it out defensively in case this is re-run after the
# tag was already pushed.
previous_tag="$(
	git tag --list "${slug}-v*" --sort=-v:refname \
		| grep -vx "${new_tag}" \
		| head -n 1 || true
)"

if [[ -n "${previous_tag}" ]]; then
	range="${previous_tag}..HEAD"
else
	range="HEAD"
fi

commits="$(git log --no-merges --format='- %s (%h)' ${range} -- "${slug}/" || true)"

{
	if [[ -z "${commits}" ]]; then
		echo "No changes recorded for this release."
	else
		echo "${commits}"
	fi
	echo
	if [[ -n "${previous_tag}" ]]; then
		echo "**Full changelog**: https://github.com/${repo}/compare/${previous_tag}...${new_tag}"
	fi
} > "${out_file:-/dev/stdout}"
