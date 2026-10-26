#!/bin/sh
set -eu

ROOT=/srv/web
RELEASES="$ROOT/releases"
ASSET_DIRS="customer/assets control/assets public/site/assets"
KEEP_ASSET_DAYS=14
KEEP_RELEASES=3

release="${RELEASE:?RELEASE is not set}"
case "$release" in *[!A-Za-z0-9._-]*) echo "invalid RELEASE: $release" >&2; exit 2 ;; esac
target="$RELEASES/$release"
mkdir -p "$RELEASES"

stage() {
    rm -rf "$target.tmp"
    cp -a /dist "$target.tmp"

    if [ -e "$ROOT/current" ]; then
        for dir in $ASSET_DIRS; do
            if [ -d "$ROOT/current/$dir" ]; then
                mkdir -p "$target.tmp/$dir"
                cp -an "$ROOT/current/$dir/." "$target.tmp/$dir/"
            fi
            if [ -d "$target.tmp/$dir" ]; then
                mkdir -p "$ROOT/current/$dir"
                cp -an "$target.tmp/$dir/." "$ROOT/current/$dir/"
            fi
        done
        find "$target.tmp" -path '*/assets/*' -type f -mtime +"$KEEP_ASSET_DAYS" -delete
    fi

    rm -rf "$target"
    mv "$target.tmp" "$target"

    if [ ! -e "$ROOT/current" ]; then
        ln -sfn "releases/$release" "$ROOT/current"
    fi

    echo "staged $release"
}

switch() {
    [ -d "$target" ] || { echo "release $release is not staged" >&2; exit 1; }
    ln -sfn "releases/$release" "$ROOT/current.tmp"
    mv -Tf "$ROOT/current.tmp" "$ROOT/current"

    current="$(readlink "$ROOT/current")"
    ls -1t "$RELEASES" | grep -v '\.tmp$' | tail -n +"$((KEEP_RELEASES + 1))" | while read -r old; do
        [ "releases/$old" = "$current" ] || rm -rf "${RELEASES:?}/$old"
    done

    echo "switched to $release"
}

case "${1:-}" in
    stage) stage ;;
    switch) switch ;;
    *) echo "usage: publish stage|switch" >&2; exit 2 ;;
esac
