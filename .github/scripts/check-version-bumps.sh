#!/usr/bin/env bash
# Fail a PR that changes a plugin's shipped files without bumping that
# plugin's header Version. A merged PR that changes plugin code but never
# bumps the Version silently releases nothing (release.yml only fires on an
# unmatched header Version, see docs/RELEASING.md).
#
# Usage:
#   check-version-bumps.sh [--base <ref>] [--head <ref>] [--skip-open-prs]
#
#   --base <ref>   Ref to compare against. Defaults to ${GITHUB_BASE_REF},
#                   the target branch name GitHub Actions sets on a
#                   pull_request event. If the ref doesn't resolve as given,
#                   "origin/<ref>" is tried next.
#   --head <ref>   Ref to compare. Defaults to HEAD.
#   --skip-open-prs
#                   Skip the open-PR comparison (rule 2 below). For local runs.
#
# Beyond "did the Version go up since the merge base", each bumped plugin is
# held to two more rules:
#
#   1. Released-version floor. The head Version must be strictly greater than
#      the highest released version, read from the plugin's git tags (the glob
#      comes from agend_tag_for, so it tracks the tag format). A branch cut
#      before a release can otherwise bump to a version that is already out.
#      Needs tags fetched (fetch-tags: true on checkout in CI).
#   2. Other open PRs. Compared against the other open PRs whose base is the
#      same branch (drafts included; a PR stacked on another PR's branch is
#      not listed, so it is ignored until retargeted). Another PR that bumps
#      the same plugin to the SAME version is an error; to a HIGHER version
#      is a ::warning only (rule 1 stops the higher one merging first). Reads
#      PR_NUMBER, GITHUB_REPOSITORY and GH_TOKEN from the environment, and is
#      skipped with a notice when they or `gh` are unavailable.
#
# Uses three-dot (merge-base) diff semantics: the comparison is against
# "git merge-base base head", not base directly, so commits that landed on
# the base branch after this PR's branch point aren't attributed to this
# PR's diff.
#
# Which changed files require a bump is derived from build-zip.sh's rsync
# --exclude list (the actual shipped-files authority): tests/, node_modules/,
# agend-apps-core/src/, non-README markdown, and its dotfile/lockfile
# excludes. Keep the filter below and build-zip.sh's rsync_args in step; if
# one changes, the other must too.

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

# shellcheck source=lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

base_arg=""
head_arg="HEAD"
skip_open_prs=0

while [[ $# -gt 0 ]]; do
	case "$1" in
		--base)
			base_arg="$2"
			shift 2
			;;
		--head)
			head_arg="$2"
			shift 2
			;;
		--skip-open-prs)
			skip_open_prs=1
			shift
			;;
		*)
			echo "::error::check-version-bumps.sh: unknown argument: $1"
			exit 1
			;;
	esac
done

if [[ -z "${base_arg}" ]]; then
	base_arg="${GITHUB_BASE_REF:-}"
fi

if [[ -z "${base_arg}" ]]; then
	echo "::error::check-version-bumps.sh: no base ref given and GITHUB_BASE_REF is unset. Pass --base <ref>."
	exit 1
fi

resolve_ref() {
	local ref=$1
	if git rev-parse --verify --quiet "${ref}^{commit}" >/dev/null; then
		echo "${ref}"
		return 0
	fi
	if git rev-parse --verify --quiet "origin/${ref}^{commit}" >/dev/null; then
		echo "origin/${ref}"
		return 0
	fi
	return 1
}

base="$(resolve_ref "${base_arg}")" || {
	echo "::error::check-version-bumps.sh: could not resolve base ref '${base_arg}' (tried '${base_arg}' and 'origin/${base_arg}')"
	exit 1
}

head="$(resolve_ref "${head_arg}")" || {
	echo "::error::check-version-bumps.sh: could not resolve head ref '${head_arg}' (tried '${head_arg}' and 'origin/${head_arg}')"
	exit 1
}

merge_base="$(git merge-base "${base}" "${head}")" || {
	echo "::error::check-version-bumps.sh: could not find a merge base between '${base}' and '${head}'"
	exit 1
}

# Returns 0 (true) if the given path, relative to a plugin's own directory
# (e.g. "tests/foo.php", "agend-apps-core.php"), ships in the release zip
# and therefore requires a Version bump when changed. Mirrors build-zip.sh's
# rsync excludes.
path_requires_bump() {
	local slug=$1 rel=$2
	local base_name
	base_name="$(basename "${rel}")"

	case "${rel}" in
		tests/*) return 1 ;;
		node_modules/*) return 1 ;;
	esac

	if [[ "${slug}" == "agend-apps-core" ]]; then
		case "${rel}" in
			src/*) return 1 ;;
		esac
	fi

	# build-zip.sh keeps README.md but drops every other *.md. Its rsync
	# `--include 'README.md'` has no leading slash, so it matches that
	# basename at any depth, not just the plugin root; match on basename
	# here for the same reason.
	if [[ "${base_name}" == *.md && "${base_name}" != "README.md" ]]; then
		return 1
	fi

	case "${base_name}" in
		.git*) return 1 ;;
		phpunit*) return 1 ;;
		.phpunit.cache) return 1 ;;
		.DS_Store) return 1 ;;
		composer.lock) return 1 ;;
	esac

	return 0
}

# Compare two semver strings (MAJOR.MINOR.PATCH[-prerelease]).
# Echoes "lt", "eq", or "gt" for `a` relative to `b`. A prerelease sorts
# before its release (1.2.3-beta.1 < 1.2.3), per semver precedence. Not
# handled: multi-field/numeric-vs-alphanumeric prerelease precedence beyond
# a plain string comparison, which is more than this guardrail needs.
semver_compare() {
	local a=$1 b=$2
	local a_core=${a%%-*} b_core=${b%%-*}
	local a_pre="" b_pre=""
	[[ "${a}" == *-* ]] && a_pre=${a#*-}
	[[ "${b}" == *-* ]] && b_pre=${b#*-}

	local a_major a_minor a_patch b_major b_minor b_patch
	IFS='.' read -r a_major a_minor a_patch <<<"${a_core}"
	IFS='.' read -r b_major b_minor b_patch <<<"${b_core}"

	local pair part_a part_b
	for pair in "${a_major}:${b_major}" "${a_minor}:${b_minor}" "${a_patch}:${b_patch}"; do
		part_a="${pair%%:*}"
		part_b="${pair##*:}"
		if ((part_a < part_b)); then
			echo "lt"
			return
		elif ((part_a > part_b)); then
			echo "gt"
			return
		fi
	done

	# Core versions equal: a prerelease sorts before a release.
	if [[ -z "${a_pre}" && -z "${b_pre}" ]]; then
		echo "eq"
	elif [[ -z "${a_pre}" ]]; then
		echo "gt"
	elif [[ -z "${b_pre}" ]]; then
		echo "lt"
	elif [[ "${a_pre}" == "${b_pre}" ]]; then
		echo "eq"
	elif [[ "${a_pre}" < "${b_pre}" ]]; then
		echo "lt"
	else
		echo "gt"
	fi
}

# Read a plugin main file's header Version at a given ref, without checking
# anything out: write the blob to a temp file and reuse agend_get_header on
# it, rather than duplicating its parsing here. Applies to head as well as
# base: --head may name any ref, and even when it defaults to HEAD the
# working tree can carry uncommitted changes that must not leak into the
# comparison. Echoes "" if the file doesn't exist at that ref.
version_at_ref() {
	local ref=$1 file=$2 tmp version
	if ! git cat-file -e "${ref}:${file}" 2>/dev/null; then
		echo ""
		return 0
	fi
	tmp="$(mktemp)"
	git show "${ref}:${file}" >"${tmp}" 2>/dev/null
	version="$(agend_get_header "${tmp}" "Version")"
	rm -f "${tmp}"
	echo "${version}"
}

# Highest released version for a plugin, from its git tags. Prints
# "<version> <tag>", or nothing if the plugin has no semver tag. The glob and
# the prefix to strip both come from agend_tag_for, not a hardcoded format.
highest_released() {
	local slug=$1 glob prefix tag version best="" best_tag=""
	glob="$(agend_tag_for "${slug}" '*')"
	prefix="$(agend_tag_for "${slug}" '')"
	while IFS= read -r tag; do
		[[ -z "${tag}" ]] && continue
		version="${tag#"${prefix}"}"
		agend_is_semver "${version}" || continue
		if [[ -z "${best}" || "$(semver_compare "${version}" "${best}")" == "gt" ]]; then
			best="${version}"
			best_tag="${tag}"
		fi
	done < <(git tag -l "${glob}")
	if [[ -n "${best}" ]]; then
		echo "${best} ${best_tag}"
	fi
	return 0
}

base_branch="${base_arg#origin/}"

# Open PRs targeting the same branch, loaded once on first use. The state is
# "unloaded", "ready" or "skipped". Each PR head is fetched from
# refs/pull/<n>/head (which exists for fork PRs too) into a private ref, so
# its plugin headers can be read with version_at_ref.
open_prs_state="unloaded"
open_pr_numbers=()
open_pr_titles=()
open_pr_refs=()

load_open_prs() {
	[[ "${open_prs_state}" == "unloaded" ]] || return 0
	open_prs_state="skipped"

	if [[ "${skip_open_prs}" -eq 1 ]]; then
		echo "::notice::check-version-bumps.sh: skipping the open-PR comparison (--skip-open-prs)"
		return 0
	fi
	if ! command -v gh >/dev/null 2>&1; then
		echo "::notice::check-version-bumps.sh: skipping the open-PR comparison, gh is not installed"
		return 0
	fi
	if [[ -z "${GH_TOKEN:-}" && -z "${GITHUB_TOKEN:-}" ]]; then
		echo "::notice::check-version-bumps.sh: skipping the open-PR comparison, GH_TOKEN is not set (locally: GH_TOKEN=\$(gh auth token))"
		return 0
	fi
	if [[ -z "${PR_NUMBER:-}" || -z "${GITHUB_REPOSITORY:-}" ]]; then
		echo "::notice::check-version-bumps.sh: skipping the open-PR comparison, PR_NUMBER and GITHUB_REPOSITORY are not both set"
		return 0
	fi

	local json rows num sha title count=0
	if ! json="$(gh pr list --repo "${GITHUB_REPOSITORY}" --base "${base_branch}" --state open \
		--json number,headRefOid,isDraft,title --limit 200)"; then
		echo "::error::check-version-bumps.sh: could not list open PRs with gh (does the job have pull-requests: read?)"
		errors=$((errors + 1))
		return 0
	fi
	if ! rows="$(printf '%s' "${json}" | python3 -c '
import json, sys
for pr in json.load(sys.stdin):
    title = "".join(c if c.isprintable() else " " for c in str(pr.get("title", "")))
    print("%s\t%s\t%s" % (pr["number"], pr.get("headRefOid", ""), title))
')"; then
		echo "::error::check-version-bumps.sh: could not parse the open PR list from gh"
		errors=$((errors + 1))
		return 0
	fi

	while IFS=$'\t' read -r num sha title; do
		[[ -z "${num}" ]] && continue
		count=$((count + 1))
		[[ "${num}" == "${PR_NUMBER}" ]] && continue
		if ! git fetch --no-tags --quiet origin "+refs/pull/${num}/head:refs/agend-check/pr-${num}" 2>/dev/null; then
			echo "::warning::check-version-bumps.sh: could not fetch the head of PR #${num} (${title}); its Version was not compared"
			continue
		fi
		open_pr_numbers+=("${num}")
		open_pr_titles+=("${title}")
		open_pr_refs+=("refs/agend-check/pr-${num}")
	done <<<"${rows}"

	if [[ "${count}" -ge 200 ]]; then
		echo "::warning::check-version-bumps.sh: 200 open PRs listed, the limit; any beyond that were not compared"
	fi
	open_prs_state="ready"
}

# Rule 2: compare this PR's head Version for a plugin with every other open
# PR that bumps the same plugin. Returns 1 if another PR bumps it to the same
# version. A plugin counts as bumped by another PR only when that PR's
# Version differs from the base branch's current Version.
check_open_prs() {
	local slug=$1 main_file=$2 head_version=$3
	local main_version other cmp i failed=0
	agend_is_semver "${head_version}" || return 0
	load_open_prs
	[[ "${open_prs_state}" == "ready" ]] || return 0

	main_version="$(version_at_ref "${base}" "${main_file}")"
	for ((i = 0; i < ${#open_pr_numbers[@]}; i++)); do
		other="$(version_at_ref "${open_pr_refs[$i]}" "${main_file}")"
		[[ -z "${other}" || "${other}" == "${main_version}" ]] && continue
		if ! agend_is_semver "${other}"; then
			echo "::notice::${slug}: PR #${open_pr_numbers[$i]} has Version '${other}', which is not valid semver; not compared"
			continue
		fi
		cmp="$(semver_compare "${other}" "${head_version}")"
		if [[ "${cmp}" == "eq" ]]; then
			echo "::error file=${main_file}::${slug}: PR #${open_pr_numbers[$i]} (${open_pr_titles[$i]}) also bumps ${slug} to ${head_version}. Only one open PR can release a version; pick the next one."
			failed=1
		elif [[ "${cmp}" == "gt" ]]; then
			echo "::warning file=${main_file}::${slug}: PR #${open_pr_numbers[$i]} (${open_pr_titles[$i]}) bumps ${slug} to ${other}, higher than this PR's ${head_version}. That PR cannot merge before this one."
		fi
	done
	[[ "${failed}" -eq 0 ]]
}

errors=0

if [[ -z "$(git tag -l | head -n 1)" ]]; then
	echo "::notice::check-version-bumps.sh: no tags are fetched, so the released-version floor is not checked (git fetch --tags)"
fi

while IFS= read -r slug; do
	main_file="${slug}/${slug}.php"

	changed_files=()
	while IFS= read -r path; do
		[[ -n "${path}" ]] && changed_files+=("${path}")
	done < <(git diff --name-only "${merge_base}" "${head}" -- "${slug}/")

	shipped_changed=()
	for path in "${changed_files[@]:-}"; do
		[[ -z "${path}" ]] && continue
		rel="${path#"${slug}"/}"
		if path_requires_bump "${slug}" "${rel}"; then
			shipped_changed+=("${path}")
		fi
	done

	if [[ "${#shipped_changed[@]}" -eq 0 ]]; then
		echo "OK ${slug} unchanged (no shipped-file changes)"
		continue
	fi

	# Read both versions straight from git (see version_at_ref above).
	base_version="$(version_at_ref "${merge_base}" "${main_file}")"
	head_version="$(version_at_ref "${head}" "${main_file}")"

	if [[ -z "${base_version}" ]]; then
		if [[ -z "${head_version}" ]]; then
			echo "::error file=${main_file}::${slug}: no Version header found (${#shipped_changed[@]} shipped files changed)"
			errors=$((errors + 1))
			continue
		fi
		if ! check_open_prs "${slug}" "${main_file}" "${head_version}"; then
			echo "FAIL ${slug} new plugin at ${head_version} (another open PR bumps it to the same version)"
			errors=$((errors + 1))
			continue
		fi
		echo "OK ${slug} new plugin at ${head_version} (${#shipped_changed[@]} shipped files changed)"
		continue
	fi

	if [[ -z "${head_version}" ]]; then
		echo "::error file=${main_file}::${slug}: no Version header found at head (${#shipped_changed[@]} shipped files changed)"
		errors=$((errors + 1))
		continue
	fi

	if [[ "${head_version}" == "${base_version}" ]]; then
		echo "::error file=${main_file}::${slug}: Version header unchanged at ${head_version} but ${#shipped_changed[@]} shipped file(s) changed. Bump the header Version in ${main_file} (and the matching *_VERSION constant, if the plugin defines one)."
		echo "FAIL ${slug} ${head_version} unchanged (${#shipped_changed[@]} shipped files changed)"
		errors=$((errors + 1))
		continue
	fi

	if ! agend_is_semver "${base_version}"; then
		echo "::error file=${main_file}::${slug}: base Version '${base_version}' at ${merge_base} is not valid semver, cannot compare"
		echo "FAIL ${slug} base version '${base_version}' invalid"
		errors=$((errors + 1))
		continue
	fi

	if ! agend_is_semver "${head_version}"; then
		echo "::error file=${main_file}::${slug}: Version header '${head_version}' is not valid semver"
		echo "FAIL ${slug} head version '${head_version}' invalid"
		errors=$((errors + 1))
		continue
	fi

	cmp="$(semver_compare "${head_version}" "${base_version}")"
	if [[ "${cmp}" != "gt" ]]; then
		echo "::error file=${main_file}::${slug}: Version header ${head_version} is not greater than the base version ${base_version} (${#shipped_changed[@]} shipped files changed)"
		echo "FAIL ${slug} ${base_version} -> ${head_version} (not an increase)"
		errors=$((errors + 1))
		continue
	fi

	# Rule 1: the released-version floor. Folds in the exact-tag case: a head
	# Version equal to the highest tag is "already released", so there is one
	# error per cause. A plugin with no tags has no floor.
	released="$(highest_released "${slug}")"
	if [[ -n "${released}" ]]; then
		released_version="${released%% *}"
		released_tag="${released#* }"
		cmp="$(semver_compare "${head_version}" "${released_version}")"
		if [[ "${cmp}" == "eq" ]]; then
			echo "::error file=${main_file}::${slug}: Version ${head_version} is already released as ${released_tag}; the release workflow would skip it, so this change would ship nowhere. Bump the Version header above ${released_version}."
			echo "FAIL ${slug} ${head_version} already released as ${released_tag}"
			errors=$((errors + 1))
			continue
		elif [[ "${cmp}" == "lt" ]]; then
			echo "::error file=${main_file}::${slug}: Version ${head_version} is below the latest released version ${released_version} (tag ${released_tag}). Rebase on main and bump the Version header above ${released_version}."
			echo "FAIL ${slug} ${head_version} is below released ${released_version}"
			errors=$((errors + 1))
			continue
		fi
	fi

	if ! check_open_prs "${slug}" "${main_file}" "${head_version}"; then
		echo "FAIL ${slug} ${head_version} (another open PR bumps it to the same version)"
		errors=$((errors + 1))
		continue
	fi

	echo "OK ${slug} ${base_version} -> ${head_version}"
done < <(agend_list_slugs)

if [[ "${errors}" -gt 0 ]]; then
	echo "::error::check-version-bumps.sh: ${errors} plugin(s) failed the version bump check"
	exit 1
fi

exit 0
