#!/usr/bin/env bash
# Runs the installer and a patch package (default disrex/magento-security-patches) against a copy of a
# real Magento project and checks what ends up on disk.
#
#   tests/e2e-local.sh ~/Development/Sites/Magento/isodin-flex2
#
# The project itself is never touched: everything happens in a copy under
# $E2E_WORK (default: a new temp dir). Needs the project's auth.json for the
# private repositories, since Composer resolves the whole lock.

set -uo pipefail

PROJECT="${1:?usage: tests/e2e-local.sh <magento-project-dir>}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INSTALLER_DIR="$(dirname "$HERE")"
PATCHES_DIR="${PATCHES_DIR:-$(dirname "$INSTALLER_DIR")/magento-security-patches}"
INSTALLER_VERSION="${INSTALLER_VERSION:-0.1.0}"
PATCHES_VERSION="${PATCHES_VERSION:-2026.10.14}"
PATCHES_PACKAGE="${PATCHES_PACKAGE:-disrex/magento-security-patches}"
WORK="${E2E_WORK:-$(mktemp -d)}"
COMPOSER_FLAGS="${E2E_COMPOSER_FLAGS:---ignore-platform-reqs}"
# "apply" for a project whose own post-install scripts cannot survive a partial
# reinstall (Gameshop's magento-cloud-patches step applies whole months).
HEAL="${E2E_HEAL:-install}"
export COMPOSER_NO_INTERACTION=1

failures=0
pass() { printf '  \033[32mok\033[0m    %s\n' "$1"; }
fail() { printf '  \033[31mFAIL\033[0m  %s\n' "$1"; failures=$((failures + 1)); }
check() { if [ "$2" = "$3" ]; then pass "$1"; else fail "$1 (expected $3, got $2)"; fi; }

[ -d "$PROJECT/vendor" ] || { echo "no vendor/ in $PROJECT" >&2; exit 2; }
[ -d "$PATCHES_DIR" ] || { echo "no patch package at $PATCHES_DIR" >&2; exit 2; }

copy="$WORK/$(basename "$PROJECT")-$(date +%s)"
echo "copy: $copy"
# APFS clones on macOS make a 5 GB project copy instant and nearly free.
cp -cR "$PROJECT" "$copy" 2>/dev/null || cp -a "$PROJECT" "$copy" || exit 2
cd "$copy" || exit 2

path_repo() {
    printf '{"type":"path","url":"%s","options":{"symlink":false,"versions":{"%s":"%s"}}}' "$1" "$2" "$3"
}
composer config --no-plugins --json repositories.e2e-installer "$(path_repo "$INSTALLER_DIR" disrex/magento-patch-installer "$INSTALLER_VERSION")"
composer config --no-plugins --json repositories.e2e-patches "$(path_repo "$PATCHES_DIR" "$PATCHES_PACKAGE" "$PATCHES_VERSION")"
composer config --no-plugins allow-plugins.disrex/magento-patch-installer true
composer config --no-plugins --json extra.magento-patches.trust '["disrex/*"]'
old=""
if grep -q '"disrex/stylesmuggler-adobe-patches"' composer.json; then
    composer remove --no-plugins --no-update disrex/stylesmuggler-adobe-patches >/dev/null
    old="disrex/stylesmuggler-adobe-patches"
fi
composer require --no-plugins --no-update "$PATCHES_PACKAGE:$PATCHES_VERSION" "disrex/magento-patch-installer:$INSTALLER_VERSION" >/dev/null

echo "composer update (log: $copy/e2e-update.log)"
# shellcheck disable=SC2086
composer update $old "$PATCHES_PACKAGE" disrex/magento-patch-installer $COMPOSER_FLAGS >e2e-update.log 2>&1
check "composer update exits 0" "$?" 0

status_json() { composer magento-patches:status --json 2>/dev/null; }
targets() {
    status_json | php -r '$d = json_decode(stream_get_contents(STDIN), true);
        foreach ($d["patches"] ?? [] as $p) foreach ($p["targets"] ?? [] as $t) echo $t["path"], "\n";' | sort -u
}
fingerprint() { targets | while read -r f; do [ -f "$f" ] && shasum -a 256 "$f"; done; }

has_base=$(php -r '$l = json_decode(file_get_contents("composer.lock"), true);
    foreach (array_merge($l["packages"], $l["packages-dev"] ?? []) as $p) if ($p["name"] === "magento/magento2-base") { echo 1; exit; } echo 0;')

if [ "$has_base" = 0 ]; then
    echo "no magento/magento2-base: the plugin must do nothing"
    check "no target applies" "$(targets | wc -l | tr -d ' ')" 0
    composer magento-patches:verify >/dev/null 2>&1
    check "verify exits 0" "$?" 0
    [ "$failures" = 0 ] && echo "PASS" || echo "FAILED: $failures"
    exit $((failures > 0))
fi

composer magento-patches:verify >/dev/null 2>&1
check "verify exits 0 after the install" "$?" 0
count=$(targets | wc -l | tr -d ' ')
[ "$count" -gt 0 ] && pass "$count targets apply to this store" || fail "no targets apply to this store"

before=$(fingerprint)
composer magento-patches:verify >/dev/null 2>&1
check "verify writes nothing" "$(fingerprint)" "$before"
composer magento-patches:apply >/dev/null 2>&1
check "a second apply writes nothing" "$(fingerprint)" "$before"

lint_failures=0
while read -r f; do
    case "$f" in *.php) [ -f "$f" ] && ! php -l "$f" >/dev/null 2>&1 && { echo "    php -l fails: $f"; lint_failures=$((lint_failures + 1)); } ;; esac
done < <(targets)
check "every patched PHP file passes php -l" "$lint_failures" 0

if [ -f vendor/bin/patch-status ]; then
    missing=$(php vendor/bin/patch-status 2>/dev/null | php -r '$d = json_decode(stream_get_contents(STDIN), true);
        echo is_array($d) ? count($d["missing_patches"] ?? []) : "unreadable";')
    check "Adobe's patch-status lists nothing missing" "$missing" 0
fi

composer reinstall --no-plugins magento/framework magento/magento2-base >/dev/null 2>&1
composer magento-patches:verify >/dev/null 2>&1
check "verify exits 1 after the core packages were reinstalled" "$?" 1
if [ "$HEAL" = apply ]; then
    composer magento-patches:apply >e2e-heal.log 2>&1
    check "magento-patches:apply exits 0" "$?" 0
else
    # shellcheck disable=SC2086
    composer install $COMPOSER_FLAGS >e2e-heal.log 2>&1
    check "the next composer install exits 0" "$?" 0
fi
composer magento-patches:verify >/dev/null 2>&1
check "verify exits 0 after healing" "$?" 0

[ "$failures" = 0 ] && echo "PASS" || echo "FAILED: $failures"
exit $((failures > 0))
