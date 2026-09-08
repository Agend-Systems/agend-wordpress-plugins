#!/usr/bin/env bash
# Fetch every GitHub Release on this repo as a single JSON array, for
# build-manifest.py to consume. Requires GH_TOKEN (or an authenticated gh)
# and GITHUB_REPOSITORY (owner/repo).
#
# Usage: fetch-releases.sh [output-file]
#   output-file defaults to stdout.

set -euo pipefail

repo="${GITHUB_REPOSITORY:?GITHUB_REPOSITORY must be set, e.g. Agend-Systems/agend-wordpress-plugins}"
out_file="${1:-}"

# `--slurp` wraps each page in an outer array (an array of pages), so
# flatten one level to get a plain array of release objects.
if [[ -n "${out_file}" ]]; then
	gh api "repos/${repo}/releases" --paginate --slurp | jq 'flatten(1)' > "${out_file}"
	echo "Wrote $(jq 'length' "${out_file}") release(s) to ${out_file}" >&2
else
	gh api "repos/${repo}/releases" --paginate --slurp | jq 'flatten(1)'
fi
