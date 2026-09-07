#!/usr/bin/env bash
# Build a release zip for one plugin: dist/<slug>.zip, with <slug>/ as the
# top-level folder inside the archive, matching how WordPress expects a
# plugin zip to unpack.
#
# Usage: build-zip.sh <slug> [output-dir]
#   output-dir defaults to "dist" under the current directory.

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

slug="${1:?usage: build-zip.sh <slug> [output-dir]}"
out_dir="${2:-dist}"

if [[ ! -d "${slug}" ]]; then
	echo "::error::build-zip.sh: no such plugin directory: ${slug}"
	exit 1
fi

mkdir -p "${out_dir}"
out_dir="$(cd "${out_dir}" && pwd)"
zip_path="${out_dir}/${slug}.zip"

staging="$(mktemp -d)"
trap 'rm -rf "${staging}"' EXIT

rsync_args=(
	-a
	--exclude 'tests/'
	--exclude 'node_modules/'
	--exclude '.git*'
	--exclude 'phpunit*'
	--exclude '.phpunit.cache'
	--exclude '.DS_Store'
	--exclude 'composer.lock'
)

# Blocks ship built into agend-apps-core/build/; the src/ tree that builds
# them has no place in the runtime zip. This must come before the generic
# `--include '*/'` below: rsync filter rules are first-match-wins, and a
# blanket directory include would otherwise shadow this exclude.
if [[ "${slug}" == "agend-apps-core" ]]; then
	rsync_args+=(--exclude 'src/')
fi

rsync_args+=(
	--include '*/'
	--include 'README.md'
	--exclude '*.md'
)

rsync "${rsync_args[@]}" "${slug}/" "${staging}/${slug}/"

rm -f "${zip_path}"
(
	cd "${staging}"
	zip -rq "${zip_path}" "${slug}"
)

echo "Built ${zip_path}"
echo "--- zip listing (head) ---"
unzip -l "${zip_path}" | head -n 25
