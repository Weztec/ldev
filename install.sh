#!/usr/bin/env bash
set -euo pipefail

if [[ $EUID -ne 0 || -z "${SUDO_USER:-}" ]]; then
    echo "This script must be run with sudo (not as a raw root login, e.g. 'su -') — it needs \$SUDO_USER to know which non-root account to configure"
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

"$SCRIPT_DIR/setup-environment.sh"
"$SCRIPT_DIR/deploy-app.sh"
