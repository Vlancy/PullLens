#!/usr/bin/env sh
#
# PullLens one-command installer.
#
#   curl -fsSL https://raw.githubusercontent.com/Vlancy/PullLens/main/bootstrap.sh | sh
#
# Installs git if it is missing, clones PullLens, and runs ./install.sh. Arguments are
# passed through to the installer:
#
#   curl -fsSL .../bootstrap.sh | sh -s -- --from-source
#
# Environment:
#   PULLLENS_DIR     where to clone (default: ./PullLens)
#   PULLLENS_BRANCH  which branch to track (default: main)
#   PULLLENS_REPO    where to clone from
#
# Re-running is safe. An existing clone is updated rather than replaced, and
# install.sh preserves the secrets already in .env.

set -eu

repo="${PULLLENS_REPO:-https://github.com/Vlancy/PullLens.git}"
branch="${PULLLENS_BRANCH:-main}"
dir="${PULLLENS_DIR:-PullLens}"

if [ -t 1 ]; then
    bold="$(printf '\033[1m')"
    blue="$(printf '\033[34m')"
    green="$(printf '\033[32m')"
    yellow="$(printf '\033[33m')"
    red="$(printf '\033[31m')"
    reset="$(printf '\033[0m')"
else
    bold=""; blue=""; green=""; yellow=""; red=""; reset=""
fi

section() { printf '\n%s%s%s\n' "$bold" "$1" "$reset"; }
info()    { printf '%s[INFO]%s %s\n' "$blue" "$reset" "$1"; }
success() { printf '%s[OK]%s %s\n' "$green" "$reset" "$1"; }
warn()    { printf '%s[WARN]%s %s\n' "$yellow" "$reset" "$1"; }
fail()    { printf '%s[ERROR]%s %s\n' "$red" "$reset" "$1" >&2; }

command_exists() { command -v "$1" >/dev/null 2>&1; }

as_root() {
    if [ "$(id -u)" = "0" ]; then
        "$@"
    elif command_exists sudo; then
        sudo "$@"
    else
        fail "This needs root and sudo was not found. Re-run as root."
        exit 1
    fi
}

ensure_git() {
    command_exists git && return 0

    section "Installing Git"
    info "Git is needed to fetch PullLens and to update it later."

    if command_exists apt-get; then
        as_root apt-get update -qq
        as_root apt-get install -y -qq git
    elif command_exists dnf; then
        as_root dnf install -y -q git
    elif command_exists yum; then
        as_root yum install -y -q git
    elif command_exists zypper; then
        as_root zypper --non-interactive install git
    elif command_exists pacman; then
        as_root pacman -Sy --noconfirm git
    elif command_exists apk; then
        as_root apk add --no-cache git
    else
        fail "No supported package manager found. Install git, then re-run this."
        exit 1
    fi

    success "Git is installed."
}

fetch_source() {
    if [ -d "$dir/.git" ]; then
        section "Updating PullLens"
        info "$dir already exists, so it is updated rather than replaced."
        cd "$dir"
        git fetch origin "$branch" --quiet
        # --ff-only refuses rather than merging over local edits. If it fails, the
        # operator has changed a tracked file and needs to decide what to keep.
        if ! git merge --ff-only "origin/$branch" --quiet; then
            fail "Cannot fast-forward $dir to origin/$branch."
            fail "Local changes to tracked files are in the way. Resolve them, then re-run."
            exit 1
        fi
        success "Source is up to date."
        return
    fi

    if [ -e "$dir" ]; then
        fail "$dir already exists and is not a PullLens clone."
        fail "Move it aside, or set PULLLENS_DIR to somewhere else."
        exit 1
    fi

    section "Fetching PullLens"
    git clone --branch "$branch" --quiet "$repo" "$dir"
    cd "$dir"
    success "Cloned into $dir."
}

run_installer() {
    [ -f ./install.sh ] || { fail "install.sh is missing from the clone."; exit 1; }
    chmod +x ./install.sh 2>/dev/null || true

    # This script is usually reached through `curl | sh`, which makes the pipe its
    # standard input. The installer asks real questions - the address to serve on, and
    # whether to get a certificate - and reading those from a pipe returns EOF
    # immediately, so every one of them would silently take its default and the
    # operator would never see a prompt. Hand the installer the terminal instead.
    # Testing with [ -r /dev/tty ] is not enough: the device node can be readable and
    # still fail to open, which aborts the redirect after the clone has happened. Try
    # opening it in a subshell and believe the result.
    if (exec < /dev/tty) 2>/dev/null; then
        sh ./install.sh "$@" < /dev/tty
    else
        warn "No terminal is attached, so the installer cannot ask anything."
        warn "It will use the defaults in .env.example. To answer the questions, run:"
        warn "  cd $dir && ./install.sh"
        sh ./install.sh "$@"
    fi
}

ensure_git
fetch_source
run_installer "$@"
