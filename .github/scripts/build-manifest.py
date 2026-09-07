#!/usr/bin/env python3
"""Build manifest.json (and the static gh-pages `site/` tree) from a repo's
GitHub Releases.

This intentionally splits "get the release data" (fetch-releases.sh, which
calls `gh api`) from "turn release data into manifest.json + site/" (this
script), so the manifest-building logic can be exercised locally against a
saved releases.json without any network access or GitHub auth.

Usage:
    build-manifest.py --releases-file releases.json --site-dir site

Flags:
    --releases-file PATH   JSON array of GitHub release objects (required).
    --repo OWNER/REPO      Defaults to $GITHUB_REPOSITORY.
    --site-dir PATH        Output directory (default: site).
    --package-host pages|releases
                           Defaults to $AGEND_PACKAGE_HOST, else "pages".
    --skip-download        Don't shell out to `gh release download` even in
                           "pages" mode (used for local/offline testing --
                           packages/ will be left empty).
    --repo-root PATH       Root of the git checkout to run `git show` /
                           `git tag` against (default: repo root relative to
                           this script).
"""
from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from md_to_html import convert as md_to_html  # noqa: E402

TAG_RE = re.compile(r"^(?P<slug>agend-[a-z0-9-]+)-v(?P<version>.+)$")
HEADER_RE_TEMPLATE = r"^\s*\*\s*{name}:\s*(?P<value>.*)$"


def parse_version_key(version: str):
	"""Best-effort semver-ish sort key: numeric release part first, then the
	rest as a string (so any pre-release suffix compares below plain
	releases with the same numeric prefix, matching `sort -V` intuition)."""
	m = re.match(r"^(\d+)\.(\d+)\.(\d+)(.*)$", version)
	if not m:
		return (0, 0, 0, version)
	major, minor, patch, rest = m.groups()
	# A version with no suffix should sort ABOVE one with a "-rc1" suffix of
	# the same numeric triple, so give "no suffix" the highest rank.
	rest_rank = "" if not rest else rest
	return (int(major), int(minor), int(patch), rest_rank == "", rest_rank)


def parse_plugin_header(php_text: str) -> dict:
	"""Pull the WordPress plugin header fields we need out of a main plugin
	file's docblock. Only looks at the first 40 lines, matching lib.sh."""
	fields = {}
	names = {
		"name": "Plugin Name",
		"description": "Description",
		"requires": "Requires at least",
		"requires_php": "Requires PHP",
		"tested": "Tested up to",
		"author": "Author",
	}
	lines = php_text.splitlines()[:40]
	for key, header_name in names.items():
		pattern = re.compile(HEADER_RE_TEMPLATE.format(name=re.escape(header_name)))
		for line in lines:
			match = pattern.match(line)
			if match:
				value = match.group("value").strip()
				if value:
					fields[key] = value
				break
	return fields


def pages_base_url(repo: str) -> str:
	"""Project-site GitHub Pages URL for OWNER/REPO (no trailing slash).
	Must agree with the Update URI headers in the plugins and with
	Agend_Apps_Updater::DEFAULT_MANIFEST_URL in agend-apps-core."""
	owner, name = repo.split("/", 1)
	return f"https://{owner.lower()}.github.io/{name}"


def git_show(repo_root: Path, tag: str, path: str) -> str:
	result = subprocess.run(
		["git", "show", f"{tag}:{path}"],
		cwd=repo_root,
		capture_output=True,
		text=True,
		check=True,
	)
	return result.stdout


def select_latest_per_slug(releases: list[dict]) -> dict:
	"""releases -> {slug: release_dict} keeping only the highest-version,
	non-prerelease release per slug."""
	best: dict[str, tuple[tuple, dict, str]] = {}
	for release in releases:
		if release.get("prerelease"):
			continue
		if release.get("draft"):
			continue
		tag = release.get("tag_name", "")
		m = TAG_RE.match(tag)
		if not m:
			continue
		slug = m.group("slug")
		version = m.group("version")
		key = parse_version_key(version)
		current = best.get(slug)
		if current is None or key > current[0]:
			best[slug] = (key, release, version)
	return {slug: (release, version) for slug, (_, release, version) in best.items()}


def build_manifest(
	repo: str,
	releases: list[dict],
	repo_root: Path,
	package_host: str,
) -> tuple[dict, dict]:
	"""Returns (manifest_dict, {slug: tag}) for the caller to use when
	deciding what to download in "pages" mode."""
	selected = select_latest_per_slug(releases)
	plugins = {}
	tags_by_slug = {}

	for slug in sorted(selected):
		release, version = selected[slug]
		tag = release["tag_name"]
		tags_by_slug[slug] = tag

		php_path = f"{slug}/{slug}.php"
		php_text = git_show(repo_root, tag, php_path)
		header = parse_plugin_header(php_text)

		if package_host == "releases":
			package_url = (
				f"https://github.com/{repo}/releases/download/{tag}/{slug}.zip"
			)
		else:
			package_url = f"{pages_base_url(repo)}/packages/{slug}.zip"

		entry = {
			"slug": slug,
			"name": header.get("name", slug),
			"version": version,
			"requires": header.get("requires", ""),
			"requires_php": header.get("requires_php", ""),
			"package": package_url,
			"url": release.get(
				"html_url",
				f"https://github.com/{repo}/releases/tag/{tag}",
			),
			"last_updated": release.get("published_at", ""),
			"author": "Agend",
			"author_url": "https://agend.com.au",
			"sections": {
				"description": md_to_html(header.get("description", "")),
				"changelog": md_to_html(release.get("body") or ""),
			},
		}
		if "tested" in header:
			entry["tested"] = header["tested"]

		plugins[slug] = entry

	manifest = {
		"generated_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
		"repository": f"https://github.com/{repo}",
		"plugins": plugins,
	}
	return manifest, tags_by_slug


def render_index_html(manifest: dict) -> str:
	rows = []
	for slug in sorted(manifest["plugins"]):
		p = manifest["plugins"][slug]
		rows.append(
			"<tr>"
			f'<td><a href="./{slug}/">{p["name"]}</a></td>'
			f'<td>{p["version"]}</td>'
			f'<td><a href="{p["url"]}">Release</a></td>'
			"</tr>"
		)
	return (
		"<!doctype html><html><head><meta charset=\"utf-8\">"
		"<title>Agend WordPress Plugins</title></head><body>"
		"<h1>Agend WordPress Plugins</h1>"
		"<p>Update manifest for the Agend WordPress plugin suite. See "
		f'<a href="{manifest["repository"]}">{manifest["repository"]}</a>.</p>'
		"<table border=\"1\" cellpadding=\"6\" cellspacing=\"0\">"
		"<thead><tr><th>Plugin</th><th>Version</th><th>Latest release</th></tr></thead>"
		f"<tbody>{''.join(rows)}</tbody>"
		"</table></body></html>"
	)


def render_plugin_html(entry: dict) -> str:
	return (
		"<!doctype html><html><head><meta charset=\"utf-8\">"
		f'<title>{entry["name"]}</title>'
		f'<meta http-equiv="refresh" content="0; url={entry["url"]}">'
		"</head><body>"
		f'<h1>{entry["name"]}</h1>'
		f'<p>Current version: {entry["version"]}</p>'
		f'{entry["sections"]["description"]}'
		f'<p>Redirecting to <a href="{entry["url"]}">{entry["url"]}</a>...</p>'
		"</body></html>"
	)


def main() -> int:
	parser = argparse.ArgumentParser(description=__doc__)
	parser.add_argument("--releases-file", required=True)
	parser.add_argument("--repo", default=None)
	parser.add_argument("--site-dir", default="site")
	parser.add_argument("--package-host", default=None, choices=["pages", "releases"])
	parser.add_argument("--skip-download", action="store_true")
	parser.add_argument("--repo-root", default=None)
	args = parser.parse_args()

	import os

	repo = args.repo or os.environ.get("GITHUB_REPOSITORY")
	if not repo:
		parser.error("--repo or $GITHUB_REPOSITORY is required")

	package_host = args.package_host or os.environ.get("AGEND_PACKAGE_HOST", "pages")
	repo_root = Path(args.repo_root) if args.repo_root else Path(__file__).resolve().parents[2]
	site_dir = Path(args.site_dir)

	with open(args.releases_file, "r", encoding="utf-8") as handle:
		releases = json.load(handle)

	manifest, tags_by_slug = build_manifest(repo, releases, repo_root, package_host)

	site_dir.mkdir(parents=True, exist_ok=True)
	(site_dir / "manifest.json").write_text(
		json.dumps(manifest, indent=2) + "\n", encoding="utf-8"
	)
	(site_dir / "index.html").write_text(render_index_html(manifest), encoding="utf-8")
	(site_dir / ".nojekyll").write_text("", encoding="utf-8")

	for slug, entry in manifest["plugins"].items():
		plugin_dir = site_dir / slug
		plugin_dir.mkdir(parents=True, exist_ok=True)
		(plugin_dir / "index.html").write_text(
			render_plugin_html(entry), encoding="utf-8"
		)

	if package_host == "pages" and not args.skip_download:
		packages_dir = site_dir / "packages"
		packages_dir.mkdir(parents=True, exist_ok=True)
		for slug, tag in tags_by_slug.items():
			subprocess.run(
				[
					"gh", "release", "download", tag,
					"--repo", repo,
					"--pattern", f"{slug}.zip",
					"--dir", str(packages_dir),
					"--clobber",
				],
				check=True,
			)

	print(f"Wrote manifest for {len(manifest['plugins'])} plugin(s) to {site_dir}")
	return 0


if __name__ == "__main__":
	raise SystemExit(main())
