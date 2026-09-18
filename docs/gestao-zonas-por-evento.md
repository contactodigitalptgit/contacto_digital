# Gestao de zonas por evento

## Objetivo

Permitir que administradores organizem os TPAs de cada evento em zonas internas,
sem depender da escrita de zonas na ZoneSoft. Uma mudanca de TPA preserva o
historico e reparte a faturacao pela data e hora real das vendas.

## Regras de negocio

- Apenas administradores podem gerir zonas.
- A configuracao inicial agrupa os TPAs pelos nomes usados anteriormente no
  dashboard (`Bar 1`, `Bar Vip`, `Top Up`, entre outros).
- Depois da inicializacao, sincronizacoes de lojas nao substituem atribuicoes
  manuais existentes.
- Uma mudanca fecha a atribuicao anterior e cria um novo intervalo com
  `starts_at` inclusivo e `ends_at` exclusivo.
- A zona de uma venda e resolvida pelo `machine_id` e `sale_datetime`; o nome
  da loja nao e uma identidade.
- Zonas com atribuicoes atuais nao podem ser arquivadas. O arquivo nunca apaga
  atribuicoes ou vendas historicas.
- Nomes de zona sao unicos dentro do mesmo evento, sem diferenca entre
  maiusculas e minusculas na validacao da aplicacao.

## Dados

- `event_zones`: catalogo de zonas do evento e estado de arquivo.
- `event_zone_assignments`: linha temporal de cada TPA, incluindo autor e
  origem da atribuicao.
- `event_zone_id` nas vendas, pagamentos e agregados: atribuicao materializada
  para consultas rapidas e divisao exata mesmo quando a mudanca ocorre no meio
  de uma hora.

Quando uma atribuicao e alterada retroativamente, as vendas e pagamentos do TPA
sao reclassificados e os agregados desse TPA sao reconstruidos. A sincronizacao
e a mudanca de zona bloqueiam o mesmo evento durante a publicacao para evitar
resultados concorrentes inconsistentes.

## Compatibilidade movel

O aplicativo continua a consultar os endpoints existentes, incluindo
`GET /api/events/{event}/zones`. O formato de `summary` e `items` e preservado;
somente a origem do calculo muda do nome da loja para a atribuicao historica.

## Publicacao e rollback

1. Fazer backup do banco e aplicar a migration antes do codigo de aplicacao.
2. Validar num evento de teste a geracao inicial, uma mudanca de TPA e os totais
   antes/depois do horario escolhido.
3. Monitorizar duracao da reclassificacao e da reconstrucao de agregados.
4. Para rollback funcional, deixar de criar atribuicoes e manter as colunas
   nullable; o dashboard conserva o fallback pelo nome quando nao existem zonas
   configuradas.
5. O `down` da migration remove as tabelas e colunas novas, mas so deve ser
   executado depois de confirmar que o historico ja nao e necessario.
