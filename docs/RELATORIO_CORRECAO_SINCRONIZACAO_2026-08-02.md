# Relatorio de correcao da sincronizacao ZoneSoft

Data: 2 de agosto de 2026

## Estado da entrega

As correcoes foram implementadas e validadas apenas no ambiente local. A producao nao foi alterada e nenhum deploy foi realizado.

Foi usada uma copia isolada da base de producao. O snapshot original permaneceu imutavel e a sincronizacao foi executada numa segunda copia de trabalho, ignorada pelo Git.

## Problemas confirmados

1. O fluxo anterior fazia uma chamada para obter os documentos e depois uma chamada adicional para cada documento. No evento 6 isso representava aproximadamente 4.325 pedidos logicos e aumentava muito o tempo, a exposicao a falhas de DNS e o risco de limitacao da API.
2. Quando a proxima sincronizacao de 15 minutos ultrapassava o fim do evento, o agendador encerrava sem garantir uma leitura final.
3. A publicacao inseria milhares de linhas e removia o relatorio anterior dentro da mesma transacao SQLite, aumentando a possibilidade de `database is locked`.
4. Uma tentativa falhada mantinha corretamente os dados anteriores, mas o dashboard nao deixava claro para o cliente que os valores eram da ultima sincronizacao valida.
5. Um documento fiscal de 15,00 EUR tinha apenas 13,50 EUR discriminados nos pagamentos parciais devolvidos pela ZoneSoft. Isso causava uma diferenca de 1,50 EUR na conciliacao.

## Correcoes realizadas

- A sincronizacao passou a usar `documents/getInstances`, que devolve o documento, as vendas e os pagamentos numa unica resposta.
- O caminho antigo com uma chamada por documento foi mantido apenas como alternativa configuravel e para compatibilidade dos testes.
- As linhas novas sao preparadas fora da transacao final. A troca do relatorio ativo agora e curta e atomica.
- Dados temporarios de sincronizacoes falhadas, interrompidas ou obsoletas sao removidos sem afetar o ultimo relatorio valido.
- A limpeza posterior a publicacao ignora qualquer nova importacao que ainda esteja em processamento, evitando que uma sincronizacao apague as linhas temporarias de outra.
- O agendador garante uma tentativa no horario exato de fim do evento.
- Se a tentativa final falhar, ela e repetida a cada 10 minutos durante uma margem de 60 minutos. Somente uma sincronizacao iniciada no horario de fim do evento ou depois dele encerra o ciclo; uma tentativa iniciada antes do fim e repetida para recolher os ultimos dados.
- O Docker Compose recebeu dois resolvedores DNS no aplicativo e no worker para reduzir a dependencia de um unico DNS.
- O dashboard agora mostra processamento, progresso por maquinas e documentos, e aviso seguro de falha. Client IDs e mensagens tecnicas nao sao expostos ao cliente.
- Pagamentos parciais continuam agrupados como um unico documento. Uma parcela positiva nao discriminada pela API e classificada em `Outros`, preservando o total fiscal e deixando a conciliacao auditavel.

## Teste com API e dados reais

O evento usado foi `Brunch Eletronik NoSolo`, ID 6, com 43 maquinas configuradas.

Foram feitas duas execucoes completas a partir do mesmo snapshot original:

| Medida | Execucao 1 | Execucao 2 |
| --- | ---: | ---: |
| Duracao total | 71,87 s | 75,26 s |
| Pedidos logicos a API | 47 | 47 |
| Documentos recebidos | 5.655 | 5.655 |
| Linhas importadas | 8.847 | 8.847 |
| Maquinas processadas | 43 | 43 |

O ultimo fluxo concluido registrado no snapshot de producao levou 460 segundos. O novo teste local com a API real ficou entre 72 e 75 segundos. A comparacao de tempo e indicativa porque o ambiente local e a VPS nao possuem os mesmos recursos, mas a reducao de pedidos de aproximadamente 4.325 para 47 e objetiva, cerca de 98,9%.

## Validacao financeira final

| Indicador | Valor |
| --- | ---: |
| Vendas FT | 58.893,75 EUR |
| Vendas FS | 22.183,00 EUR |
| Total faturado sem ZT | 81.076,75 EUR |
| Multibanco | 77.017,25 EUR |
| Dinheiro | 132,00 EUR |
| ZT - Card gasto | 3.926,00 EUR |
| Outros pagamentos | 1,50 EUR |
| Total dos pagamentos | 81.076,75 EUR |
| Diferenca da conciliacao | 0,00 EUR |
| Carregamentos ZT | 5.133,00 EUR |
| Quantidade de carregamentos ZT | 117 |
| Remanescente ZT | 1.207,00 EUR |
| Total com ZT | 86.209,75 EUR |
| Outros movimentos | 16,00 EUR |
| Movimento geral | 86.225,75 EUR |

Distribuicao por dia:

| Dia | Vendas | Tickets |
| --- | ---: | ---: |
| 01/08/2026 | 80.845,25 EUR | 5.509 |
| 02/08/2026 | 231,50 EUR | 27 |

## Controles de consistencia

- 0 linhas do snapshot anterior desapareceram ou mudaram.
- 0 linhas de negocio duplicadas.
- 0 vendas fora do intervalo configurado do evento.
- 0 documentos fiscais sem conciliacao.
- 5.536 documentos fiscais: 3.943 FT e 1.593 FS.
- 117 documentos ZT e 2 documentos CM, no total de 16,00 EUR.
- Integridade SQLite: `ok`.
- Hash SHA-256 do snapshot original mantido: `c00e5d076f1bdf548b47b7e81fecd2cfba8976e5aade7019b48f0a206e9ae765`.
- Somente a nova importacao ficou ativa; linhas de importacoes inativas foram eliminadas.

## Verificacoes automatizadas

- PHPUnit: 82 testes aprovados, 645 assercoes.
- Vue TypeScript e build de producao: aprovados.
- Build SSR: aprovado.
- Laravel Pint: 156 arquivos sem problemas de estilo.
- Verificacao de espacos e conflitos no diff: aprovada.

## Recomendacao para o deploy

O deploy deve ser feito numa janela controlada, com backup da base e rollback pronto. Depois da atualizacao, deve ser executada uma sincronizacao manual de um evento controlado, comparar os totais com a ZoneSoft e somente entao manter o agendador automatico ativo. O worker, o scheduler e o aplicativo deste projeto devem ser reiniciados sem tocar nos outros servicos da VPS.

Nao ha migracao de estrutura de base nesta correcao.
