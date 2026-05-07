#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
REPO_DIR="$(cd "$ROOT_DIR/.." && pwd)"
DIST_DIR="$ROOT_DIR/.vitepress/dist"
VERSIONED_DIST_DIR="$ROOT_DIR/.vitepress/dist-versioned"
LEGACY_WORKTREE_DIR="$ROOT_DIR/.vitepress/.legacy-docs-worktree"
CURRENT_CONFIG="$ROOT_DIR/.vitepress/config.ts"

LATEST_BASE="${DOCS_LATEST_BASE:-/cakephp-fixture-factories/}"
LEGACY_BASE="${DOCS_LEGACY_BASE:-/cakephp-fixture-factories/1.x/}"
LEGACY_REF="${DOCS_LEGACY_REF:-origin/1.x}"
LEGACY_LABEL="${DOCS_LEGACY_LABEL:-v1.x (legacy)}"

detect_latest_label() {
    if [[ -n "${DOCS_LATEST_LABEL:-}" ]]; then
        printf '%s\n' "$DOCS_LATEST_LABEL"
        return
    fi

    local latest_v2_tag
    latest_v2_tag="$(git -C "$REPO_DIR" tag --list '2.*' --sort=-version:refname | head -n1)"

    case "$latest_v2_tag" in
        *-beta*)
            printf '%s\n' 'v2 (beta)'
            ;;
        *-rc*|*-RC*)
            printf '%s\n' 'v2 (rc)'
            ;;
        *)
            printf '%s\n' 'v2 (latest)'
            ;;
    esac
}

cleanup() {
    if git -C "$REPO_DIR" worktree list --porcelain | grep -Fq "worktree $LEGACY_WORKTREE_DIR"; then
        git -C "$REPO_DIR" worktree remove --force "$LEGACY_WORKTREE_DIR"
    fi
}

trap cleanup EXIT

LATEST_LABEL="$(detect_latest_label)"

rm -rf "$DIST_DIR" "$VERSIONED_DIST_DIR"

echo "Building latest docs at base: $LATEST_BASE"
DOCS_BASE="$LATEST_BASE" \
DOCS_VERSION_TEXT="$LATEST_LABEL" \
DOCS_LATEST_LINK_TEXT="$LATEST_LABEL" \
DOCS_LEGACY_LINK_TEXT="$LEGACY_LABEL" \
npx vitepress build "$ROOT_DIR"
mkdir -p "$VERSIONED_DIST_DIR"
cp -R "$DIST_DIR"/. "$VERSIONED_DIST_DIR"/

echo "Preparing legacy docs from ref: $LEGACY_REF"
cleanup
git -C "$REPO_DIR" worktree add --force --detach "$LEGACY_WORKTREE_DIR" "$LEGACY_REF"
cp "$CURRENT_CONFIG" "$LEGACY_WORKTREE_DIR/docs/.vitepress/config.ts"

echo "Installing legacy docs dependencies"
(cd "$LEGACY_WORKTREE_DIR/docs" && npm ci)

echo "Building legacy docs at base: $LEGACY_BASE"
DOCS_BASE="$LEGACY_BASE" \
DOCS_VERSION_TEXT="$LEGACY_LABEL" \
DOCS_LATEST_LINK_TEXT="$LATEST_LABEL" \
DOCS_LEGACY_LINK_TEXT="$LEGACY_LABEL" \
npx vitepress build "$LEGACY_WORKTREE_DIR/docs"
mkdir -p "$VERSIONED_DIST_DIR/1.x"
cp -R "$LEGACY_WORKTREE_DIR/docs/.vitepress/dist"/. "$VERSIONED_DIST_DIR/1.x"/

echo "Combined docs written to: $VERSIONED_DIST_DIR"
