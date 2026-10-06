#!/usr/bin/env bash
# Tests for .github/scripts/check-version-bumps.sh: the released-version floor
# and the open-PR comparison.
#
# Each case builds a throwaway repo (a bare "origin" plus a clone) holding a
# fake plugin, copies the real scripts into it, and puts a stub `gh` on PATH
# that prints canned JSON. Nothing touches the network or the real repo.
#
# Usage: .github/scripts/tests/check-version-bumps.test.sh

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "${TMP}"' EXIT

export GIT_AUTHOR_NAME=test GIT_AUTHOR_EMAIL=test@example.com
export GIT_COMMITTER_NAME=test GIT_COMMITTER_EMAIL=test@example.com
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_SYSTEM=/dev/null
GIT_EXEC_PATH="$(git --exec-path)"
export GIT_EXEC_PATH

passed=0
failed=0
case_dir=""
out=""
rc=0

plugin_php() {
	printf '<?php\n/**\n * Plugin Name: Demo\n * Version:     %s\n */\n' "$1"
}

# Build a fresh origin + work clone for one case. main carries agend-demo at
# $1 and agend-other at 1.0.0.
new_case() {
	case_dir="$(mktemp -d "${TMP}/case.XXXXXX")"
	git init -q --bare -b main "${case_dir}/origin.git"
	git clone -q "${case_dir}/origin.git" "${case_dir}/work" 2>/dev/null
	(
		cd "${case_dir}/work"
		git checkout -q -b main
		mkdir -p .github/scripts agend-demo agend-other
		cp "${REPO_ROOT}/.github/scripts/lib.sh" "${REPO_ROOT}/.github/scripts/check-version-bumps.sh" .github/scripts/
		plugin_php "$1" >agend-demo/agend-demo.php
		plugin_php 1.0.0 >agend-other/agend-other.php
		git add -A
		git commit -q -m "initial"
		git push -q origin main
	)
	mkdir -p "${case_dir}/bin"
}

git_work() { git -C "${case_dir}/work" "$@"; }

# Commit a Version bump of one plugin on a new branch cut from $1 (a ref).
# Prints the commit sha. Usage: bump_branch <branch> <from> <plugin> <version>
bump_branch() {
	local branch=$1 from=$2 plugin=$3 version=$4
	git_work checkout -q -b "${branch}" "${from}"
	plugin_php "${version}" >"${case_dir}/work/${plugin}/${plugin}.php"
	echo "// change ${branch}" >>"${case_dir}/work/${plugin}/code.php"
	git_work add -A
	git_work commit -q -m "bump ${plugin} to ${version} on ${branch}"
	git_work rev-parse HEAD
	git_work checkout -q main
}

# Move main on by one commit that sets agend-demo to $1, tags it, and
# publishes both. Usage: release_on_main <version> [--no-tag]
release_on_main() {
	git_work checkout -q main
	plugin_php "$1" >"${case_dir}/work/agend-demo/agend-demo.php"
	git_work commit -q -am "release $1"
	[[ "${2:-}" == "--no-tag" ]] || git_work tag "agend-demo-v$1"
	git_work push -q origin main --tags
}

# Publish a commit as refs/pull/<n>/head on origin.
publish_pr() {
	git_work push -q origin "$2:refs/pull/$1/head"
}

# Canned `gh pr list` output. Usage: stub_gh <base> <json>
# The stub answers only `pr list`, replies with the JSON canned for the --base
# it was given (an empty list for any other base), and logs its arguments.
write_gh_stub() {
	cat >"${case_dir}/bin/gh" <<'STUB'
#!/usr/bin/env bash
echo "$*" >>"${STUB_DIR}/gh.log"
[[ "$1 $2" == "pr list" ]] || exit 1
base=""
while [[ $# -gt 0 ]]; do
	[[ "$1" == "--base" ]] && base="$2"
	shift
done
if [[ -f "${STUB_DIR}/prs-${base}.json" ]]; then
	cat "${STUB_DIR}/prs-${base}.json"
else
	echo '[]'
fi
STUB
	chmod +x "${case_dir}/bin/gh"
}
stub_gh() {
	write_gh_stub
	printf '%s\n' "$2" >"${case_dir}/prs-$1.json"
}

# Run the script in the case's work clone against origin/main.
run_check() {
	set +e
	out="$(cd "${case_dir}/work" && STUB_DIR="${case_dir}" PATH="${case_dir}/bin:${PATH}" \
		GH_TOKEN=token PR_NUMBER=100 GITHUB_REPOSITORY=acme/plugins \
		bash .github/scripts/check-version-bumps.sh --base origin/main --head "$1" "${@:2}" 2>&1)"
	rc=$?
	set -e
}

assert() {
	local name=$1 ok=$2
	if [[ "${ok}" == 1 ]]; then
		echo "PASS ${name}"
		passed=$((passed + 1))
	else
		echo "FAIL ${name}"
		echo "${out}" | sed 's/^/    | /'
		failed=$((failed + 1))
	fi
}
has() { [[ "${out}" == *"$1"* ]] && echo 1 || echo 0; }
rc_is() { [[ "${rc}" -eq "$1" ]] && echo 1 || echo 0; }
both() { [[ "$1" == 1 && "$2" == 1 ]] && echo 1 || echo 0; }

# --- Rule 1: released-version floor ----------------------------------------

test_floor_below_latest_tag_fails() {
	new_case 1.20.0
	bump_branch feature main agend-demo 1.21.0 >/dev/null
	release_on_main 1.25.0
	run_check feature --skip-open-prs
	local ok
	ok="$(both "$(rc_is 1)" "$(has '::error file=agend-demo/agend-demo.php::agend-demo: Version 1.21.0 is below the latest released version 1.25.0 (tag agend-demo-v1.25.0)')")"
	assert "(a) a bump below the latest tag fails" "${ok}"
	# One cause, one error annotation for the plugin (plus the summary line).
	local n
	n="$(echo "${out}" | grep -c '::error file=' || true)"
	assert "(a) a bump below the latest tag raises a single error" "$([[ "${n}" -eq 1 ]] && echo 1 || echo 0)"
}

test_floor_equal_to_latest_tag_fails() {
	new_case 1.20.0
	bump_branch feature main agend-demo 1.21.0 >/dev/null
	# 1.21.0 is already released (tagged), though main's header never moved.
	git_work tag agend-demo-v1.21.0 main
	git_work push -q origin --tags
	run_check feature --skip-open-prs
	local ok
	ok="$(both "$(rc_is 1)" "$(has 'already released as agend-demo-v1.21.0')")"
	assert "(a) a bump equal to the latest tag fails" "${ok}"
}

test_floor_above_latest_tag_passes() {
	new_case 1.20.0
	bump_branch feature main agend-demo 1.26.0 >/dev/null
	release_on_main 1.25.0
	run_check feature --skip-open-prs
	local ok
	ok="$(both "$(rc_is 0)" "$(has 'OK agend-demo 1.20.0 -> 1.26.0')")"
	assert "(a) a bump above the latest tag passes" "${ok}"
}

test_floor_no_tags_has_no_floor() {
	new_case 1.20.0
	bump_branch feature main agend-demo 1.20.1 >/dev/null
	git_work tag -l | grep -q . && { echo "fixture has tags"; exit 1; }
	run_check feature --skip-open-prs
	local ok
	ok="$(both "$(rc_is 0)" "$(has 'no tags are fetched')")"
	assert "(a) no tags means no floor, with a notice" "${ok}"
}

test_floor_tags_for_other_plugins_are_not_a_floor() {
	new_case 1.20.0
	git_work tag agend-other-v9.9.9
	git_work push -q origin --tags
	bump_branch feature main agend-demo 1.20.1 >/dev/null
	run_check feature --skip-open-prs
	local ok
	ok="$(both "$(rc_is 0)" "$(has 'OK agend-demo 1.20.0 -> 1.20.1')")"
	assert "(a) another plugin's tag is not this plugin's floor" "${ok}"
}

# --- Rule 2: other open PRs targeting the same base ------------------------

# Two open PRs against main: this one (#100, on branch `feature`) and #200.
# Usage: open_pr_case <this_version> <other_version> [other_plugin]
open_pr_case() {
	local this_version=$1 other_version=$2 other_plugin=${3:-agend-demo}
	new_case 1.20.0
	bump_branch feature main agend-demo "${this_version}" >/dev/null
	local sha
	sha="$(bump_branch other main "${other_plugin}" "${other_version}")"
	publish_pr 200 "${sha}"
	stub_gh main "[{\"number\":100,\"headRefOid\":\"x\",\"isDraft\":false,\"title\":\"This PR\"},{\"number\":200,\"headRefOid\":\"${sha}\",\"isDraft\":true,\"title\":\"Other draft PR\"}]"
}

test_open_pr_same_version_fails() {
	open_pr_case 1.21.0 1.21.0
	run_check feature
	local ok
	ok="$(both "$(rc_is 1)" "$(has '::error file=agend-demo/agend-demo.php::agend-demo: PR #200 (Other draft PR) also bumps agend-demo to 1.21.0')")"
	assert "(b) another main-targeted PR at the same version fails (drafts count)" "${ok}"
}

test_open_pr_higher_version_warns() {
	open_pr_case 1.21.0 1.22.0
	run_check feature
	local ok
	ok="$(both "$(rc_is 0)" "$(has '::warning file=agend-demo/agend-demo.php::agend-demo: PR #200 (Other draft PR) bumps agend-demo to 1.22.0')")"
	assert "(b) a higher version warns but passes" "${ok}"
}

test_open_pr_lower_version_ignored() {
	open_pr_case 1.22.0 1.21.0
	run_check feature
	local ok
	ok="$([[ "$(rc_is 0)" == 1 && "$(has '::warning')" == 0 && "$(has '::error')" == 0 ]] && echo 1 || echo 0)"
	assert "(b) a lower version is ignored" "${ok}"
}

test_open_pr_not_bumping_plugin_ignored() {
	# #200 bumps agend-other, and leaves agend-demo at main's version.
	open_pr_case 1.21.0 1.21.0 agend-other
	run_check feature
	local ok
	ok="$([[ "$(rc_is 0)" == 1 && "$(has '::warning')" == 0 && "$(has 'OK agend-demo 1.20.0 -> 1.21.0')" == 1 ]] && echo 1 || echo 0)"
	assert "(b) a PR that does not bump that plugin is ignored" "${ok}"
}

test_open_pr_own_number_excluded() {
	new_case 1.20.0
	local sha
	sha="$(bump_branch feature main agend-demo 1.21.0)"
	# The only listed PR is this one, published at the same version.
	publish_pr 100 "${sha}"
	stub_gh main "[{\"number\":100,\"headRefOid\":\"${sha}\",\"isDraft\":false,\"title\":\"This PR\"}]"
	run_check feature
	local ok
	ok="$([[ "$(rc_is 0)" == 1 && "$(has 'also bumps')" == 0 ]] && echo 1 || echo 0)"
	assert "(b) this PR's own number is excluded" "${ok}"
}

test_open_pr_stacked_pr_not_listed() {
	new_case 1.20.0
	bump_branch feature main agend-demo 1.21.0 >/dev/null
	local sha
	sha="$(bump_branch stacked main agend-demo 1.21.0)"
	publish_pr 300 "${sha}"
	# #300 is stacked on another branch, so only the `other-branch` listing has it.
	write_gh_stub
	printf '[{"number":300,"headRefOid":"%s","isDraft":false,"title":"Stacked PR"}]\n' "${sha}" >"${case_dir}/prs-other-branch.json"
	run_check feature
	local asked
	asked="$(grep -c -- '--base main' "${case_dir}/gh.log" || true)"
	local ok
	ok="$([[ "$(rc_is 0)" == 1 && "$(has 'also bumps')" == 0 && "${asked}" -ge 1 ]] && echo 1 || echo 0)"
	assert "(b) a stacked PR (base not main) is not listed, so it is ignored" "${ok}"
}

test_open_pr_no_gh_is_skipped() {
	open_pr_case 1.21.0 1.21.0
	# A PATH with the tools the script needs but no gh (a runner can have one
	# in /usr/bin, so PATH cannot just be trimmed).
	mkdir -p "${case_dir}/nogh"
	local tool
	for tool in bash env git sed grep head sort mktemp rm dirname basename cat python3 tr; do
		ln -sf "$(command -v "${tool}")" "${case_dir}/nogh/${tool}"
	done
	set +e
	out="$(cd "${case_dir}/work" && PATH="${case_dir}/nogh" GH_TOKEN=token PR_NUMBER=100 \
		GITHUB_REPOSITORY=acme/plugins "${case_dir}/nogh/bash" .github/scripts/check-version-bumps.sh \
		--base origin/main --head feature 2>&1)"
	rc=$?
	set -e
	local ok
	ok="$([[ "$(rc_is 0)" == 1 && "$(has 'gh is not installed')" == 1 && "$(has 'also bumps')" == 0 ]] && echo 1 || echo 0)"
	assert "(b) no gh available means skipped with a notice" "${ok}"
}

test_open_pr_skip_flag() {
	open_pr_case 1.21.0 1.21.0
	run_check feature --skip-open-prs
	local ok
	ok="$([[ "$(rc_is 0)" == 1 && "$(has '--skip-open-prs')" == 1 && "$(has 'also bumps')" == 0 ]] && echo 1 || echo 0)"
	assert "(b) --skip-open-prs skips the comparison with a notice" "${ok}"
}

test_floor_below_latest_tag_fails
test_floor_equal_to_latest_tag_fails
test_floor_above_latest_tag_passes
test_floor_no_tags_has_no_floor
test_floor_tags_for_other_plugins_are_not_a_floor
test_open_pr_same_version_fails
test_open_pr_higher_version_warns
test_open_pr_lower_version_ignored
test_open_pr_not_bumping_plugin_ignored
test_open_pr_own_number_excluded
test_open_pr_stacked_pr_not_listed
test_open_pr_no_gh_is_skipped
test_open_pr_skip_flag

echo
echo "${passed} passed, ${failed} failed"
[[ "${failed}" -eq 0 ]]
