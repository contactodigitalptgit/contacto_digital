#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
runtime_dir="${repo_root}/deploy/app-runtime"
seed_dir="${repo_root}/deploy/seed"

cd "${repo_root}"

rm -rf "${runtime_dir}" "${seed_dir}"
mkdir -p "${runtime_dir}" "${seed_dir}"

rsync -a --delete \
  --exclude='.git' \
  --exclude='.github' \
  --exclude='.githooks' \
  --exclude='node_modules' \
  --exclude='vendor/bin' \
  --exclude='tests' \
  --exclude='docs' \
  --exclude='tmp' \
  --exclude='deploy' \
  --exclude='scripts' \
  --exclude='.env' \
  --exclude='.env.*' \
  --exclude='.DS_Store' \
  --exclude='.phpunit.result.cache' \
  --exclude='contacto_digital_bd' \
  --exclude='contacto_digital_db' \
  --exclude='database/*.sqlite' \
  --exclude='database/*.sqlite-*' \
  --exclude='database/*.sql' \
  --exclude='database/.DS_Store' \
  --exclude='storage/logs/*' \
  --exclude='storage/framework/cache/*' \
  --exclude='storage/framework/sessions/*' \
  --exclude='storage/framework/testing/*' \
  --exclude='storage/framework/views/*' \
  --exclude='storage/app/public/*' \
  ./ "${runtime_dir}/"

install -d -m 0755 \
  "${runtime_dir}/storage/app/public" \
  "${runtime_dir}/storage/framework/cache/data" \
  "${runtime_dir}/storage/framework/sessions" \
  "${runtime_dir}/storage/framework/testing" \
  "${runtime_dir}/storage/framework/views" \
  "${runtime_dir}/storage/logs"

install -m 0644 "database/contacto_digital_bd.sqlite" "${seed_dir}/contacto_digital_bd.sqlite"

test -f "${runtime_dir}/artisan"
test -f "${runtime_dir}/bootstrap/app.php"
test -f "${runtime_dir}/vendor/autoload.php"
test -f "${runtime_dir}/public/index.php"
test -f "${runtime_dir}/public/build/manifest.json"
test -f "${runtime_dir}/bootstrap/ssr/ssr.js"
test -f "${seed_dir}/contacto_digital_bd.sqlite"
