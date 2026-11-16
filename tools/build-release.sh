#!/usr/bin/env bash

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [[ $# -eq 0 ]]; then
	git_dir="${repo_root}/.git"
else
	git_dir=$1
fi

plugin_file="$repo_root/jetlinez-woocommerce-invoice.php"
readme_file="$repo_root/readme.txt"
manifest_file="$repo_root/update.json"
slug="jetlinez-woocommerce-invoice"

plugin_version="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$plugin_file" | head -n 1)"
constant_version="$(sed -n "s/^define( 'JLWI_VERSION', '\([^']*\)' );/\1/p" "$plugin_file" | head -n 1)"
stable_version="$(sed -n 's/^Stable tag:[[:space:]]*//p' "$readme_file" | head -n 1)"
manifest_version="$(sed -n 's/^[[:space:]]*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$manifest_file" | head -n 1)"

if [[ "$plugin_version" != "$constant_version" || "$plugin_version" != "$stable_version" || "$plugin_version" != "$manifest_version" ]]; then
	printf 'Version mismatch: header=%s constant=%s stable_tag=%s manifest=%s\n' \
		"$plugin_version" "$constant_version" "$stable_version" "$manifest_version" >&2
	exit 1
fi

output_file="${2:-$repo_root/$slug.zip}"
work_dir="$(mktemp -d "${TMPDIR:-/tmp}/jlwi-build-release.XXXXXX")"
trap 'rm -rf -- "$work_dir"' EXIT

git_project() {
	git -C "$repo_root" --git-dir="$git_dir" -c core.splitIndex=false -c core.fsmonitor=false "$@"
}

git_dir="$(git_project rev-parse --absolute-git-dir)"
index_file="$(git_project rev-parse --path-format=absolute --git-path index)"
object_dir="$(git_project rev-parse --path-format=absolute --git-path objects)"

# Snapshot current files without changing the real index or object database.
# Copying the index also retains tracked files covered by ignore rules.
if [[ -f "$index_file" ]]; then
	cp "$index_file" "$work_dir/index"
fi
mkdir "$work_dir/objects"
export GIT_INDEX_FILE="$work_dir/index"
export GIT_OBJECT_DIRECTORY="$work_dir/objects"
export GIT_ALTERNATE_OBJECT_DIRECTORIES="$object_dir${GIT_ALTERNATE_OBJECT_DIRECTORIES:+:$GIT_ALTERNATE_OBJECT_DIRECTORIES}"

git_project add --all -- .
tree="$(git_project write-tree)"
temp_file="$work_dir/$slug.zip"
git_project archive \
	--format=zip \
	--prefix="$slug/" \
	--output="$temp_file" \
	"$tree"

mv "$temp_file" "$output_file"

printf 'Built %s (version %s)\n' "$output_file" "$plugin_version"
if command -v sha256sum >/dev/null 2>&1; then
	sha256sum "$output_file"
fi
