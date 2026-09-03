#!/usr/bin/env bash
set -euo pipefail

# PERF-501 fase 3 — corte de produção SQLite -> PostgreSQL. UMA VEZ.
# Não faz parte do pipeline de deploy normal (não corre em cada push) —
# invoca-se manualmente, uma única vez, quando se decide avançar.
#
# Escrito depois de uma tentativa manual, passo a passo, ter deixado a app
# a servir tráfego real apontada para um Postgres com schema incompleto: a
# migração de dados falhou a meio (uma tabela sem equivalente no destino),
# mas os passos seguintes correram na mesma porque cada comando era
# invocado manualmente, sem nada a impedir continuar depois de uma falha.
# Este script existe para tornar essa sequência atómica: qualquer falha em
# qualquer passo aborta imediatamente e o `trap` abaixo desfaz tudo o que
# já tinha corrido (reverte DB_CONNECTION se cheguem a ser alterados,
# tira a app do modo de manutenção) — nunca fica a meio.
#
# Ver docs/PLANO_DE_PERFORMANCE_SINCRONIZACAO.md (PERF-501) para o
# contexto completo antes de correr isto.

project_dir="/srv/projects/contacto-digital"
env_file="${project_dir}/.env.production"
timestamp="$(date +%Y%m%d-%H%M%S)"
env_backup_file="${env_file}.bak-cutover-${timestamp}"
lock_file="/var/lock/contacto-digital-cutover.lock"

maintenance_entered=0
cutover_committed=0

cleanup() {
  exit_code=$?

  if [ "${exit_code}" -eq 0 ]; then
    return
  fi

  echo "CUTOVER ABORTOU (codigo ${exit_code}) — a desfazer o que ja correu." >&2

  cd "${project_dir}"

  if [ "${cutover_committed}" -eq 1 ] && [ -f "${env_backup_file}" ]; then
    echo "A reverter .env.production e a reiniciar com a configuracao anterior (sqlite)..." >&2
    cp "${env_backup_file}" "${env_file}"
    docker compose --env-file "${env_file}" -f compose.yaml up -d --no-deps app || true
    sleep 3
  fi

  if [ "${maintenance_entered}" -eq 1 ]; then
    # worker/scheduler were stopped in step 2 regardless of how far past
    # that the failure happened — always bring them back, not only on the
    # post-cutover revert path (found this gap: a failure between steps 2
    # and 7 left them stopped after the app itself was already healthy
    # again).
    docker compose --env-file "${env_file}" -f compose.yaml up -d --no-deps worker scheduler || true
    docker exec contacto_digital_portal_app php artisan up || true
  fi

  echo "Estado revertido. A base de dados sqlite nunca foi alterada — nada foi perdido." >&2
}
trap cleanup EXIT

exec 9>"${lock_file}"
flock -n 9 || {
  echo "Ja ha um corte em curso." >&2
  exit 1
}

cd "${project_dir}"

test -f "${env_file}"
grep -q '^POSTGRES_USER=' "${env_file}"
grep -q '^POSTGRES_PASSWORD=' "${env_file}"
grep -q '^POSTGRES_DB=' "${env_file}"
grep -q '^SQLITE_DATABASE=' "${env_file}"

pgdb="$(grep '^POSTGRES_DB=' "${env_file}" | cut -d= -f2-)"

echo "--- 0. nenhum evento ativo agora ---"
active_events="$(docker exec contacto_digital_portal_app php artisan tinker --execute='
$now = now();
echo DB::table("events")->where("is_active", true)->where("report_starts_at", "<=", $now)->where("report_ends_at", ">=", $now)->count();
')"
if [ "${active_events}" != "0" ]; then
  echo "A abortar: ${active_events} evento(s) ativo(s) agora. Nao correr durante um evento ao vivo." >&2
  exit 1
fi

echo "--- 1. reiniciar para captar variaveis novas (DB_CONNECTION continua sqlite) ---"
docker compose --env-file "${env_file}" -f compose.yaml up -d --no-deps app worker scheduler
sleep 3
curl --fail --silent --show-error https://portal.contactodigital.pt/up >/dev/null
if ! grep -q '^DB_CONNECTION=sqlite$' "${env_file}"; then
  echo "A abortar: DB_CONNECTION nao e sqlite antes de comecar." >&2
  exit 1
fi

echo "--- 2. modo de manutencao ---"
docker compose --env-file "${env_file}" -f compose.yaml stop scheduler
docker compose --env-file "${env_file}" -f compose.yaml stop --timeout "${WORKER_STOP_TIMEOUT_SECONDS:-900}" worker
docker exec contacto_digital_portal_app php artisan down --retry=60
maintenance_entered=1

echo "--- 3. backup final e consistente do sqlite, com verificacao de integridade ---"
docker exec -e CUTOVER_TIMESTAMP="${timestamp}" contacto_digital_portal_app php -r '
umask(0077);
$src = getenv("SQLITE_DATABASE");
// Timestamped so re-running after an earlier aborted attempt never
// collides with a leftover file from that attempt (VACUUM INTO refuses
// to overwrite an existing destination).
$dst = "/var/www/shared/database/pre-postgres-cutover-".getenv("CUTOVER_TIMESTAMP").".sqlite";
$pdo = new PDO("sqlite:".$src);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("PRAGMA busy_timeout=30000");
$pdo->exec("VACUUM INTO ".$pdo->quote($dst));
$check = new PDO("sqlite:".$dst);
if ($check->query("PRAGMA integrity_check")->fetchAll(PDO::FETCH_COLUMN) !== ["ok"]) {
    throw new RuntimeException("backup falhou na verificacao de integridade");
}
echo "backup OK: ".$dst.PHP_EOL;
'

echo "--- 4. criar schema no Postgres (a partir do zero) ---"
docker exec -e DB_CONNECTION=pgsql -e DB_DATABASE="${pgdb}" contacto_digital_portal_app \
  php artisan migrate --database=pgsql --force

echo "--- 5. migrar os dados reais ---"
docker exec -e DB_CONNECTION=pgsql -e DB_DATABASE="${pgdb}" contacto_digital_portal_app \
  php artisan app:migrate-sqlite-to-postgres --force

echo "--- 6. verificacao final antes do ponto sem retorno: schema completo ---"
docker exec -e DB_CONNECTION=pgsql -e DB_DATABASE="${pgdb}" contacto_digital_portal_app php artisan tinker --execute='
$tables = [
    "users", "clients", "zonesoft_applications", "client_zonesoft_machines",
    "events", "event_zonesoft_machines", "event_report_imports",
    "event_report_rows", "event_report_payment_documents",
    "event_report_row_aggregates", "event_report_ticket_aggregates",
];
foreach ($tables as $table) {
    if (! Schema::connection("pgsql")->hasTable($table)) {
        throw new RuntimeException("verificacao de corte falhou: tabela em falta no destino: ".$table);
    }
}
echo "verificacao de schema OK".PHP_EOL;
'

echo "--- 7. ponto sem retorno: mudar DB_CONNECTION para pgsql ---"
cp "${env_file}" "${env_backup_file}"
cutover_committed=1
sed -i \
  -e 's/^DB_CONNECTION=sqlite$/DB_CONNECTION=pgsql/' \
  -e "s|^DB_DATABASE=.*|DB_DATABASE=${pgdb}|" \
  "${env_file}"
docker compose --env-file "${env_file}" -f compose.yaml up -d --no-deps app
sleep 3
docker exec contacto_digital_portal_app php artisan up
maintenance_entered=0
docker compose --env-file "${env_file}" -f compose.yaml up -d --no-deps worker scheduler

curl --fail --silent --show-error https://portal.contactodigital.pt/up >/dev/null

echo "Corte concluido. .env.production anterior guardado em: ${env_backup_file}"
echo "Para reverter manualmente mais tarde: cp ${env_backup_file} ${env_file} && docker compose --env-file ${env_file} -f compose.yaml up -d --no-deps app worker scheduler"
