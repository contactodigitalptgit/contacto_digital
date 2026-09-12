# Publicacao de performance - 03/09/2026

## Publicacao e seguranca

- Portal: https://portal.contactodigital.pt
- Servidor confirmado: 185.32.190.86. O outro servidor indicado inicialmente nao foi alterado.
- Versao inicial: `7e66dcd74347d596df3f191063404b0281762d93`.
- Workflow inicial: https://github.com/contactodigitalptgit/contacto_digital/actions/runs/33726722835
- Versao final publicada: `56a5d166470441159e617e149c2173e0c56e301c`.
- Workflow final: https://github.com/contactodigitalptgit/contacto_digital/actions/runs/33728205244
- Publicacao concluida com sucesso. Os outros servicos da VPS foram preservados.
- Backup de ensaio: `/srv/backups/contacto-digital/pre-perf-20260903-bRKMys/contacto_digital_bd.sqlite`.
- Backup imediatamente anterior ao deploy: `/srv/backups/contacto-digital/20260903-071100/contacto_digital_bd.sqlite`.
- Ambos os backups foram validados antes de qualquer migracao em producao. Copia do primeiro guardada localmente para o ensaio.
- Backup adicional, anterior a correcao de sessoes/cache: `/srv/backups/contacto-digital/20260903-072837/contacto_digital_bd.sqlite`, tambem validado pelo procedimento de deploy.
- Integridade da base apos migracao: `ok`; zero violacoes de chaves estrangeiras.

## Correcoes Publicadas

1. O Laravel nao envolvia automaticamente as migracoes SQLite numa transacao. Foram adicionadas transacoes explicitas e um teste que comprova a reposicao do esquema e das linhas quando a trava de contagem dispara.
2. O ensaio detetou vendas depois da meia-noite a mudar de dia no grafico horario. Os agregados passaram a guardar separadamente o dia civil; os filtros e totais diarios continuam a usar a data operacional ZoneSoft.
3. O procedimento instalado no servidor nao fazia backup da base. Foi substituido por um procedimento que para scheduler/worker, ativa manutencao e valida o backup antes de substituir codigo ou alterar o esquema. Uma falha interrompe a publicacao sem reabrir automaticamente o sistema.
4. No teste real, a transacao longa de publicacao bloqueou as escritas de sessoes e cache, causando erros HTTP 500. Foi publicada uma base SQLite separada, `contacto_runtime.sqlite`, para sessoes/cache/locks, preservando os dados existentes e mantendo as vendas na base original. O novo procedimento inclui ambas as bases nos backups futuros.

## Validacao

- Suite local final: 120 testes aprovados, 1598 assercoes. Inclui HTTP com sessoes/cache funcionais durante um bloqueio de escrita da base de vendas.
- Build local e build de producao concluidos; formatacao dos PHP alterados, sintaxe do script e `git diff --check` aprovados.
- Ensaio sobre copia de producao: 32.273 comparacoes, zero diferencas, em tres eventos e sete combinacoes de filtros por evento.
- Comparados: resumo, pagamentos, dias, horas, zonas, devices, produtos, documentos e reconciliacao.
- Producao apos deploy: 5.772 comparacoes sem cache, zero diferencas nos tres eventos sem filtros.
- Dashboards autenticados de Porto e Lisboa reabertos e conferidos visualmente.
- `php artisan migrate --force`: migracao de chave natural concluida em 22 s; migracao de agregados em 2 s. Nenhuma RuntimeException da trava; nenhuma repeticao necessaria.
- Migracao adicional de isolamento de sessoes/cache concluida em 388 ms. Configuracoes de sessoes, cache e locks confirmadas em `sqlite_runtime`; integridade `ok`, oito sessoes copiadas. A sessao autenticada do navegador foi mantida.
- Verificacao final apos o segundo ciclo: 5.772 comparacoes; somente `last_synced_at` do Porto mudou. Nenhuma diferenca financeira, de quantidades ou dos graficos.
- Nenhum novo erro de aplicacao nos logs entre o deploy final (07:29:29 UTC) e a verificacao das 07:35:25 UTC. App, worker, scheduler e web em execucao; outros servicos da VPS preservados.

### Totais Antes e Depois da Migracao

Valores do dashboard no mesmo perimetro de faturacao, sem misturar carregamentos e outros movimentos.

| Evento | Antes | Depois | Diferenca |
| --- | ---: | ---: | ---: |
| Festival de Marisco 2026 | 107.840,70 EUR | 107.840,70 EUR | 0,00 EUR |
| Brunch Porto | 87.148,25 EUR | 87.148,25 EUR | 0,00 EUR |
| Brunch Lisboa Charlotte #6 | 125.033,75 EUR | 125.033,75 EUR | 0,00 EUR |

## Sincronizacao Real

- Importacao 684, Brunch Porto, consulta completa: 49/49 maquinas, zero falhas de maquinas ou avisos de documentos, 65 pedidos de API, 10.319 documentos consultados e 21.364 linhas finais (incluindo tipos excluidos dos indicadores).
- Concorrencia completa: 10. Duracao do job: aproximadamente 4 min 40 s. Medicao interna: 278,249 s, dos quais 124,204 s na recolha/normalizacao e aproximadamente 154 s na publicacao/agregacao.
- Depois desta sincronizacao, 5.772 comparacoes: apenas mudou o horario da ultima sincronizacao. Totais, pagamentos, quantidades, transacoes, zonas, produtos e horas ficaram iguais.
- O teste detetou os erros HTTP 500 referidos acima. Apesar de os dados financeiros terem sido publicados corretamente, a verificacao do portal nao foi considerada aprovada sem a correcao do armazenamento de sessoes/cache.
- Depois da correcao, importacao 685 iniciada pela interface autenticada: modo incremental, 49/49 maquinas, 49 pedidos de API, zero documentos novos, zero falhas ou avisos. Medicao interna de 3,377 s; worker registou 3 s.
- O dashboard atualizou automaticamente a ultima sincronizacao para 03/09/2026, 08:31 (Lisboa), mantendo os valores. Nao foram observados novos erros HTTP 500 depois da correcao.

## Limites

As comparacoes demonstram equivalencia com o estado existente antes do deploy; nao substituem uma reconciliacao independente com extratos bancarios ou com o backoffice ZoneSoft. Uma execucao isolada tambem nao demonstra ganho percentual de velocidade sob todas as cargas.

Os 3,4 s referem-se a um ciclo incremental sem documentos novos, nao a uma sincronizacao completa. O ciclo completo de 4 min 40 s ocorreu antes do isolamento das sessoes/cache; nao foi repetido depois. O comportamento HTTP sob bloqueio da base principal foi coberto por teste local com bloqueio real de escrita, e o ciclo incremental foi validado em producao.

O principal ponto ainda a otimizar no ciclo completo medido e a publicacao/agregacao (aproximadamente 154 s). Esta medicao nao justifica aumentar a concorrencia sem outro ensaio controlado. O intervalo automatico de 15 minutos foi preservado.
