# Origem dos dados do dashboard - Cavado

Data da verificacao: 27/07/2026

Os valores abaixo foram conferidos diretamente na ultima sincronizacao ativa do
evento **Cavado**:

- Importacao: `87`
- Sincronizada em: 19/07/2026, 23:01 (hora de Lisboa)
- Documentos de pagamento analisados: `3.351`
- Origem: API ZoneSoft

## Como os dados chegam

Os valores financeiros usam os cabecalhos dos documentos devolvidos por:

`documents/getDocumentsHeaders`

As lojas, produtos e linhas de venda usam os dados devolvidos por:

`sales/getInstancesFromDocument`

O sistema normaliza e guarda esses dados localmente antes de montar o dashboard.
Os valores nao sao preenchidos manualmente.

## Outros movimentos - 20,00 EUR

Este valor vem de um documento que nao e venda nem carregamento ZT:

- Tipo: `CM`
- Serie: `13R0TA261`
- Numero: `1`
- Data: 18/07/2026
- Loja: `Tpa 13 - Bar 2 Claudia - POS 1`
- Codigo de pagamento: `0`
- Valor: `20,00 EUR`

Regra atual: documentos `CM` ficam em **Outros movimentos**. Documentos `ZT`
ficam separados como carregamentos.

## Outros pagamentos - 54,00 EUR

Este valor e a soma de tres vendas `FS` com o codigo de pagamento `9`. Esse
codigo nao esta classificado como Dinheiro, Multibanco ou ZT - Card.

- `7R0TA261 / 177` - Tpa 7 - Bar 1 Ana C. - POS 1 - `10,00 EUR`
- `13R0TA261 / 180` - Tpa 13 - Bar 2 Claudia - POS 1 - `34,00 EUR`
- `13R0TA261 / 190` - Tpa 13 - Bar 2 Claudia - POS 1 - `10,00 EUR`

Total: `10,00 + 34,00 + 10,00 = 54,00 EUR`

Codigos reconhecidos atualmente:

- Dinheiro: `1`
- Multibanco: `3`, `4` e `20`
- ZT - Card: `10`, `12`, `14` e `56`
- Outros pagamentos: qualquer outro codigo, incluindo o codigo `9`

## Remanescente ZT - 939,00 EUR

O remanescente representa o valor carregado nos cartoes que ainda nao foi
usado em compras:

- 274 carregamentos `ZT`: `5.435,00 EUR`
- 536 pagamentos com ZT - Card, codigo `56`: `4.496,00 EUR`
- Calculo: `5.435,00 - 4.496,00 = 939,00 EUR`

Este valor e um saldo calculado do evento. Nao e um campo de saldo devolvido
diretamente pela API.

## Zonas - 15

O numero `15` e calculado a partir do nome das lojas presentes nas linhas de
venda. O sistema encontrou 16 lojas e agrupou as duas lojas Top Up, resultando
em 15 grupos:

- Tpa 3 - Bar 2 Vitor - POS 1
- TPA 4 - POS 1
- Tpa 7 - Bar 1 Ana C. - POS 1
- Tpa 6 - Bar 1 Rodolfo - POS 1
- TPA 11 - POS 1
- Bilheteira
- Tpa 13 - Bar 2 Claudia - POS 1
- TPA 9 - POS 1
- Tpa 16 - Bar 1 Bernardo - POS 1
- Tpa 17 - Bar Andre F - POS 1
- Top Up: Top Up 1 e Top Up 2
- Tpa 15 - Bar Vip Simao - POS 1
- Bengaleiro
- Tpa 8 - Bar Vip Alison - POS 1
- Tpa 12 - Bar 2 Vitor - POS 1

### Observacao importante

O cartao chamado **Zonas** nao usa atualmente um campo especifico de zona da
ZoneSoft. Ele cria grupos operacionais a partir do nome da loja. Por isso, na
maior parte dos casos do Cavado, cada TPA aparece como uma zona separada.

Se o objetivo comercial for mostrar zonas reais, sera necessario definir a
relacao entre cada TPA e a sua zona, ou identificar um campo equivalente
fornecido pela ZoneSoft.
