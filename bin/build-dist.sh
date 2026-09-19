#!/usr/bin/env sh

set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
version=$(sed -n 's/^ \* Version:[[:space:]]*\([^[:space:]]*\).*$/\1/p' "$project_dir/taxonomy-tidy.php")

if [ -z "$version" ]; then
	echo "Could not determine the plugin version." >&2
	exit 1
fi

build_root="$project_dir/build/dist"
stage_root="$build_root/stage"
plugin_dir="$stage_root/taxonomy-tidy"
dist_dir="$project_dir/dist"
archive="$dist_dir/taxonomy-tidy-$version.zip"
checksum="$archive.sha256"

case "$build_root" in
	"$project_dir"/build/dist) ;;
	*)
		echo "Refusing to use an unexpected build directory." >&2
		exit 1
		;;
esac

rm -rf "$build_root"
mkdir -p "$plugin_dir" "$dist_dir"

rsync -a --delete --exclude-from="$project_dir/.distignore" "$project_dir/" "$plugin_dir/"
cp "$project_dir/composer.json" "$project_dir/composer.lock" "$plugin_dir/"

docker compose --project-directory "$project_dir" run --rm --no-deps \
	-e "COMPOSER_ROOT_VERSION=$version" \
	-v "$plugin_dir:/dist" \
	composer install \
	--working-dir=/dist \
	--no-dev \
	--prefer-dist \
	--optimize-autoloader \
	--no-interaction \
	--no-progress

rm -f "$plugin_dir/composer.json" "$plugin_dir/composer.lock"

test -f "$plugin_dir/taxonomy-tidy.php"
test -f "$plugin_dir/vendor/autoload.php"
test -f "$plugin_dir/README.md"
test -f "$plugin_dir/LICENSE"

if find "$plugin_dir" \
	\( -name .env -o -name .git -o -name .github -o -name node_modules -o -name tests -o -name tools -o -name docker-compose.yml \) \
	-print -quit | grep -q .; then
	echo "The staged package contains a forbidden development file." >&2
	exit 1
fi

find "$plugin_dir" -exec touch -t 202601010000 {} +
rm -f "$archive" "$checksum"
(
	cd "$stage_root"
	find taxonomy-tidy -print | LC_ALL=C sort | zip -X -q "$archive" -@
)

(
	cd "$dist_dir"
	shasum -a 256 "$(basename "$archive")" > "$(basename "$checksum")"
)

unzip -tq "$archive" >/dev/null
echo "Created $archive"
cat "$checksum"
