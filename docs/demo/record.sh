#!/usr/bin/env bash
# Records docs/demo.gif against the real Claude Code, logged in.
# Needs asciinema 3 and agg (https://github.com/asciinema/agg).
#   docs/demo/record.sh      (from the repository root)

set -euo pipefail

repo=$(cd "$(dirname "$0")/../.." && pwd)

if [ "${1:-}" = scene ]; then
    # A fresh copy of the project, so that each recording starts with the failing test.
    rm -rf /tmp/acp-demo && mkdir /tmp/acp-demo
    cp -R "$repo/docs/demo/project" /tmp/acp-demo/cart
    ln -s "$repo/docs/demo/demo.php" /tmp/acp-demo/demo.php
    cd /tmp/acp-demo

    run() {
        printf '\e[32m$\e[0m '
        for ((i = 0; i < ${#1}; i++)); do
            printf '%s' "${1:i:1}"
            sleep 0.035
        done
        sleep 0.4
        printf '\n'
        eval "$1" || true
        sleep 1.5
        printf '\n'
    }

    run 'php cart/tests/CartTest.php'
    run 'php demo.php cart "tests/CartTest.php fails. Fix it."'
    run 'php cart/tests/CartTest.php'
    exit
fi

cast=$(mktemp -t acp-demo).cast
asciinema rec --headless --window-size 100x24 --overwrite --command "$0 scene" "$cast"
agg --theme monokai --font-size 16 --idle-time-limit 1.5 --last-frame-duration 5 "$cast" "$repo/docs/demo.gif"
rm -f "$cast"
echo "docs/demo.gif written."
