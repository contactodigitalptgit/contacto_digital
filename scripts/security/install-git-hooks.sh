#!/usr/bin/env bash

set -euo pipefail

repository_root="$(git rev-parse --show-toplevel)"
cd "$repository_root"

git config core.hooksPath .githooks
chmod +x .githooks/pre-push
chmod +x scripts/security/check-repository.sh

printf 'Hooks de segurança instalados para %s\n' "$repository_root"
