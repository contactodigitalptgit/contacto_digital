# App do cliente — Contacto Digital

App Flutter para o cliente acompanhar o seu evento (resumo de vendas, top
lojas). Backend: a API nova em `../routes/api.php` do projeto Laravel
principal (ver `docs/PLANO_DE_PERFORMANCE_SINCRONIZACAO.md`).

O código Dart (`lib/`, `pubspec.yaml`) já está pronto. **As pastas de
plataforma (`android/`, `ios/`) ainda não existem** — este ambiente não
tem o Flutter/Dart SDK instalado, por isso não pude correr `flutter
create`. Segue os passos abaixo para as gerar sem apagar nada do que já
está aqui.

## 1. Gerar as pastas de plataforma

A partir desta pasta (`mobile/`), com o Flutter SDK instalado:

```bash
flutter create --platforms=android,ios --org pt.contactodigital .
```

Como já existe aqui um `pubspec.yaml`, este comando só acrescenta as
pastas `android/` e `ios/` — não toca em `lib/` nem no `pubspec.yaml`
existentes.

## 2. Instalar as dependências

```bash
flutter pub get
```

## 3. Configurar o URL da API

Por omissão o app aponta para `https://portal.contactodigital.pt/api`
(produção) — ver `ApiClient.defaultBaseUrl` em `lib/api_client.dart`.
Para testar contra o teu ambiente local, muda essa constante ou passa um
`baseUrl` diferente ao construir o `ApiClient` em `lib/main.dart`
(no emulador Android, `http://10.0.2.2:8000/api` chega ao `localhost:8000`
da máquina anfitriã; num dispositivo físico usa o IP da tua rede local).

## 4. Correr o app

```bash
flutter run
```

## Testar o login

Usa as credenciais de um utilizador `client` já existente no portal web
(mesmo email/palavra-passe do dashboard). Contas `admin` e clientes
desativados são rejeitados pela API por desenho — ver
`app/Http/Controllers/Api/AuthController.php` no projeto Laravel.

## Âmbito desta v1

Um único ecrã: resumo do evento ativo mais recente do cliente (vendas
totais, bilhetes, ticket médio, lojas) + top lojas por vendas. Sem
produtos, pagamentos, zonas, comparação ou gráfico horário — fica para
uma próxima fase depois de validar esta primeira versão.
