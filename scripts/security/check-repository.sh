#!/usr/bin/env bash

set -euo pipefail

repository_root="$(git rev-parse --show-toplevel)"
cd "$repository_root"

forbidden_data_pattern='(^|/)(contacto_digital_(bd|db)|[^/]+\.(db|sqlite|sqlite3|dump|sql|sql\.gz|postman_environment))$'
forbidden_env_pattern='(^|/)\.env($|\.)'
allowed_env_pattern='(^|/)\.env\.example$'

find_forbidden_paths() {
    grep -E "$forbidden_data_pattern" || true
}

find_forbidden_envs() {
    grep -E "$forbidden_env_pattern" | grep -Ev "$allowed_env_pattern" || true
}

tracked_paths="$(git ls-files)"
tracked_forbidden="$(
    {
        printf '%s\n' "$tracked_paths" | find_forbidden_paths
        printf '%s\n' "$tracked_paths" | find_forbidden_envs
    } | sed '/^$/d' | sort -u
)"

history_refs="$(git for-each-ref --format='%(refname)' refs/heads refs/remotes)"
history_paths="$(
    printf '%s\n' "$history_refs" \
        | git rev-list --objects --stdin \
        | cut -d' ' -f2-
)"
history_forbidden="$(
    {
        printf '%s\n' "$history_paths" | find_forbidden_paths
        printf '%s\n' "$history_paths" | find_forbidden_envs
    } | sed '/^$/d' | sort -u
)"

if [[ -n "$tracked_forbidden" || -n "$history_forbidden" ]]; then
    printf 'Erro: o repositório contém ficheiros proibidos.\n' >&2

    if [[ -n "$tracked_forbidden" ]]; then
        printf '\nFicheiros rastreados:\n%s\n' "$tracked_forbidden" >&2
    fi

    if [[ -n "$history_forbidden" ]]; then
        printf '\nFicheiros encontrados no histórico:\n%s\n' "$history_forbidden" >&2
    fi

    exit 1
fi

if [[ "${SKIP_GITLEAKS:-0}" == "1" ]]; then
    exit 0
fi

if ! command -v gitleaks >/dev/null 2>&1; then
    printf 'Erro: instale o Gitleaks antes de enviar alterações.\n' >&2
    exit 1
fi

gitleaks git --no-banner --redact "$repository_root"
