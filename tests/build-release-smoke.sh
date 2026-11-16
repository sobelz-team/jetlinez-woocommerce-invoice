#!/usr/bin/env bash

# Standalone release build test. Requires Git and unzip.
set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
work_dir="$(mktemp -d "${TMPDIR:-/tmp}/jlwi-build-smoke.XXXXXX")"
trap 'rm -rf -- "$work_dir"' EXIT
repo_dir="$work_dir/repo with spaces"
slug='jetlinez-woocommerce-invoice'
archive_file="$repo_dir/$slug.zip"

mkdir -p "$repo_dir/tools" "$repo_dir/includes" "$repo_dir/tests"
cp "$project_root/tools/build-release.sh" "$project_root/tools/bump-version.sh" "$repo_dir/tools/"
cp "$project_root/.gitattributes" "$repo_dir/"
printf '*.log\nprivate/\n' > "$repo_dir/.gitignore"
printf '<?php\n/**\n * Version:     1.2.3\n */\ndefine( '\''JLWI_VERSION'\'', '\''1.2.3'\'' );\n' > "$repo_dir/$slug.php"
printf 'Stable tag: 1.2.3\n' > "$repo_dir/readme.txt"
cat > "$repo_dir/update.json" <<'JSON'
{
  "version": "1.2.3",
  "last_updated": "2026-01-01 00:00:00",
  "sections": {
    "changelog": "<h4>1.2.3</h4>"
  }
}
JSON
printf 'original\n' > "$repo_dir/includes/current.php"
printf 'tracked despite ignore rules\n' > "$repo_dir/includes/tracked.log"
printf 'removed later\n' > "$repo_dir/includes/deleted.php"
printf 'excluded\n' > "$repo_dir/tests/fixture.php"
git -C "$repo_dir" init --quiet

assert_content() {
	local actual
	actual="$(unzip -p "$archive_file" "$slug/$1")"
	if [[ "$actual" != "$2" ]]; then
		printf 'FAIL: Unexpected archive content for %s\n' "$1" >&2
		exit 1
	fi
}

# Even a repository without its first commit can produce a release.
bash "$repo_dir/tools/build-release.sh" > "$work_dir/build.log"
assert_content 'includes/current.php' 'original'
if [[ -e "$repo_dir/.git/index" ]]; then
	printf 'FAIL: Build created the real Git index.\n' >&2
	exit 1
fi

git -C "$repo_dir" add --all
git -C "$repo_dir" add --force includes/tracked.log
git -C "$repo_dir" -c user.name='Release Test' -c user.email='release@example.test' -c commit.gpgsign=false commit --quiet -m 'Fixture'
printf 'staged\n' > "$repo_dir/includes/current.php"
git -C "$repo_dir" add includes/current.php
printf 'current working content\n' > "$repo_dir/includes/current.php"
printf 'updated tracked log\n' > "$repo_dir/includes/tracked.log"
printf 'new file\n' > "$repo_dir/includes/new file.php"
printf 'private log\n' > "$repo_dir/debug.log"
mkdir "$repo_dir/private"
printf 'secret\n' > "$repo_dir/private/secret.txt"
printf '/local-only.txt export-ignore\n' >> "$repo_dir/.gitattributes"
printf 'excluded by current attributes\n' > "$repo_dir/local-only.txt"
rm "$repo_dir/includes/deleted.php"
bash "$repo_dir/tools/bump-version.sh" patch 'Build before committing' > "$work_dir/bump.log"
cp "$repo_dir/.git/index" "$work_dir/original-index"

# Build twice: an existing, tracked ZIP must not block or include itself.
for run in 1 2; do
	if [[ "$run" -eq 2 ]]; then
		git -C "$repo_dir" update-index --split-index
		cp "$repo_dir/.git/index" "$work_dir/original-index"
	fi
	bash "$repo_dir/tools/build-release.sh" > "$work_dir/build.log"
	cmp "$repo_dir/.git/index" "$work_dir/original-index"
	assert_content 'includes/current.php' 'current working content'
	assert_content 'includes/new file.php' 'new file'
	assert_content 'includes/tracked.log' 'updated tracked log'
	assert_content 'readme.txt' 'Stable tag: 1.2.4'
	unzip -p "$archive_file" "$slug/$slug.php" > "$work_dir/archived-plugin.php"
	cmp "$repo_dir/$slug.php" "$work_dir/archived-plugin.php"
	entries="$(unzip -Z1 "$archive_file")"
	for excluded in includes/deleted.php debug.log private/secret.txt local-only.txt tools/build-release.sh tests/fixture.php .git/config .gitignore .gitattributes update.json "$slug.zip"; do
		if [[ "$entries" == *"$slug/$excluded"* ]]; then
			printf 'FAIL: Excluded file was archived: %s\n' "$excluded" >&2
			exit 1
		fi
	done
done

# Keep the existing git-directory and output-path arguments working.
bash "$repo_dir/tools/build-release.sh" "$repo_dir/.git" "$work_dir/custom release.zip" > "$work_dir/build.log"
unzip -tq "$work_dir/custom release.zip"

# A version mismatch must fail without overwriting the existing release.
cp "$archive_file" "$work_dir/previous.zip"
sed -i 's/1.2.4/9.9.9/' "$repo_dir/update.json"
if bash "$repo_dir/tools/build-release.sh" > "$work_dir/build.log" 2>&1; then
	printf 'FAIL: Build accepted mismatched versions.\n' >&2
	exit 1
fi
cmp "$archive_file" "$work_dir/previous.zip"
cmp "$repo_dir/.git/index" "$work_dir/original-index"

printf 'Release build smoke test passed.\n'
