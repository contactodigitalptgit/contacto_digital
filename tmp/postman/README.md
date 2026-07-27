# Coleção Postman ZoneSoft

Os ambientes versionados neste diretório são apenas exemplos e devem manter
placeholders como `YOUR_APP_KEY`, `YOUR_CLIENT_ID` e `YOUR_APP_SECRET`.

Para utilização local:

1. copie o ficheiro `.postman_environment.example` pretendido para um ficheiro com
   a extensão `.postman_environment`;
2. preencha as credenciais apenas na cópia local;
3. confirme que o ficheiro local está ignorado pelo Git antes de inserir segredos;
4. nunca partilhe exports do Postman sem remover credenciais e dados reais.

Ficheiros `.postman_environment` são ignorados pelo repositório. Se uma credencial
for commitada, ela deve ser rotacionada e o histórico deve ser saneado; apagar o
ficheiro num commit posterior não elimina a exposição.
