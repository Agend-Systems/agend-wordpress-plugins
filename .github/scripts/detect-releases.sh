#!/usr/bin/env bash
# Find plugins whose header Version has no matching git tag, and validate
# every plugin's header/constant consistency along the way.
#
# Usage:
#   detect-releases.sh                 # emit a release matrix (needs tags fetched)
#   detect-releases.sh --plugin SLUG   # restrict to one plugin
#   detect-releases.sh --check-only    # validate only, no tag lookup, used as a
#                                       # PR guardrail (tests.yml) where tags may
#                                       # not be fetched and nothing should be
#                                       # "released"
#
# On success in default mode, prints a JSON object to stdout:
#   {"include":[{"slug":"agend-embed","version":"1.0.0","tag":"agend-embed-v1.0.0"}, ...]}
# and, when GITHUB_OUTPUT is set, also writes `matrix` and `has_matches`
# outputs there.
#
# Any header/constant/semver problem is reported with a `::error::` line and
# causes a non-zero exit, in both modes.

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

# shellcheck source=lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

check_only=0
only_slug=""

while [[ $# -gt 0 ]]; do
	case "$1" in
		--check-only)
			check_only=1
			shift
			;;
		--plugin)
			only_slug="$2"
			shift 2
			;;
		*)
			echo "::error::detect-releases.sh: unknown argument: $1"
			exit 1
			;;
	esac
done

errors=0
entries=()

while IFS= read -r slug; do
	if [[ -n "${only_slug}" && "${slug}" != "${only_slug}" ]]; then
		continue
	fi

	main_file="${slug}/${slug}.php"
	version="$(agend_get_header "${main_file}" "Version")"

	if [[ -z "${version}" ]]; then
		echo "::error file=${main_file}::${slug}: no Version header found"
		errors=$((errors + 1))
		continue
	fi

	if ! agend_is_semver "${version}"; then
		echo "::error file=${main_file}::${slug}: Version header '${version}' is not a valid semver string"
		errors=$((errors + 1))
		continue
	fi

	constant_version="$(agend_get_version_constant "${main_file}")"
	if [[ -n "${constant_version}" && "${constant_version}" != "${version}" ]]; then
		echo "::error file=${main_file}::${slug}: header Version (${version}) does not match the *_VERSION constant (${constant_version})"
		errors=$((errors + 1))
		continue
	fi

	requires_plugins="$(agend_get_header "${main_file}" "Requires Plugins")"
	if [[ -n "${requires_plugins}" ]]; then
		echo "::notice file=${main_file}::${slug}: Requires Plugins: ${requires_plugins} (informational only, not enforced)"
	fi

	if [[ "${check_only}" -eq 1 ]]; then
		echo "OK ${slug} ${version}"
		continue
	fi

	tag="$(agend_tag_for "${slug}" "${version}")"
	if git tag -l "${tag}" | grep -qx "${tag}"; then
		echo "OK ${slug} ${version} already tagged as ${tag}, skipping"
		continue
	fi

	echo "PENDING ${slug} ${version} -> ${tag}"
	entries+=("{\"slug\":\"${slug}\",\"version\":\"${version}\",\"tag\":\"${tag}\"}")
done < <(agend_list_slugs)

if [[ "${errors}" -gt 0 ]]; then
	echo "::error::detect-releases.sh: ${errors} plugin(s) failed validation"
	exit 1
fi

if [[ "${check_only}" -eq 1 ]]; then
	exit 0
fi

json_list="$(IFS=,; echo "${entries[*]:-}")"
matrix="{\"include\":[${json_list}]}"
has_matches="false"
[[ "${#entries[@]}" -gt 0 ]] && has_matches="true"

echo "${matrix}"

if [[ -n "${GITHUB_OUTPUT:-}" ]]; then
	{
		echo "matrix=${matrix}"
		echo "has_matches=${has_matches}"
	} >> "${GITHUB_OUTPUT}"
fi
