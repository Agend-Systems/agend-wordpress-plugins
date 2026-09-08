#!/usr/bin/env bash
# Shared helpers for the release scripts under .github/scripts.
# Sourced, not executed directly.
set -euo pipefail

# List every plugin slug in the repo, one per line, sorted.
# A "plugin" is any top-level agend-* directory that has a matching main file
# agend-<slug>/agend-<slug>.php.
agend_list_slugs() {
	local dir slug
	for dir in agend-*/; do
		slug="${dir%/}"
		if [[ -f "${slug}/${slug}.php" ]]; then
			echo "${slug}"
		fi
	done | sort
}

# Print a single plugin header field's raw value, or nothing if absent.
# Usage: agend_get_header <main-file> <Header Name>
#
# Uses only POSIX/BSD-portable `sed -E` (no GNU-only grep -P/\K), so this
# works the same on a macOS dev machine and on the ubuntu-latest runner.
agend_get_header() {
	local file=$1 name=$2
	# Header lines look like: " * Version:           1.11.0" with variable
	# spacing before/after the colon. Only look at the first ~40 lines (the
	# docblock) so we never match a define() or comment further down.
	head -n 40 "${file}" 2>/dev/null \
		| sed -n -E "s/^[[:space:]]*\*[[:space:]]*${name}:[[:space:]]*(.*)\$/\1/p" \
		| head -n 1 \
		| sed -E 's/[[:space:]]+$//'
}

# Print the value of a `define( 'SOMETHING_VERSION', '1.2.3' );` style
# constant in a main plugin file, or nothing if no such VERSION constant
# exists in the file (agend-directory-sync and agend-entitlement-mirror
# currently define no version constant at all, which is allowed).
agend_get_version_constant() {
	local file=$1
	# `|| true` matters: with `set -o pipefail` (on for every script that
	# sources this file), a no-match grep exiting 1 would otherwise abort
	# the whole script even though "no VERSION constant" is a valid state.
	{ grep -E "define\([[:space:]]*'[A-Z0-9_]*_VERSION'[[:space:]]*,[[:space:]]*'[^']+'" "${file}" 2>/dev/null || true; } \
		| head -n 1 \
		| sed -E "s/.*_VERSION'[[:space:]]*,[[:space:]]*'([^']+)'.*/\1/"
}

# Validate a version string looks semver-ish. Returns 0/1.
agend_is_semver() {
	local version=$1
	[[ "${version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]]
}

# The git tag name for a given plugin slug + version.
agend_tag_for() {
	local slug=$1 version=$2
	echo "${slug}-v${version}"
}
