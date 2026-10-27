#!/usr/bin/env bash
# Publishes this checkout to the wordpress.org plugin directory over SVN: trunk, tags/<version> and the listing
# assets in .wordpress-org/. The plugin files are what `git archive` gives, so export-ignore decides what ships.
#
#   bin/svn-release.sh --dry-run                      # build the SVN working copy and show what would change
#   bin/svn-release.sh --password-file ~/path/to/file # commit (SVN password from your wordpress.org profile)
#
# Needs svn (macOS: brew install subversion). Run it from a clean checkout of main.
set -euo pipefail

SLUG=variation-converter-for-woocommerce
URL="${SVN_URL:-https://plugins.svn.wordpress.org/$SLUG}"
SVN_USER="${SVN_USER:-craigpestell}"
dry=0
password_file=''
while [ $# -gt 0 ]; do
	case "$1" in
		--dry-run) dry=1 ;;
		--password-file) password_file="$2"; shift ;;
		*) echo "Unknown option: $1" >&2; exit 2 ;;
	esac
	shift
done

die() { echo "Error: $*" >&2; exit 1; }
command -v svn >/dev/null || die "svn is not installed (macOS: brew install subversion)"
cd "$(git rev-parse --show-toplevel)"
[ -z "$(git status --porcelain)" ] || die "the checkout has uncommitted changes"
version="$(sed -n 's/^ \* Version: *//p' "$SLUG.php" | tr -d '[:space:]')"
stable="$(sed -n 's/^Stable tag: *//p' readme.txt | tr -d '[:space:]')"
[ -n "$version" ] && [ "$version" = "$stable" ] || die "Version ($version) and readme Stable tag ($stable) differ"
if [ "$dry" = 0 ]; then
	[ -n "$password_file" ] && [ -f "$password_file" ] || die "give --password-file with your SVN password"
fi

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
echo "==> Checking out $URL"
svn checkout --quiet --depth immediates "$URL" "$work/svn"
svn update --quiet --set-depth infinity "$work/svn/trunk" "$work/svn/assets"
if svn ls "$URL/tags/$version" >/dev/null 2>&1; then
	die "tags/$version already exists; bump the version first"
fi

echo "==> trunk <- git archive HEAD ($version)"
find "$work/svn/trunk" -mindepth 1 -maxdepth 1 ! -name .svn -exec rm -rf {} +
git archive HEAD | tar -x -C "$work/svn/trunk"
echo "==> assets <- .wordpress-org"
find "$work/svn/assets" -mindepth 1 -maxdepth 1 ! -name .svn -exec rm -rf {} +
find .wordpress-org -maxdepth 1 -type f \( -name '*.png' -o -name '*.jpg' -o -name '*.svg' -o -name '*.gif' \) -exec cp {} "$work/svn/assets/" \;

cd "$work/svn"
svn add --quiet --force trunk assets
svn status | { grep '^!' || true; } | cut -c9- | while IFS= read -r gone; do svn rm --quiet "$gone"; done
for f in assets/*.png; do if [ -e "$f" ]; then svn propset --quiet svn:mime-type image/png "$f"; fi; done
for f in assets/*.svg; do if [ -e "$f" ]; then svn propset --quiet svn:mime-type image/svg+xml "$f"; fi; done
svn copy --quiet trunk "tags/$version"
svn status

if [ "$dry" = 1 ]; then
	echo "==> Dry run: nothing was committed."
	exit 0
fi
echo "==> Committing $version"
svn commit -m "Release $version" --username "$SVN_USER" --password "$(cat "$password_file")" --non-interactive
echo "==> Done: https://wordpress.org/plugins/$SLUG/ (the page can take a few minutes to update)"
