<?php

/**
 * Sonda de diagnóstico da API ZoneSoft — leitura apenas.
 *
 * Objetivo: observar como os documentos chegam de UMA máquina real:
 * paginação, estrutura da resposta, tempos e volume por página.
 *
 * Não escreve nada na base de dados. Não imprime credenciais.
 *
 * Uso:
 *   php scripts/diagnostics/zonesoft-probe.php
 *       -> lista as máquinas disponíveis e sai
 *
 *   php scripts/diagnostics/zonesoft-probe.php <machine_id> [inicio] [fim] [limit]
 *       -> sonda uma máquina; datas em YYYY-MM-DD (default: janela do evento)
 *
 * Exemplos:
 *   php scripts/diagnostics/zonesoft-probe.php 93
 *   php scripts/diagnostics/zonesoft-probe.php 93 2026-07-18 2026-07-19
 *   php scripts/diagnostics/zonesoft-probe.php 93 2026-07-18 2026-07-19 250
 */

$root = dirname(__DIR__, 2);

require $root.'/vendor/autoload.php';

$app = require_once $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ClientZoneSoftMachine;

function out(string $line = ''): void
{
    fwrite(STDOUT, $line.PHP_EOL);
}

function rule(string $title = ''): void
{
    out($title !== '' ? PHP_EOL.'── '.$title.' '.str_repeat('─', max(0, 62 - mb_strlen($title))) : str_repeat('─', 66));
}

/**
 * Chamada crua à ZSAPI, sem passar pelo ZoneSoftApiClient, para observarmos
 * exatamente o que vem no fio (bytes, tempos, envelope).
 *
 * @return array{ok:bool,status:int,ms:float,bytes:int,payload:mixed,error:string|null}
 */
function zsCall(object $application, string $zsClientId, string $interface, string $action, string $entity, array $payload): array
{
    $body = json_encode([$entity => $payload], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $baseUrl = rtrim($application->base_url, '/');
    if (! str_ends_with($baseUrl, '/v3')) {
        $baseUrl .= '/v3';
    }
    $url = sprintf('%s/%s/%s', $baseUrl, $interface, $action);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-ZS-APP-KEY: '.$application->app_key,
            'X-ZS-CLIENT-ID: '.$zsClientId,
            'X-ZS-SIGNATURE: '.hash_hmac('sha256', $body, $application->app_secret),
        ],
    ]);

    $startedAt = microtime(true);
    $raw = curl_exec($ch);
    $ms = (microtime(true) - $startedAt) * 1000;
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch) ?: null;
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'status' => 0, 'ms' => $ms, 'bytes' => 0, 'payload' => null, 'error' => $error];
    }

    $decoded = json_decode($raw, true);
    $envelope = is_array($decoded) ? ($decoded['Response'] ?? null) : null;
    $innerStatus = is_array($envelope) ? (int) ($envelope['StatusCode'] ?? $status) : $status;
    $content = is_array($envelope) ? ($envelope['Content'] ?? null) : $decoded;

    return [
        'ok' => $innerStatus < 400 && $status < 400,
        'status' => $innerStatus,
        'ms' => $ms,
        'bytes' => strlen($raw),
        'payload' => $content,
        'error' => $innerStatus >= 400 ? (string) ($envelope['StatusMessage'] ?? 'erro sem mensagem') : null,
    ];
}

function describeShape(array $document): void
{
    $keys = array_keys($document);
    sort($keys);

    out('  Campos de topo do documento ('.count($keys).'):');
    foreach (array_chunk($keys, 6) as $chunk) {
        out('    '.implode(', ', $chunk));
    }

    foreach (['vendas' => 'linhas de venda', 'pagamentos' => 'pagamentos', 'documentospagamento' => 'documentos de pagamento'] as $key => $label) {
        if (! array_key_exists($key, $document)) {
            out(sprintf('  %-22s AUSENTE', $key));

            continue;
        }

        $value = $document[$key];
        $count = is_array($value) ? count($value) : 0;
        out(sprintf('  %-22s presente, %d %s', $key, $count, $label));

        if (is_array($value) && $count > 0 && is_array($value[0] ?? null)) {
            $childKeys = array_keys($value[0]);
            sort($childKeys);
            out('      campos: '.implode(', ', array_slice($childKeys, 0, 18)).(count($childKeys) > 18 ? ', …' : ''));
        }
    }
}

// ---------------------------------------------------------------- listagem

$machineId = isset($argv[1]) ? (int) $argv[1] : 0;

if ($machineId <= 0) {
    rule('Máquinas disponíveis');
    $machines = ClientZoneSoftMachine::query()->with('application')->orderBy('id')->get();

    out(sprintf('  %-6s %-8s %-34s %-8s %s', 'id', 'loja', 'etiqueta', 'ativa', 'aplicação'));
    foreach ($machines as $machine) {
        out(sprintf(
            '  %-6s %-8s %-34s %-8s %s',
            $machine->id,
            $machine->store_id,
            mb_substr((string) $machine->store_label, 0, 34),
            $machine->is_active ? 'sim' : 'não',
            $machine->application?->name ?? '—',
        ));
    }
    out(PHP_EOL.'  Corre outra vez com o id da máquina, por exemplo:');
    out('    php scripts/diagnostics/zonesoft-probe.php '.($machines->first()->id ?? 1));
    exit(0);
}

$machine = ClientZoneSoftMachine::query()->with(['application', 'events'])->find($machineId);

if (! $machine) {
    out('Máquina '.$machineId.' não encontrada.');
    exit(1);
}

if (! $machine->application) {
    out('A máquina '.$machineId.' não tem aplicação ZoneSoft associada.');
    exit(1);
}

$event = $machine->events->first();
$start = $argv[2] ?? $event?->report_starts_at?->toDateString() ?? now()->subDay()->toDateString();
$end = $argv[3] ?? $event?->report_ends_at?->toDateString() ?? now()->toDateString();
$limit = isset($argv[4]) ? (int) $argv[4] : 250;

rule('Contexto');
out('  máquina        : #'.$machine->id.'  '.$machine->store_label);
out('  loja (store_id): '.$machine->store_id);
out('  client-id      : '.substr((string) $machine->zs_client_id, 0, 6).'…  (truncado)');
out('  aplicação      : '.$machine->application->name.'  →  '.$machine->application->base_url);
out('  evento         : '.($event ? '#'.$event->id.' '.$event->title : '— sem evento associado —'));
out('  intervalo      : '.$start.' a '.$end);
out('  limit por pág. : '.$limit);

$condition = sprintf(
    "loja = %d and data >= '%s' and data <= '%s'",
    $machine->store_id,
    $start,
    $end,
);
out('  condition      : '.$condition);

// ------------------------------------------------------- paginação real

rule('Paginação — documents/getInstances (documento completo)');
out(sprintf('  %-5s %-8s %8s %10s %12s %10s', 'pág', 'offset', 'devolv.', 'ms', 'bytes', 'acum.'));

$offset = 0;
$page = 0;
$total = 0;
$totalMs = 0.0;
$totalBytes = 0;
$firstDocument = null;
$seen = [];
$duplicates = 0;

do {
    $page++;

    $result = zsCall($machine->application, $machine->zs_client_id, 'documents', 'getInstances', 'document', [
        'condition' => $condition,
        'order' => 'data ASC, numero ASC',
        'limit' => $limit,
        'offset' => $offset,
    ]);

    if (! $result['ok']) {
        out(sprintf('  %-5s %-8s  ERRO status=%s  %s', $page, $offset, $result['status'], $result['error'] ?? ''));
        break;
    }

    $batch = is_array($result['payload']['document'] ?? null)
        ? array_values(array_filter($result['payload']['document'], 'is_array'))
        : [];

    $count = count($batch);
    $total += $count;
    $totalMs += $result['ms'];
    $totalBytes += $result['bytes'];

    foreach ($batch as $document) {
        $key = ($document['doc'] ?? '').'|'.($document['serie'] ?? '').'|'.($document['numero'] ?? '');
        if (isset($seen[$key])) {
            $duplicates++;
        }
        $seen[$key] = true;
    }

    $firstDocument ??= $batch[0] ?? null;

    out(sprintf(
        '  %-5s %-8s %8d %10s %12s %10d',
        $page,
        $offset,
        $count,
        number_format($result['ms'], 0),
        number_format($result['bytes']),
        $total,
    ));

    $offset += $count;

    if ($page >= 40) {
        out('  ⚠ paragem de segurança às 40 páginas');
        break;
    }
} while ($count === $limit);

rule('Resumo');
out('  páginas pedidas      : '.$page);
out('  documentos recebidos : '.$total);
out('  chaves distintas     : '.count($seen).($duplicates > 0 ? '   ⚠ '.$duplicates.' DUPLICADOS entre páginas' : '   (sem duplicados)'));
out('  tempo total na API   : '.number_format($totalMs, 0).' ms');
out('  tempo médio/página   : '.($page > 0 ? number_format($totalMs / $page, 0) : 0).' ms');
out('  bytes recebidos      : '.number_format($totalBytes).'  ('.number_format($totalBytes / 1048576, 2).' MB)');
out('  bytes/documento      : '.($total > 0 ? number_format($totalBytes / $total, 0) : 0));

if ($firstDocument !== null) {
    rule('Estrutura de um documento');
    describeShape($firstDocument);
}

// ------------------------------------------- o limite de 250 é mesmo teto?

rule('O limite de 250 é respeitado?');
$probe = zsCall($machine->application, $machine->zs_client_id, 'documents', 'getInstances', 'document', [
    'condition' => $condition,
    'order' => 'data ASC, numero ASC',
    'limit' => 1000,
    'offset' => 0,
]);
if ($probe['ok']) {
    $n = is_array($probe['payload']['document'] ?? null) ? count($probe['payload']['document']) : 0;
    out('  pedido limit=1000  →  devolveu '.$n.' documentos em '.number_format($probe['ms'], 0).' ms');
    out($n > 250
        ? '  ✅ a API aceita mais de 250 por página — podemos reduzir muito o nº de pedidos'
        : '  ℹ a API trunca em 250, como o manual indica');
} else {
    out('  pedido limit=1000  →  erro '.$probe['status'].' '.($probe['error'] ?? ''));
}

// ------------------------------------------- alternativa: cursor lastupdate

rule('Alternativa a offset — paginação por lastupdate (PERF-103)');
$keyset = zsCall($machine->application, $machine->zs_client_id, 'documents', 'getInstances', 'document', [
    'condition' => $condition,
    'order' => 'lastupdate ASC, numero ASC',
    'limit' => 5,
    'offset' => 0,
]);
if ($keyset['ok']) {
    $batch = is_array($keyset['payload']['document'] ?? null) ? $keyset['payload']['document'] : [];
    out('  ordenação por lastupdate ASC devolveu '.count($batch).' documentos');
    foreach (array_slice($batch, 0, 5) as $document) {
        out(sprintf(
            '    %-4s %-12s %-8s lastupdate=%s',
            $document['doc'] ?? '?',
            $document['serie'] ?? '?',
            $document['numero'] ?? '?',
            $document['lastupdate'] ?? '(campo ausente!)',
        ));
    }
    out(isset($batch[0]['lastupdate'])
        ? '  ✅ lastupdate vem no documento — paginação por chave é viável'
        : '  ⚠ lastupdate NÃO vem no documento — é preciso outro campo estável para o cursor');
} else {
    out('  erro '.$keyset['status'].' '.($keyset['error'] ?? ''));
}

rule();
out('Sonda concluída. Nada foi escrito na base de dados.');
