# App do cliente - Contacto Digital

Aplicacao Flutter para o cliente acompanhar os seus eventos com os dados ja
sincronizados e processados pelo portal Laravel. O app nao consulta a ZoneSoft
diretamente.

## Funcionalidades

- Login com a mesma conta de cliente do portal.
- Selecao entre os eventos ativos do cliente.
- Resumo com faturacao, transacoes, ticket medio, vendas por hora, produtos e
  lojas em destaque.
- Areas de produtos, pagamentos, zonas, performance e comparacao de eventos.
- Filtros por multiplas zonas, loja/device, produto, periodo e intervalo de
  horas.
- Pesquisa nas listas, atualizacao manual e atualizacao automatica do resumo.
- Labels, ordem e visibilidade controladas pela configuracao publicada pelo
  administrador no portal.
- Atalhos para relatorio web, suporte por WhatsApp e fim de sessao.

## Executar localmente

```bash
flutter pub get
flutter run --dart-define=API_BASE_URL=http://127.0.0.1:8000/api
```

No emulador Android, use `http://10.0.2.2:8000/api`. Em um dispositivo fisico,
use o IP local do computador. Sem `API_BASE_URL`, o aplicativo aponta para
`https://portal.contactodigital.pt/api`.

## Validar

```bash
flutter analyze
flutter test
flutter build apk --release
flutter build ios --release --no-codesign
```

O APK gerado localmente usa a chave de depuracao e serve para instalacao e
validacao interna. Antes de publicar na Play Store, configure uma chave de
assinatura de producao. A versao iOS tambem precisa dos certificados da conta
Apple para distribuicao.
