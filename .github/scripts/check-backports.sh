#!/usr/bin/env bash
#
# Reports fixes that landed on one branch and never reached the branches that
# should also carry them. Only the shared infrastructure files listed in
# .github/backport-watch.txt are considered, because feature code diverges on
# purpose and would bury the signal.
#
# Commits are matched by subject, not by SHA or patch id: a backport is usually
# adapted to the target branch, which changes both.
#
# Exit status is 1 when an unignored fix( commit is missing, 0 otherwise.
# feat( commits are listed for information and never affect the exit status.
#
# Kept to bash 3.2 features so it runs on macOS as well as CI. Presence tests
# always grep a file, never a pipe: "printf ... | grep -q" returns 141 (SIGPIPE)
# on a match once the payload exceeds the pipe buffer, and under 'set -o
# pipefail' that reads as "not found".

set -uo pipefail

die() { echo "backport-check: $*" >&2; exit 2; }

cd "$(git rev-parse --show-toplevel)" || die "not inside a git repository"

# source::target pairs, in the direction a change should travel
PAIRS="
develop::master
master::release/4.3.x
master::release/4.2.x
"

SINCE="${BACKPORT_CHECK_SINCE:-12 months ago}"
WATCH_FILE=".github/backport-watch.txt"
IGNORE_FILE=".github/backport-ignore.txt"

[ -f "$WATCH_FILE" ] || die "missing ${WATCH_FILE}"

TMPDIR_BP=$(mktemp -d) || die "cannot create temp dir"
trap 'rm -rf "$TMPDIR_BP"' EXIT

# Only whole-line comments are stripped: commit subjects legitimately contain '#'
# in "(closes #1234)" and would otherwise be truncated.
strip_comments() { sed -e '/^[[:space:]]*#/d' -e 's/[[:space:]]*$//' -e '/^$/d' "$1"; }

WATCH_PATHS=$(strip_comments "$WATCH_FILE")
[ -n "$WATCH_PATHS" ] || die "no paths listed in ${WATCH_FILE}"

: > "$TMPDIR_BP/ignore.txt"
[ -f "$IGNORE_FILE" ] && strip_comments "$IGNORE_FILE" > "$TMPDIR_BP/ignore.txt"

# Resolve a branch name to whichever ref actually exists in this checkout
resolve() {
    git rev-parse --verify --quiet "origin/$1" >/dev/null && { echo "origin/$1"; return 0; }
    git rev-parse --verify --quiet "$1" >/dev/null && { echo "$1"; return 0; }
    return 1
}

emit() {
    printf '%s\n' "$1"
    if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then printf '%s\n' "$1" >> "$GITHUB_STEP_SUMMARY"; fi
    return 0
}

missing_fixes=0
total_fixes=0
total_feats=0

emit "## Backport check"
emit ""
emit "Watching: $(printf '%s' "$WATCH_PATHS" | tr '\n' ' ')"
emit ""

while IFS= read -r pair; do
    [ -n "$pair" ] || continue
    src_name=${pair%%::*}
    dst_name=${pair##*::}

    if ! src=$(resolve "$src_name"); then emit "- skipped \`${src_name}\` -> \`${dst_name}\`: branch not found"; emit ""; continue; fi
    if ! dst=$(resolve "$dst_name"); then emit "- skipped \`${src_name}\` -> \`${dst_name}\`: branch not found"; emit ""; continue; fi

    git log --format='%s' --since="$SINCE" "$dst" > "$TMPDIR_BP/dst.txt" || die "git log failed for ${dst}"
    # shellcheck disable=SC2086
    git log --format='%h|%ad|%s' --date=short --since="$SINCE" "$src" -- $WATCH_PATHS > "$TMPDIR_BP/src.txt" \
        || die "git log failed for ${src}"

    fixes=""
    feats=""
    : > "$TMPDIR_BP/seen.txt"
    while IFS='|' read -r sha date subject; do
        [ -n "${subject:-}" ] || continue
        case "$subject" in
            "fix("*) kind=fix ;;
            "feat("*) kind=feat ;;
            *) continue ;;
        esac
        # already on the target branch?
        grep -Fqx -- "$subject" "$TMPDIR_BP/dst.txt" && continue
        # deliberately excluded, globally or for this target?
        grep -Fqx -- "$subject" "$TMPDIR_BP/ignore.txt" && continue
        grep -Fqx -- "${dst_name}::${subject}" "$TMPDIR_BP/ignore.txt" && continue
        # the same subject can appear more than once on the source branch
        grep -Fqx -- "$subject" "$TMPDIR_BP/seen.txt" && continue
        printf '%s\n' "$subject" >> "$TMPDIR_BP/seen.txt"

        line="| \`$sha\` | $date | $subject |"
        if [ "$kind" = fix ]; then fixes="${fixes}${line}
"; else feats="${feats}${line}
"; fi
    done < "$TMPDIR_BP/src.txt"

    n_fix=$(printf '%s' "$fixes" | grep -c . || true)
    n_feat=$(printf '%s' "$feats" | grep -c . || true)
    total_fixes=$((total_fixes + n_fix))
    total_feats=$((total_feats + n_feat))

    if [ "$n_fix" -eq 0 ] && [ "$n_feat" -eq 0 ]; then
        emit "### \`${src_name}\` -> \`${dst_name}\`: nothing outstanding"
        emit ""
        continue
    fi

    emit "### \`${src_name}\` -> \`${dst_name}\`"
    emit ""
    if [ "$n_fix" -gt 0 ]; then
        missing_fixes=1
        emit "**${n_fix} fix(es) not on \`${dst_name}\`**"
        emit ""
        emit "| commit | date | subject |"
        emit "| --- | --- | --- |"
        emit "$(printf '%s' "$fixes")"
        emit ""
    fi
    if [ "$n_feat" -gt 0 ]; then
        emit "<details><summary>${n_feat} feature commit(s), informational</summary>"
        emit ""
        emit "| commit | date | subject |"
        emit "| --- | --- | --- |"
        emit "$(printf '%s' "$feats")"
        emit ""
        emit "</details>"
        emit ""
    fi
done <<EOF
$PAIRS
EOF

emit "---"
emit ""
if [ "$missing_fixes" -eq 1 ]; then
    emit "**${total_fixes} fix(es) look unbackported.** Backport them, or add the subject to \`${IGNORE_FILE}\` if that is deliberate."
    exit 1
fi
emit "No unbackported fixes. ${total_feats} feature commit(s) listed for information."
exit 0
