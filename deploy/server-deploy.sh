#!/usr/bin/env bash
set -euo pipefail

project_dir="/srv/projects/contacto-digital"
backup_root="/srv/backups/contacto-digital"
proxy_conf_dir="/srv/proxy/conf"
proxy_conf_file="${proxy_conf_dir}/portal.contactodigital.pt.conf"
lock_file="/var/lock/contacto-digital-deploy.lock"
release_dir="$(mktemp -d /tmp/contacto-digital-release.XXXXXX)"
timestamp="$(date +%Y%m%d-%H%M%S)"
backup_dir="${backup_root}/${timestamp}"
database_path="${project_dir}/shared/database/contacto_digital_bd.sqlite"
database_backup_temp="${project_dir}/shared/database/.pre-deploy-${timestamp}.sqlite"
requested_sha="${SSH_ORIGINAL_COMMAND:-unknown}"
migration_services_stopped=0
worker_stop_timeout_seconds="${WORKER_STOP_TIMEOUT_SECONDS:-900}"

if [[ "${requested_sha}" =~ ^[0-9a-f]{40}$ ]]; then
  release_label="${requested_sha}"
else
  release_label="unknown"
fi

cleanup() {
  rm -rf "${release_dir}"
  rm -f "${database_backup_temp}"

  if [ "${migration_services_stopped}" -eq 1 ] && [ -f "${project_dir}/compose.yaml" ]; then
    echo "Deployment stopped. Keep maintenance mode and workers stopped until the failure is investigated. Backup: ${backup_dir}" >&2
  fi
}
trap cleanup EXIT

exec 9>"${lock_file}"
flock -n 9 || {
  echo "Another deployment is already running." >&2
  exit 1
}

tar -xzf - -C "${release_dir}"

test -f "${release_dir}/Dockerfile"
test -f "${release_dir}/compose.yaml"
test -f "${release_dir}/nginx/default.conf"
test -f "${release_dir}/proxy/portal.contactodigital.pt.conf"
test -f "${release_dir}/app-runtime/artisan"
test -f "${release_dir}/app-runtime/public/index.php"
test -f "${release_dir}/app-runtime/public/build/manifest.json"

if find "${release_dir}" -type l -print -quit | grep -q .; then
  echo "Release archives may not contain symbolic links." >&2
  exit 1
fi

install -d -m 0750 "${backup_dir}"

if [ ! -f "${project_dir}/.env.production" ] || [ ! -s "${database_path}" ]; then
  echo "Missing production configuration or database; refusing to deploy without a backup." >&2
  exit 1
fi

cd "${project_dir}"
docker compose --env-file .env.production -f compose.yaml config --quiet
migration_services_stopped=1
docker compose --env-file .env.production -f compose.yaml stop scheduler
docker compose --env-file .env.production -f compose.yaml stop \
  --timeout "${worker_stop_timeout_seconds}" worker
docker exec contacto_digital_portal_app php artisan down --retry=60

# Back up the live database before replacing any runtime files or schema.
docker exec contacto_digital_portal_app php -r '
  umask(0077);
  $source = getenv("DB_DATABASE");
  $target = "/var/www/shared/database/'"$(basename "${database_backup_temp}")"'";
  $pdo = new PDO("sqlite:".$source);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->exec("PRAGMA busy_timeout=30000");
  if ((int) $pdo->query("SELECT COUNT(*) FROM event_report_imports WHERE status = '\''processing'\''")->fetchColumn() > 0) {
    throw new RuntimeException("A sync is still processing; investigate before deploying.");
  }
  $pdo->exec("VACUUM INTO ".$pdo->quote($target));
  $backup = new PDO("sqlite:".$target);
  if ($backup->query("PRAGMA integrity_check")->fetchAll(PDO::FETCH_COLUMN) !== ["ok"]
      || $backup->query("PRAGMA foreign_key_check")->fetchAll() !== []) {
    throw new RuntimeException("Database backup failed integrity validation.");
  }
'
test -s "${database_backup_temp}"
install -m 0600 "${database_backup_temp}" "${backup_dir}/contacto_digital_bd.sqlite"
sha256sum "${backup_dir}/contacto_digital_bd.sqlite"
echo "Verified database backup: ${backup_dir}/contacto_digital_bd.sqlite"
rm -f "${database_backup_temp}"

install -d -m 0755 \
  "${project_dir}" \
  "${project_dir}/nginx" \
  "${project_dir}/shared/database" \
  "${project_dir}/shared/storage/app/public" \
  "${project_dir}/shared/storage/framework/cache/data" \
  "${project_dir}/shared/storage/framework/sessions" \
  "${project_dir}/shared/storage/framework/testing" \
  "${project_dir}/shared/storage/framework/views" \
  "${project_dir}/shared/storage/logs" \
  "${proxy_conf_dir}"

chown -R 33:33 \
  "${project_dir}/shared/storage" \
  "${project_dir}/shared/database"

find "${project_dir}/shared/storage" -type d -exec chmod 0775 {} \;
find "${project_dir}/shared/storage" -type f -exec chmod 0664 {} \;
find "${project_dir}/shared/database" -type d -exec chmod 0775 {} \;
find "${project_dir}/shared/database" -type f -exec chmod 0664 {} \;

if [ -f "${project_dir}/Dockerfile" ]; then
  cp -a "${project_dir}/Dockerfile" "${backup_dir}/Dockerfile"
fi

if [ -f "${project_dir}/compose.yaml" ]; then
  cp -a "${project_dir}/compose.yaml" "${backup_dir}/compose.yaml"
fi

if [ -d "${project_dir}/nginx" ]; then
  cp -a "${project_dir}/nginx" "${backup_dir}/nginx"
fi

if [ -d "${project_dir}/app-runtime" ]; then
  cp -a "${project_dir}/app-runtime" "${backup_dir}/app-runtime"
fi

if [ -f "${proxy_conf_file}" ]; then
  cp -a "${proxy_conf_file}" "${backup_dir}/portal.contactodigital.pt.conf"
fi

install -m 0644 "${release_dir}/Dockerfile" "${project_dir}/Dockerfile"
install -m 0644 "${release_dir}/compose.yaml" "${project_dir}/compose.yaml"
install -d -m 0755 "${project_dir}/nginx"
install -m 0644 "${release_dir}/nginx/default.conf" "${project_dir}/nginx/default.conf"
install -d -m 0755 "${project_dir}/app-runtime"
rsync -a --delete "${release_dir}/app-runtime/" "${project_dir}/app-runtime/"

rm -f "${project_dir}/app-runtime/public/storage"
ln -s /var/www/html/storage/app/public "${project_dir}/app-runtime/public/storage"

if [ ! -f "${project_dir}/shared/database/contacto_digital_bd.sqlite" ] && [ -f "${release_dir}/seed/contacto_digital_bd.sqlite" ]; then
  install -m 0644 "${release_dir}/seed/contacto_digital_bd.sqlite" \
    "${project_dir}/shared/database/contacto_digital_bd.sqlite"
fi

chown -R 33:33 \
  "${project_dir}/shared/storage" \
  "${project_dir}/shared/database"

find "${project_dir}/shared/storage" -type d -exec chmod 0775 {} \;
find "${project_dir}/shared/storage" -type f -exec chmod 0664 {} \;
find "${project_dir}/shared/database" -type d -exec chmod 0775 {} \;
find "${project_dir}/shared/database" -type f -exec chmod 0664 {} \;

install -m 0644 "${release_dir}/proxy/portal.contactodigital.pt.conf" "${proxy_conf_file}"

if [ ! -f "${project_dir}/.env.production" ]; then
  echo "Missing ${project_dir}/.env.production." >&2
  exit 1
fi

cd "${project_dir}"

docker compose --env-file .env.production -f compose.yaml config --quiet
docker compose --env-file .env.production -f compose.yaml build app
docker compose --env-file .env.production -f compose.yaml run --rm --no-deps app php artisan migrate --force
docker compose --env-file .env.production -f compose.yaml up -d --no-deps app web worker scheduler
docker exec contacto_digital_portal_app php artisan up
migration_services_stopped=0

if docker ps --format '{{.Names}}' | grep -qx proxy_nginx; then
  docker exec proxy_nginx nginx -s reload
fi

for _ in $(seq 1 30); do
  status="$(docker inspect contacto_digital_portal_web --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}')"
  if [ "${status}" = "healthy" ]; then
    curl --fail --silent --show-error https://portal.contactodigital.pt/up >/dev/null
    echo "Deployment completed from ${release_label}."
    exit 0
  fi
  sleep 3
done

docker logs --tail 100 contacto_digital_portal_app >&2 || true
docker logs --tail 100 contacto_digital_portal_web >&2 || true
docker logs --tail 100 contacto_digital_portal_worker >&2 || true
docker logs --tail 100 contacto_digital_portal_scheduler >&2 || true
echo "Application did not become healthy." >&2
exit 1
