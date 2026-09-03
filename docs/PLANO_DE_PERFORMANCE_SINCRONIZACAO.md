# Plano de Performance da Sincronização — Escala de 200 Máquinas

**Projeto:** Contacto Digital
**Estado do documento:** Proposta para aprovação
**Data:** 2 de setembro de 2026
**Âmbito:** `EventReportSyncService`, `ZoneSoftApiClient`, `EventDashboardController`, persistência e infraestrutura
**Documentos relacionados:** [Padrão de Implementação Segura](./PADRAO_DE_IMPLEMENTACAO_SEGURA.md), [Plano de Correção Pré-Comercial](./PLANO_DE_CORRECAO_PRE_COMERCIAL.md), [Relatório de Correção da Sincronização](./RELATORIO_CORRECAO_SINCRONIZACAO_2026-08-02.md)

---

## 1. Objetivo

Permitir que um evento com **200 TPAs ativas** alimente o dashboard com dados
praticamente em tempo real, sem exceder os limites da API ZoneSoft, sem degradar
a consistência financeira e sem perder a garantia de que uma falha parcial nunca
substitui o último snapshot válido.

Metas mensuráveis propostas:

| Indicador | Hoje (43 máquinas) | Alvo (200 máquinas) |
| --- | ---: | ---: |
| Abertura do dashboard | 0,5 s a 30 s (com timeouts registados) | `< 300 ms` (p95) |
| Latência dos dados no dashboard | até 15 min | `<= 60 s` |
| Duração de um ciclo de sincronização | 72 s local / 460 s produção | `<= 15 s` (p95) |
| Escritas em base por ciclo | ~17.000 linhas | `< 500` linhas |
| Eventos em sincronização simultânea | 1 (global) | `>= 10` |

## 2. Pressupostos Assumidos

Estes pressupostos foram assumidos para desbloquear o planeamento e devem ser
confirmados antes da execução. Se algum for alterado, o plano muda.

| # | Pressuposto | Impacto se for falso |
| --- | --- | --- |
| P1 | PostgreSQL e Redis serão adotados (CR-201/CR-202) antes da Fase 3 | Sem escritores concorrentes, o teto do sistema é o lock único do SQLite e as Fases 3 a 5 perdem a maior parte do ganho |
| P2 | O limite de pedidos da ZoneSoft é desconhecido e será tratado de forma conservadora | Podemos estar a subdimensionar ou, pior, a arriscar bloqueio do fornecedor |
| P3 | As 200 máquinas pertencem ao mesmo evento e são lidas na mesma janela | Se forem vários eventos em paralelo, o orçamento global de chamadas precisa de ser repartido |
| P4 | O perfil de volume escala linearmente a partir do evento 6 | As estimativas de volume desta secção mudam proporcionalmente |

## 3. Linha de Base Medida

Do `RELATORIO_CORRECAO_SINCRONIZACAO_2026-08-02.md`, evento 6
(*Brunch Eletronik NoSolo*):

- 43 máquinas configuradas;
- 5.655 documentos recebidos;
- 8.847 linhas importadas;
- 5.536 documentos fiscais;
- 47 pedidos lógicos à API;
- 71,87 s e 75,26 s em duas execuções locais;
- 460 s no último fluxo concluído registado em produção.

### 3.1 Extrapolação para 200 máquinas

Aplicando o fator `200 / 43 = 4,65` ao perfil medido:

| Grandeza | 43 máquinas | 200 máquinas (estimado) |
| --- | ---: | ---: |
| Documentos por evento | 5.655 | ~26.300 |
| Linhas de venda | 8.847 | ~41.100 |
| Documentos de pagamento | ~5.650 | ~26.300 |
| **Total de registos do dataset** | **~17.000** | **~67.400** |

O ponto crítico não é este volume. É o facto de o desenho atual **reescrever o
dataset inteiro em cada ciclo**, mesmo em modo incremental. A secção seguinte
explica porquê.

## 4. Diagnóstico — Onde o Tempo é Gasto

Sete constatações, ordenadas por impacto. As três primeiras explicam a maior
parte do custo.

### 4.1 O modo incremental copia o dataset completo a cada ciclo — `CRÍTICO`

`EventReportSyncService::resolveReusableHistoricalData()` (linha ~564) faz, em
todos os ciclos, mesmo quando nada mudou:

```php
$activeImport->rows()
    ->whereDate('sale_date', '>=', ...)
    ->orderBy('id')
    ->chunkById(self::STAGING_INSERT_BATCH_SIZE, function (Collection $rows) { ... });
```

Isto lê **todas** as linhas do snapshot anterior, hidrata cada uma como modelo
Eloquent, e volta a inseri-las numa nova importação. `copyHistoricalPaymentDocuments()`
faz exatamente o mesmo aos documentos de pagamento.

Consequência a 200 máquinas: cada sincronização de 15 minutos lê ~67.400
registos e escreve ~67.400 registos, para depois apagar os ~67.400 da importação
anterior em `cleanupSupersededRows()`. **Aproximadamente 200.000 operações de
linha para incorporar algumas centenas de documentos novos.**

O custo é `O(dataset)` quando deveria ser `O(delta)`. Esta é a causa raiz.

### 4.2 A concorrência arranca um processo PHP completo por máquina — `CRÍTICO`

`runConcurrentMachineTasks()` (linha ~801) lança, por máquina, um processo
`invoke-serialized-closure` através do `ProcessFactory`. Cada processo arranca o
framework Laravel do zero: autoloader, container, service providers, configuração,
ligação à base.

A 200 máquinas, isso são **200 arranques completos do Laravel por ciclo**, com a
concorrência limitada a 10 (modo full) ou 4 (modo incremental), e travada em 20
pelo `machineSyncConcurrency()`. Em modo incremental: **50 vagas sequenciais**.

O trabalho é dominado por espera de rede, não por CPU. Pagar um boot de framework
por cada pedido HTTP é o pior rácio possível.

### 4.3 A publicação e o staging são serializados no processo pai — `ALTO`

O `foreach` principal de `fetchRows()` lê o ficheiro de cada máquina, desduplica
em memória e chama `stageRowsChunk()` / `stagePaymentDocumentsChunk()` — tudo em
sequência, no processo pai, contra um único escritor SQLite. O paralelismo
conquistado na leitura é integralmente perdido na escrita.

Pior: `retryRateLimitedMachineResult()` volta a executar **o payload completo de
uma máquina**, de forma serial e no processo pai, com pausas de 2 segundos, até
2 rondas. Uma máquina limitada pela ZoneSoft bloqueia as outras 199.

### 4.4 Existe um bloqueio global de sincronização em toda a plataforma — `ALTO`

`start()` recusa qualquer sincronização se existir **qualquer** importação com
`status = 'processing'` em toda a base — não apenas no evento em causa:

```php
if (EventReportImport::query()->where('status', 'processing')->exists()) {
    throw ValidationException::withMessages([...]);
}
```

Com um evento demora minutos; com dois clientes em eventos simultâneos, um fica
à espera do outro indefinidamente. Isto contradiz diretamente o critério de
aceite do CR-203 ("dois eventos independentes podem avançar").

### 4.5 O modo incremental é frágil e cai facilmente para full — `ALTO`

`resolveReusableHistoricalData()` regressa a `full` se **qualquer** uma destas
condições ocorrer:

- houve um único aviso ou uma única máquina falhada no snapshot anterior;
- o conjunto de máquinas mudou (adicionar 1 TPA invalida as 200);
- passaram 24 h desde a última sincronização completa;
- um único cursor está ausente ou não corresponde ao `zs_client_id`/`store_id`.

Com 200 máquinas, a probabilidade de nenhuma ter avisos num ciclo é baixa. Na
prática, o sistema tenderá a correr quase sempre em modo `full`.

### 4.6 A paginação usa `offset` sobre dados em movimento — `ALTO (correção)`

`fetchDocuments()` (linha ~1305) pagina assim:

```php
'order'  => 'data ASC, numero ASC',
'limit'  => 250,
'offset' => $offset,
// ...
} while (count($batch) === $limit);
```

O manual ZSAPI confirma o teto de 250 instâncias por listagem. Dois problemas:

1. **Offset-drift.** Durante um evento ao vivo, chegam documentos novos entre
   páginas. Com ordenação por `data ASC`, uma inserção anterior ao offset atual
   desloca a janela e **pode saltar documentos**. É um risco de conciliação, não
   apenas de performance.
2. **Sem guarda de páginas.** O ciclo `do/while` não tem limite máximo de páginas
   nem de itens, contrariando o §11 do Padrão de Implementação Segura.

### 4.7 O dashboard escreve na base durante um pedido de leitura — `MÉDIO`

`EventDashboardController::renderDashboard()` (linha ~138) chama
`markStaleProcessingImportsAsFailed()`, que executa `UPDATE` e `DELETE`. Cada
abertura de dashboard concorre pelo escritor único do SQLite com a sincronização
em curso. Com vários clientes a olhar para o mesmo evento, isto agrava os
timeouts de 30 s já registados na auditoria.

Complementarmente, `dashboardCacheVersion()` inclui o `MAX(id)` das importações
ativas **de todo o cliente**, pelo que sincronizar um evento invalida a cache de
todos os outros eventos desse cliente.

### 4.8 Possível trabalho duplicado por loja — `RESOLVIDO`

**Atualização:** já resolvido, sem ser preciso agir. A migração
`2026_08_29_170500_make_zonesoft_machines_global` (que já estava pendente
no repositório antes deste plano ser escrito) eliminou a duplicação de
máquinas por evento — confirmado ao vivo nesta sessão: as 22 máquinas do
evento Cavado formam 22 pares `(zs_client_id, store_id)` distintos, zero
duplicados. O texto original desta secção fica abaixo como registo do
diagnóstico que motivou a verificação.

`buildDocumentCondition()` filtra por `loja = {store_id}`, e o manual ZSAPI
descreve o `X-ZS-CLIENT-ID` como *"your client id associated with a store"*.

No evento Cavado documentámos **16 lojas para 43 máquinas**. Se várias máquinas
partilharem o mesmo par `(zs_client_id, store_id)`, o sistema está a pedir à
ZoneSoft **o mesmo conjunto de documentos várias vezes** e a desduplicá-lo
localmente.

Se se confirmar, desduplicar os pedidos por `(zs_client_id, store_id)` reduz os
pedidos de 200 para o número real de lojas — potencialmente um corte imediato de
60% a 70% sem qualquer mudança arquitetural. **Esta verificação deve ser o
primeiro passo do plano**, porque pode alterar a dimensão de todo o resto.

## 5. Estratégia

A mudança conceptual é uma só:

> Deixar de tratar uma sincronização como *"produzir um snapshot novo e completo"*
> e passar a tratá-la como *"avançar um registo durável por delta, e servir números
> pré-agregados"*.

Disto decorrem quatro princípios:

1. **O custo de um ciclo deve ser proporcional ao que mudou**, não ao histórico acumulado.
2. **A publicação é a troca de um ponteiro de versão**, não a cópia de linhas.
3. **A leitura do dashboard nunca toca nas linhas de venda**, apenas em agregados materializados.
4. **Cada máquina falha, repete e progride de forma independente** das outras 199.

## 6. Fases

Cada item segue a numeração `PERF-xxx` e cumpre o Padrão de Implementação Segura.
Nenhum item se considera concluído sem as evidências do §8.

---

### Fase 0 — Medir antes de mexer

#### PERF-001 — Banco de ensaio para 200 máquinas
**Prioridade:** P0 · **Estado:** Não iniciado · **Dependências:** nenhuma

Sem um ambiente de ensaio, qualquer número neste plano é uma estimativa. E não
podemos usar a API real de produção para testes de carga.

Ações:

- construir um simulador ZoneSoft (`Http::fake` com latência configurável,
  paginação de 250, respostas 429 injetáveis e taxas de erro parametrizáveis);
- gerar um fixture sintético de 200 máquinas, ~26.000 documentos e ~41.000 linhas;
- instrumentar o `machine_timings` já existente com a decomposição real:
  `boot_ms`, `api_wait_ms`, `parse_ms`, `db_write_ms`;
- registar a linha de base atual neste ambiente, sem qualquer alteração de código.

Critério de aceite:

- existe um comando reproduzível que mede um ciclo completo de 200 máquinas;
- a decomposição de tempo é registada e comparável entre execuções;
- os dados são sintéticos, sem qualquer informação real de cliente.

#### PERF-002 — Confirmar limites e topologia com a ZoneSoft
**Prioridade:** P0 · **Estado:** Não iniciado

O manual ZSAPI v3 (Jan 2024) confirma que a API devolve
`ZSAPI_TO_MANY_REQUESTS: 429` mas **nunca publica o limite**. Subir a
concorrência sem esse número é adivinhar em cima de um fornecedor.

Ações:

- pedido formal a `api@zonesoft.org`, seguindo o guia de comunicação do manual,
  a perguntar: pedidos por segundo e por minuto tolerados, se o limite é por
  `app-key`, por `client-id` ou por IP, se existe `Retry-After`, e se existe
  qualquer mecanismo de push/webhook ou endpoint agregado;
- confirmar se um `client-id` cobre uma loja com vários POS (ver §4.8);
- avaliar `salesday/getInstances` como sonda barata de "houve movimento?".

Critério de aceite:

- resposta escrita do fornecedor arquivada;
- limite documentado e transformado num orçamento configurável de chamadas;
- decisão registada sobre a desduplicação por loja.

---

### Fase 1 — Eliminar o trabalho `O(dataset)`

Maior ganho, menor risco arquitetural. Pode avançar mesmo em SQLite.

#### PERF-101 — Substituir a cópia de snapshot por um registo incremental
**Prioridade:** P0 · **Estado:** Implementado, aguarda ensaio contra cópia de produção antes do deploy · **Dependências:** PERF-001

Implementado em `app/Services/EventReportSyncService.php` (branch
`perf/101-incremental-natural-key`), com chave natural
`(event_id, machine_id, doc_type, document_series, document_number, line_key)`
em `event_report_rows` e `(event_id, dedupe_key)` em
`event_report_payment_documents`. `resolveReusableHistoricalData()` e
`copyHistoricalPaymentDocuments()` foram removidos por completo — não há
mais cópia, apenas `upsert()` do delta devolvido pela API, escrito numa
única transação curta dentro de `run()`, só depois de todas as máquinas
obrigatórias terem tido sucesso (mais forte que a garantia anterior: uma
falha a meio não escreve **nada**, em vez de escrever e depois limpar).
`is_active`/`EventReportImport` mantiveram-se inalterados como registo de
auditoria; só deixaram de ser a partição física dos dados.

**Achado durante o ensaio local (dados reais do evento Cavado):** ao
adicionar o índice único por evento, `event_report_rows` recusou o backfill
por colisão — o evento 5 tinha **3.055 linhas órfãs (38% da tabela)**
espalhadas por 7 gerações de import nunca limpas, confirmando ao vivo o
diagnóstico do §4.1: `cleanupSupersededRows()` é best-effort e engoliu as
suas próprias exceções silenciosamente durante um período. A migração
(`2026_09_02_120000_add_natural_key_to_event_report_rows_and_payment_documents.php`)
resolve isto adicionando um passo que mantém apenas as linhas do import
ativo de cada evento antes do backfill — validado localmente: contagens e
somas de `event_report_rows`/`event_report_payment_documents` por evento
ficaram bit-a-bit iguais às que já eram visíveis no dashboard antes da
migração (nenhum número muda, só o excesso físico é removido).
`event_report_payment_documents` não tinha o mesmo problema localmente
(a sua limpeza tinha corrido bem), mas a migração aplica a mesma regra às
duas tabelas por segurança.

**Por fazer antes do deploy:**
- ensaiar a migração (incluindo o passo de backfill) contra uma cópia real
  do schema de produção, não só contra os dados locais de "Cavado";
- correr a suite completa (109 testes, incluídos os novos/alterados para
  este item) e o `vendor/bin/pint --test` no ambiente de destino;
- conciliação financeira formal contra os números dourados do evento 6
  (81.076,75 EUR sem ZT, diferença 0,00 EUR) — não é possível localmente,
  esse evento já não existe na base local (só restam Cavado/Cavado 2).

Critérios de aceite do plano original — confirmados localmente:

Ações:

- introduzir identidade natural e estável para cada linha e documento de
  pagamento: `(event_id, machine_id, doc_type, document_series, document_number, line_index)`,
  com índice único;
- substituir `insert()` por `upsert()` sobre essa chave;
- desacoplar os dados da importação: `event_report_import_id` deixa de fazer
  parte da identidade e passa a ser apenas `last_seen_import_id` para auditoria;
- introduzir marcação de anulação (tombstone) em vez de apagar e reinserir
  documentos cancelados;
- **remover por completo** `resolveReusableHistoricalData()` e
  `copyHistoricalPaymentDocuments()`;
- a publicação passa a ser o incremento de um `published_version` no evento,
  dentro de uma transação curta;
- `cleanupSupersededRows()` e `cleanupSupersededPaymentDocuments()` deixam de
  existir no caminho crítico e passam a ser manutenção assíncrona.

Critério de aceite:

- um ciclo sem documentos novos escreve **zero** linhas de negócio;
- um ciclo com `N` documentos alterados escreve `O(N)` linhas;
- os totais financeiros do evento 6 conciliam a 100% com os valores dourados já
  aprovados (81.076,75 EUR faturado sem ZT, diferença de conciliação 0,00 EUR);
- uma falha a meio da sincronização mantém o `published_version` anterior intacto;
- reprocessar o mesmo intervalo duas vezes produz exatamente o mesmo resultado.

#### PERF-102 — Cursor incremental durável e por máquina
**Prioridade:** P1 · **Estado:** Não iniciado · **Dependências:** PERF-101

Ações:

- mover os cursores de `summary['machine_document_cursors']` (JSON dentro da
  importação) para colunas próprias em `client_zonesoft_machines`:
  `last_synced_at`, `last_document_cursor`, `last_full_sync_at`, `consecutive_failures`;
- eliminar as regras de invalidação global: o aviso de uma máquina deixa de
  forçar refetch completo das restantes 199;
- adicionar uma TPA ao evento passa a exigir refetch **apenas** dessa TPA;
- manter o refresh completo periódico, mas escalonado por máquina em vez de
  simultâneo para todas.

Critério de aceite:

- adicionar uma máquina a um evento com 200 TPAs gera pedidos apenas para essa máquina;
- uma máquina com avisos não altera o modo de sincronização das outras;
- o refresh completo distribui-se ao longo da janela, sem picos.

#### PERF-103 — Paginação por chave em vez de `offset`
**Prioridade:** P1 (correção) · **Estado:** Implementado

Implementado em `app/Services/EventReportSyncService.php` (branch
`perf/101-incremental-natural-key`) — afeta os dois caminhos que paginam
`documents/getInstances`: `fetchDocuments()` (serial, usado no retry serial
de máquinas rate-limited) e `fetchDocumentsAcrossMachines()` (pooled,
caminho principal do PERF-201).

- `order` passou de `data ASC, numero ASC` para `lastupdate ASC, numero
  ASC`, e o `offset` deixou de ser usado — cada página além da primeira
  volta a pedir com `lastupdate >= '<lastupdate do último item da página
  anterior>'` (`buildDocumentCondition()`). `data` é a data de negócio do
  documento (pode ser antiga/retroativa); `lastupdate` é atribuída pelo
  próprio ZoneSoft no momento da escrita, por isso um documento inserido
  a meio de uma sincronização recebe sempre um `lastupdate` posterior a
  qualquer fronteira já ultrapassada — nunca pode cair numa página já
  lida. Isto elimina o offset-drift descrito no §4.6.
- O manual da API (`IntegrationManual.pdf`, p.16) documenta `condition`
  apenas como "critério de pesquisa extra" — sem gramática de OR/parênteses
  garantida. Para não depender disso, a fronteira usa `>=` (inclusiva) em
  vez de uma comparação exclusiva com desempate, e `dedupeDocumentPage()`
  filtra do lado do cliente os documentos que a página seguinte
  inevitavelmente repete por partilharem exatamente o mesmo `lastupdate`
  que a fronteira.
- Guarda de páginas máximas e de documentos máximos por máquina
  (`event-reports.zonesoft.document_pagination_max_pages`/
  `document_pagination_max_documents`, configuráveis por env), e deteção de
  página repetida (`isStuckDocumentPage()`): uma página cheia cujo
  `lastupdate` de fronteira não avançou em relação à página anterior só
  pode significar mais de 250 documentos com o mesmo `lastupdate`
  (inatingível por paginação de chave) — lança `ZoneSoftApiException`, que
  flui pelo mesmo canal de erro por máquina já existente (não aborta a
  sincronização inteira; só essa máquina falha, com mensagem explícita).
- Achado ao testar: a primeira versão do teste de "página repetida" só
  disparava quando a página cheia devolvia zero itens novos — mas se o
  `lastupdate` vier vazio em todos os documentos (não deveria acontecer em
  produção, mas aconteceu num teste com fixtures incompletas), a fronteira
  fica presa em `null` para sempre e cada ronda volta a contar os mesmos
  documentos como "novos", crescendo sem limite até esgotar a memória.
  Corrigido simplificando a deteção para "página cheia + fronteira não
  avançou" (sem depender de contar itens novos) — a ordenação por
  `lastupdate ASC` garante que a fronteira nunca regride, por isso "não
  avançou" já implica inequivocamente que a próxima ronda repetiria
  exatamente o mesmo resultado.

Critério de aceite:

- nenhum documento é saltado quando são inseridos registos durante a
  paginação — provado por
  `test_document_pagination_does_not_skip_a_document_inserted_between_page_requests`
  em `EventReportImportTest.php`;
- o teste de página repetida falha de forma controlada e visível — provado
  por `test_document_pagination_aborts_when_more_documents_than_the_page_limit_share_one_lastupdate`;
- os limites de páginas e itens são configuráveis (`config/event-reports.php`).

---

### Fase 2 — Paralelismo real

#### PERF-201 — Trocar o fork por máquina por I/O assíncrono
**Prioridade:** P0 · **Estado:** Implementado · **Dependências:** PERF-001

Implementado em `app/Services/EventReportSyncService.php` e
`app/Services/ZoneSoft/ZoneSoftApiClient.php` (branch
`perf/101-incremental-natural-key`). `ProcessFactory`/`SerializableClosure`/
`invoke-serialized-closure` e todo o mecanismo de ficheiros temporários por
máquina foram removidos por completo. A paginação de documentos passou a
correr em `fetchDocumentsAcrossMachines()`: por ronda, agrupa um pedido
"próxima página" por cada máquina ainda ativa e despacha-os todos juntos
num único `ZoneSoftApiClient::postManyAcrossRequests()` (generalização do
`postMany()` já existente, com o mesmo `Http::pool($callback,
$concurrency)`), até nenhuma máquina ter mais páginas. A lógica de negócio
por máquina (`syncMachinePayload()`) manteve-se — só foi reorganizada para
`buildMachineResultFromDocuments()`, partilhada entre o caminho novo e o
caminho de retry serial existente, que não mudou.

**Achado ao generalizar `postMany()`:** o `fetchDocuments()` original nunca
repetia um 401 (Não Autorizado); o `postMany()` sempre repetiu (fallback
bloqueante com `retryUnauthorized=true`). Generalizar sem reparar nisso
teria feito cada máquina com credencial inválida gastar ~1,5 s extra por
ciclo antes de falhar — a 21 das 22 máquinas locais do evento Cavado (ver
achado da sessão anterior sobre sessões fechadas), isso seriam ~31 s
desperdiçados por ciclo. Corrigido tornando `retryUnauthorized`
parametrizável por pedido; `postMany()` mantém `true` (o seu comportamento
de sempre), a paginação de documentos usa `false` (o comportamento original
de `fetchDocuments()`).

**Validado contra a API real** (não só `Http::fake`): correu um `sync()`
completo do evento Cavado (22 máquinas) contra `api.zonesoft.org`. A única
máquina com sessão válida (loja 1) foi buscada e processada corretamente
via pool; as outras 21 falharam com 401 em bloco, rápido — ciclo completo
em **10,15 s**, sem nenhum arranque de processo. Confirmado que a
sincronização falhada não escreveu nada (`event_report_rows`/
`event_report_payment_documents` do evento ficaram exatamente iguais a
antes: 4.392 linhas / 3.351 documentos de pagamento).

Critério de aceite do plano original:

O trabalho é limitado por rede. Um único processo PHP consegue manter dezenas de
pedidos HTTPS em voo através do pool Guzzle que o `ZoneSoftApiClient::postMany()`
já usa.

Ações:

- eliminar `invoke-serialized-closure`, `SerializableClosure` e o
  `ProcessFactory` do caminho de sincronização;
- reescrever `fetchMachineResultDescriptors()` sobre `Http::pool()`, com
  janela de pedidos em voo configurável;
- remover a escrita e leitura de ficheiros intermédios por máquina, que só
  existiam para atravessar a fronteira entre processos;
- manter o isolamento de falhas por máquina ao nível do resultado, não do processo.

Critério de aceite:

- zero arranques de framework por máquina;
- 200 pedidos completam em menos de 5 s com latência simulada de 400 ms;
- uma máquina em erro ou timeout não afeta o resultado das restantes;
- o consumo de memória do processo mantém-se estável ao longo do ciclo.

#### PERF-202 — Um job por máquina, com escritas em lote
**Prioridade:** P1 · **Estado:** Não iniciado · **Dependências:** PERF-201, P1 (Redis)

Ações:

- `SyncEventReportJob` passa a orquestrador; cada máquina recebe o seu job
  idempotente, com `timeout`, `tries`, backoff e lock próprios;
- os resultados são escritos em lote por lotes de máquinas, não máquina a máquina;
- `retryRateLimitedMachineResult()` desaparece do processo pai: a repetição
  passa a ser responsabilidade da fila, com backoff e jitter;
- progresso e heartbeat passam a ser agregados a partir dos jobs individuais.

Critério de aceite:

- jobs pendentes sobrevivem a reboot e a deploy;
- uma máquina limitada pela API não atrasa as restantes;
- o progresso apresentado ao cliente reflete máquinas concluídas em tempo real.

#### PERF-203 — Substituir o bloqueio global por locks por evento
**Prioridade:** P1 · **Estado:** Não iniciado · **Dependências:** PERF-202

Ações:

- `GLOBAL_SYNC_START_LOCK` passa a `event-report:sync:{event_id}`;
- introduzir um **orçamento global de chamadas** partilhado (token bucket em
  Redis), dimensionado pela resposta do PERF-002, para que o limite do fornecedor
  continue respeitado com vários eventos em paralelo;
- adicionar circuit breaker por aplicação ZoneSoft;
- a concorrência passa a ser configuração, nunca alteração de código.

Critério de aceite:

- dois eventos de clientes diferentes sincronizam em simultâneo;
- o total de chamadas por segundo nunca excede o orçamento configurado,
  independentemente do número de eventos ativos;
- o circuit breaker abre e fecha de forma observável e testada.

---

### Fase 3 — Reduzir o que se pede à ZoneSoft

#### PERF-301 — Sonda de movimento antes de sincronizar
**Prioridade:** P2 · **Estado:** Não iniciado · **Dependências:** PERF-002

Num evento ao vivo, a maior parte das 200 TPAs não regista vendas em qualquer
janela de 60 segundos. Perguntar "houve movimento?" é muito mais barato do que
pedir documentos.

Ações:

- avaliar `salesday/getInstances` (totais diários por caixa) e
  `signeddocumentscount/getInstance` como sondas;
- se os totais de uma máquina não mudaram desde o último ciclo, saltar o fetch
  de documentos dessa máquina;
- garantir que a sonda nunca substitui a leitura final obrigatória no fim do evento.

Critério de aceite:

- num ciclo típico de evento ao vivo, apenas as máquinas com movimento geram
  pedidos de documentos;
- a leitura final no fim do evento continua a percorrer todas as máquinas;
- nenhum documento é perdido por efeito da sonda, validado contra um caso dourado.

#### PERF-302 — Reduzir o payload persistido
**Prioridade:** P2 · **Estado:** Não iniciado · **Dependências:** PERF-101

Alinha-se com o CR-104 (minimizar dados) e reduz diretamente a escrita.

Ações:

- inventariar os campos efetivamente usados pelo dashboard;
- deixar de persistir `raw_row` por linha, ou reduzi-lo aos campos necessários;
- confirmar a classificação de `payment_card_number` (identificador ZT ou PAN)
  e mascarar ou tokenizar conforme o resultado;
- definir retenção para dados brutos e importações antigas.

Critério de aceite:

- existe inventário de campos com finalidade documentada;
- o volume escrito por documento reduz-se de forma medida;
- nenhum identificador sensível é guardado em claro.

---

### Fase 4 — Dashboard instantâneo

#### PERF-401 — Agregados materializados
**Prioridade:** P1 · **Estado:** Implementado (fatia 1) · **Dependências:** PERF-101

Implementado em `app/Http/Controllers/EventDashboardController.php` e
`app/Services/EventReportSyncService.php` (branch
`perf/101-incremental-natural-key`). **Âmbito reduzido conscientemente**:
"materializar tudo" tal como descrito acima equivale, na prática, ao
PERF-101+PERF-201 juntos, e parte é genuinamente incompatível com
pré-agregação sem perder precisão — ver justificação abaixo. Implementada
a fatia que cobre o volume real (linhas de venda) e entrega o ganho
prometido sem tocar na parte financeiramente mais delicada.

**O que foi feito:** duas tabelas novas —
`event_report_row_aggregates` (grão dia × hora × loja × produto ×
tipo de documento, por `machine_id`) e `event_report_ticket_aggregates`
(um registo por documento distinto, só para `COUNT(*)` = `tickets_count`,
evitando o `COUNT(DISTINCT ...)` caro de hoje). Mantidas dentro da mesma
transação de publicação do PERF-101 (`EventReportSyncService::refreshRowAggregates()`),
por máquina tocada no ciclo — `O(linhas da máquina)`, nunca `O(dataset)`.
Os 8 métodos do `EventDashboardController` que liam `event_report_rows`
diretamente (`buildSummary`, `buildBarGroups`, `buildTopStores`,
`buildTopProducts`, `buildProductBreakdowns`, `buildDocumentTypes`,
`buildHourlySales`, mais as opções de filtro) passam a ler as tabelas
agregadas — exceto quando `total_min`/`total_max`/`product` estão ativos
no filtro (ver achado abaixo), caso em que caem para a query original
sobre `event_report_rows`, inalterada.

**Porque é que `total_min`/`total_max`/`product` não podem usar o caminho
rápido:** `total_min`/`total_max` filtram pelo total de **uma linha
individual**; um balde já somado não tem essa granularidade — aplicar o
filtro depois de somar dá um resultado errado, não só mais lento.
`product` precisa de `tickets_count` restrito a "bilhetes que contêm este
produto", e a tabela de bilhetes não tem dimensão de produto (um bilhete
pode ter vários produtos). A opção escolhida: estes três filtros continuam
a usar a query direta sobre `event_report_rows` (o comportamento de hoje,
sem alteração), só o caminho sem estes filtros — a esmagadora maioria do
tráfego — é que fica rápido.

**Achado ao implementar:** ao juntar contagens de bilhetes por grupo de
bar (`buildBarGroups`/`buildZoneDevices`), descobri que o mesmo
`store_code` pode ter várias variantes de `store_name` (POS 1, POS 2 —
`normalizeSaleRow()` já acrescenta ` - POS N`). Contar bilhetes só por
`store_code` e atribuir o mesmo total a cada variante de `store_name`
teria duplicado a contagem ao somar por grupo de bar. Corrigido incluindo
`store_name` no grão de `event_report_ticket_aggregates`.

**Achado mais sério, só visível a correr os testes:** o cast `date` do
Eloquent (`EventReportRow::casts()`) só trunca a hora ao *ler* o atributo
— ao gravar, continua a usar o formato completo (`sale_date` fica gravado
como `"2026-03-14 00:00:00"`, não `"2026-03-14"`). Sem normalizar os dois
lados com `DATE(...)` ao calcular o dia do agregado, um filtro `date_to`
excluía silenciosamente todas as linhas — não é um erro visível, é um
número errado sem aviso. Corrigido em
`EventReportSyncService::refreshRowAggregatesForMachine()` e no backfill
da migração.

**Validado contra dados reais** (evento Cavado local, não só os testes):
`total_sum`, `rows_count`, contagem de bilhetes, faturação por loja, por
produto (incl. o CASE de ZT→Contactless) e por tipo de documento, e a
distribuição horária — todos batem 100% entre `event_report_rows` bruto e
as tabelas agregadas.

**Fora desta fatia, registado para depois:** `buildDailyBreakdowns`,
`buildPaymentSummary`, `buildPaymentReconciliation`,
`buildComparison`/`buildComparisonSnapshot` continuam a ler
`event_report_payment_documents` tal como hoje (essa tabela não é o
gargalo diagnosticado no §4 do plano, e mexer nela é risco de
reconciliação sem ganho de performance real). `dashboardCacheVersion()`
não mudou de mecanismo — continua chaveada por metadados do import.

Critério de aceite original (ajustado ao âmbito da fatia 1):

- ✅ o dashboard deixa de escrever queries sobre `event_report_rows` num
  carregamento sem `total_min`/`total_max`/`product` — provado por um
  teste que semeia 600 linhas e verifica zero queries à tabela bruta;
- ✅ os agregados conciliam a 100% com o cálculo direto sobre as linhas,
  validado contra o evento Cavado local (o evento 6 já não existe nesta
  base local — mesma limitação já registada no PERF-101);
- não medido `< 300 ms` (p95) a 200 máquinas — precisa do banco de ensaio
  do PERF-001, que continua por fazer.

O `EventDashboardController` recalcula hoje mais de vinte agregações sobre
`event_report_rows` a cada abertura, protegido apenas por uma cache de 300 s cuja
chave é invalidada por qualquer sincronização do cliente.

Ações:

- criar tabelas de agregados atualizadas pelo mesmo pipeline delta:
  por evento × dia × hora × loja × produto × código de pagamento;
- o dashboard passa a ler exclusivamente agregados; nunca varre linhas de venda;
- manter o acesso às linhas apenas para exportação e auditoria;
- a chave de cache passa a depender do `published_version` **do evento**, não do
  `MAX(id)` das importações de todo o cliente;
- adicionar teste de regressão de desempenho ao CI.

Critério de aceite:

- a abertura do dashboard responde em `< 300 ms` (p95) com o dataset de 200 máquinas;
- o tempo de resposta não cresce com o histórico acumulado;
- os agregados conciliam a 100% com o cálculo direto sobre as linhas, para o caso dourado do evento 6.

#### PERF-402 — Retirar escritas do caminho de leitura
**Prioridade:** P1 · **Estado:** Implementado

Ações:

- remover `markStaleProcessingImportsAsFailed()` de `renderDashboard()`;
- mover a deteção de sincronizações presas para o scheduler, onde já existe;
- garantir que nenhuma rota `GET` de dashboard executa `UPDATE` ou `DELETE`.

Critério de aceite:

- um teste confirma que abrir o dashboard não produz escritas;
- abrir o dashboard durante uma sincronização ativa não gera contenção.

---

### Fase 5 — Infraestrutura e frescura

#### PERF-501 — PostgreSQL e Redis
**Prioridade:** P1 · **Estado:** Não iniciado · **Referência:** CR-201, CR-202

Este é o teto físico do sistema. O SQLite admite **um escritor de cada vez**. Com
200 máquinas, sincronizações concorrentes e clientes a abrir dashboards, nenhuma
otimização de código contorna essa restrição.

O trabalho está descrito nos CR-201 e CR-202 e não é repetido aqui. Fica apenas
registada a dependência: as Fases 2, 3 e 4 entregam uma fração do ganho enquanto
a persistência for SQLite.

#### PERF-502 — Reduzir o intervalo e empurrar as atualizações
**Prioridade:** P2 · **Estado:** Não iniciado · **Dependências:** PERF-101, PERF-201, PERF-501

Ações:

- baixar `EVENT_REPORT_SYNC_INTERVAL_MINUTES` de 15 para 1 em eventos ativos,
  **apenas** depois de o custo por ciclo passar a ser proporcional ao delta e de
  o limite do fornecedor estar confirmado;
- manter intervalo mais largo em eventos sem movimento recente;
- avaliar Laravel Reverb (WebSockets) para empurrar agregados atualizados para os
  dashboards abertos, eliminando o refresh manual;
- manter a garantia da leitura final obrigatória no fim do evento.

Critério de aceite:

- a frescura medida em produção fica `<= 60 s` durante um evento ao vivo;
- o orçamento de chamadas nunca é excedido no intervalo reduzido;
- um dashboard aberto reflete novos valores sem interação do utilizador.

## 7. Ganho Esperado por Fase

Estimativas para 200 máquinas, a validar no banco de ensaio do PERF-001.
São ordens de grandeza, não compromissos.

| Após | Escritas por ciclo | Arranques de framework | Ciclo (p95) | Dashboard (p95) |
| --- | ---: | ---: | ---: | ---: |
| Estado atual (extrapolado) | ~200.000 | 200 | 4–12 min | 0,5–30 s |
| Fase 1 | ~500 | 200 | 60–120 s | 0,5–30 s |
| Fase 1 + 2 | ~500 | 0 | 10–20 s | 0,5–30 s |
| Fase 1 + 2 + 3 | ~500 | 0 | 3–8 s | 0,5–30 s |
| Todas as fases | ~500 | 0 | `< 15 s` | `< 300 ms` |

Nota de honestidade: **a sincronização não pode ser instantânea**, porque a
ZoneSoft é uma API de polling e não expõe push. O que fica instantâneo é o
dashboard. A frescura dos dados fica limitada pelo intervalo de polling que o
fornecedor tolerar — o alvo realista é 60 segundos, e só o PERF-002 dirá se
podemos ir abaixo disso.

## 8. Evidências Obrigatórias

Por cada item `PERF-xxx`, conforme o §11 do Plano de Correção Pré-Comercial:

- medição antes e depois no banco de ensaio, com a mesma semente de dados;
- conciliação financeira contra o caso dourado do evento 6;
- teste de falha parcial: uma máquina em erro não publica snapshot incompleto;
- teste de idempotência: reprocessar o mesmo intervalo não duplica dados;
- teste de rate limit: a API não é sobrecarregada com o orçamento configurado;
- plano e resultado de rollback;
- métricas de produção após o deploy.

## 9. Riscos

| Risco | Mitigação |
| --- | --- |
| A ZoneSoft não tolera a taxa de pedidos necessária | PERF-002 antes de qualquer aumento; orçamento global configurável; circuit breaker |
| A migração de identidade das linhas (PERF-101) corrompe dados históricos | Ensaio em cópia da base real; comparação de contagens e totais; rollback preparado |
| Enquanto for SQLite, os ganhos das Fases 2 a 4 ficam limitados | Priorizar CR-201/CR-202; medir e comunicar o teto em cada fase |
| A sonda de movimento (PERF-301) perde documentos | Leitura final obrigatória mantida; validação contra caso dourado antes de ativar |
| A complexidade do `EventReportSyncService` (2.400 linhas) dificulta a mudança segura | Extrair o fetch, a normalização e a publicação para colaboradores testáveis à medida que cada fase avança |
| **[Novo, esta sessão]** Máquinas testadas fora da janela do evento (21 de 22 no evento Cavado, mais de um mês após o fim) devolvem 401 da ZoneSoft — o `zs_client_id` pode deixar de autenticar quando a "sessão" da TPA fecha | A verificar se acontece **durante** um evento ao vivo (não só depois); se sim, é um risco novo para a resiliência por máquina do PERF-102/PERF-201, não coberto ainda |

## 10. Ordem de Execução Recomendada

1. **PERF-002** — perguntar à ZoneSoft. É o item mais lento (depende de terceiros) e condiciona as Fases 2, 3 e 5. Arrancar primeiro.
2. **§4.8** — verificar a duplicação por loja. Uma tarde de trabalho, com potencial de cortar 60% dos pedidos sem tocar na arquitetura.
3. **PERF-001** — banco de ensaio. Sem ele, nada do resto é demonstrável.
4. **PERF-101** — o registo incremental. Maior ganho isolado do plano.
5. **PERF-201** — remover o fork por máquina.
6. **PERF-401 + PERF-402** — dashboard instantâneo. É o que o cliente vê.
7. Restantes itens, por prioridade.
