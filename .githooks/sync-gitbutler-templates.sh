#!/bin/sh
# Sync versioned GitButler prompt templates to .git/gitbutler/prompt-templates/
REPO_ROOT=$(git rev-parse --show-toplevel 2>/dev/null) || exit 0
SRC="$REPO_ROOT/.gitbutler/prompt-templates"
DST="$REPO_ROOT/.git/gitbutler/prompt-templates"

if [ -d "$SRC" ]; then
    mkdir -p "$DST"
    find "$SRC" -maxdepth 1 -name '*.md' -exec cp {} "$DST/" \;
fi
