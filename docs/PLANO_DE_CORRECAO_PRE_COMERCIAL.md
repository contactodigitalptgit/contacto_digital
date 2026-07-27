# Plano de Correção Pré-Comercial

**Projeto:** Contacto Digital
**Estado do documento:** Em execução
**Data da auditoria de referência:** 27 de julho de 2026
**Responsável pelo aceite:** proprietário do produto

## 1. Objetivo

Este documento organiza as correções necessárias para que o Contacto Digital possa
receber clientes comerciais com níveis adequados de segurança, consistência,
desempenho, disponibilidade e capacidade de recuperação.

Nenhum item é considerado concluído apenas porque o código foi alterado. Cada
correção precisa cumprir o seu critério de aceite e guardar evidências verificáveis.

## 2. Decisão de Liberação

O produto não deve receber novos clientes comerciais enquanto existir qualquer item
`P0` aberto.

A liberação comercial exige:

- todos os itens `P0` concluídos;
- itens `P1` de segurança, dados financeiros, backup e disponibilidade concluídos;
- teste de restauração aprovado;
- reconciliação controlada com dados reais da ZPOS aprovada;
- deploy reproduzível e rollback testado;
- monitorização e alertas mínimos ativos;
- documentação jurídica e de privacidade aprovada.

## 3. Classificação

- `P0 - Bloqueador`: risco imediato de exposição, perda de dados ou comprometimento.
- `P1 - Alto`: risco relevante para clientes, faturação, disponibilidade ou escala.
- `P2 - Médio`: requisito de maturidade comercial ou melhoria importante.
- `P3 - Baixo`: qualidade, manutenção ou experiência sem risco imediato.

Estados permitidos:

- `Não iniciado`
- `Em execução`
- `Bloqueado`
- `Em validação`
- `Concluído`

## 4. Linha de Base da Auditoria

Na auditoria de 27 de julho de 2026:

- o repositório GitHub estava público e continha duas bases SQLite versionadas;
- as bases continham utilizadores, hashes de palavras-passe, clientes, vendas e
  identificadores da integração ZoneSoft;
- `composer audit` encontrou 34 avisos em 12 pacotes;
- `npm audit` encontrou 19 vulnerabilidades, incluindo 2 críticas e 11 altas;
- não existia backup automático externo com teste de restauração;
- o servidor aceitava acesso SSH de `root` por palavra-passe;
- firewall e Fail2ban não estavam ativos;
- os containers não tinham `restart`, `healthcheck` ou limites de recursos;
- a base de produção era SQLite e concentrava aplicação, filas, cache, locks e sessões;
- as sincronizações concluídas observadas tiveram média de 7,7 minutos e máximo de
  26,8 minutos;
- o dashboard já tinha apresentado timeouts de 30 segundos;
- 66 testes, com 465 asserções, estavam aprovados;
- o build Vue, TypeScript, Vite, SSR e PWA estava aprovado;
- o formatador Laravel Pint apontava 3 problemas de estilo.

Essa linha de base deve ser atualizada após cada fase.

## 5. Regras de Execução

- Não executar alterações destrutivas sem backup validado.
- Não guardar segredos, dumps, dados pessoais ou dados reais de clientes no Git.
- Não corrigir diretamente em produção sem registrar a alteração no repositório.
- Não misturar correções críticas com mudanças visuais ou funcionalidades novas.
- Cada fase deve ter plano de rollback antes do deploy.
- Toda mudança deve seguir o
  [Padrão de Implementação Segura](./PADRAO_DE_IMPLEMENTACAO_SEGURA.md).
- Credenciais nunca devem aparecer em documentação, commits, logs ou evidências.

## 6. Fase 0 - Contenção Imediata

### CR-001 - Proteger e sanear o repositório

**Prioridade:** P0
**Estado:** Em execução

Progresso em 27 de julho de 2026:

- [x] repositório alterado de público para privado;
- [x] acesso anónimo à API do repositório validado com resposta `404`;
- [x] backup administrativo criado e validado com `git fsck`;
- [x] bundle protegido criado em
  `/Users/dcv/Backups/contacto-digital/2026-07-27-cr001/`;
- [x] checksum SHA-256 do bundle:
  `325dde945c1dca718f500de3fd73574da48f2aafe28c5040a406d54e9252a187`;
- [x] bases e dumps adicionados ao `.gitignore`;
- [x] ambientes Postman convertidos em exemplos com placeholders;
- [x] limpeza do histórico ensaiada num clone isolado;
- [x] ensaio validado com zero caminhos de base, `git fsck` aprovado e zero
  achados no Gitleaks;
- [ ] publicar o histórico saneado;
- [ ] validar um clone novo do histórico remoto;
- [ ] ativar proteção de branch e análise contínua de segredos.

Ações:

- tornar o repositório privado imediatamente;
- criar backup administrativo do repositório antes da limpeza;
- remover `contacto_digital_bd`, `contacto_digital_db` e outros dumps do índice Git;
- adicionar padrões de bancos e dumps ao `.gitignore`;
- reescrever o histórico para eliminar os blobs das bases;
- invalidar clones, artefactos ou caches públicos que ainda contenham os ficheiros;
- ativar proteção de branch e análise automática de segredos;
- executar uma varredura de segredos em todo o histórico limpo.

Critério de aceite:

- o repositório está privado;
- os dumps não aparecem na branch atual nem em qualquer commit acessível;
- a varredura de segredos termina sem achados ativos;
- um clone novo não contém bases, dumps ou dados reais;
- a evidência inclui os hashes antes/depois e o relatório da varredura.

### CR-002 - Redefinir acessos potencialmente expostos

**Prioridade:** P0
**Estado:** Não iniciado
**Dependência:** CR-001

Ações:

- trocar a palavra-passe de `root` que foi partilhada;
- redefinir as palavras-passe dos utilizadores presentes nas bases versionadas;
- avaliar com a ZoneSoft a rotação de app key, client IDs, licenças e segredos;
- revogar sessões existentes após a troca de credenciais;
- documentar quem tem acesso administrativo ao servidor e ao GitHub;
- migrar acessos humanos para contas individuais, sem credenciais partilhadas.

Critério de aceite:

- as credenciais antigas deixam de autenticar;
- sessões antigas estão revogadas;
- os acessos administrativos têm proprietário identificado;
- nenhuma credencial nova está presente no Git, logs ou documentação.

### CR-003 - Implementar backup e recuperação

**Prioridade:** P0
**Estado:** Não iniciado

Ações:

- definir RPO e RTO comerciais;
- criar backup automático e consistente da base de dados;
- guardar cópia cifrada fora do VPS;
- aplicar retenção diária, semanal e mensal;
- incluir ficheiros necessários à restauração, configurações e chaves protegidas;
- monitorizar falhas e idade do último backup;
- executar uma restauração completa num ambiente isolado;
- documentar o procedimento de desastre.

Critério de aceite:

- backups são executados automaticamente;
- o alerta dispara quando um backup falha ou fica atrasado;
- uma restauração completa é concluída e validada;
- o tempo de recuperação cumpre o RTO;
- os dados recuperados cumprem o RPO;
- o relatório de restauração está anexado à evidência da correção.

### CR-004 - Corrigir dependências vulneráveis

**Prioridade:** P0
**Estado:** Não iniciado

Ações:

- atualizar Laravel para uma versão 12 corrigida e suportada;
- remover PhpSpreadsheet se continuar sem utilização;
- caso seja necessário mantê-lo, atualizar para versão corrigida e adicionar testes;
- atualizar Guzzle, PSR-7, Symfony e restantes dependências afetadas;
- atualizar Axios, Vite, PostCSS, Concurrently e dependências transitivas;
- executar testes, build e auditorias depois de cada grupo de atualizações;
- ativar atualização automática supervisionada de dependências.

Critério de aceite:

- `composer audit --locked` não apresenta vulnerabilidades conhecidas;
- `npm audit` não apresenta vulnerabilidades críticas ou altas;
- exceções de severidade menor estão justificadas, aprovadas e têm prazo;
- testes e build são aprovados com os novos locks.

### CR-005 - Endurecer o acesso ao servidor

**Prioridade:** P0
**Estado:** Não iniciado

Ações:

- criar utilizador administrativo nominal com `sudo`;
- configurar autenticação por chave SSH;
- desativar login SSH direto de `root`;
- desativar autenticação SSH por palavra-passe;
- ativar firewall com política de negação por padrão;
- permitir apenas portas e origens necessárias;
- remover a exposição pública de bancos e serviços internos;
- instalar e configurar Fail2ban;
- atualizar o sistema e reiniciar de forma controlada;
- guardar e testar um procedimento de acesso de emergência.

Critério de aceite:

- `root` e palavra-passe não autenticam por SSH;
- o utilizador nominal autentica com chave e `sudo`;
- apenas portas aprovadas estão expostas;
- Fail2ban e firewall estão ativos após reboot;
- o acesso de emergência foi testado sem reduzir a segurança normal.

## 7. Fase 1 - Segurança da Aplicação

### CR-101 - Bloquear SSRF na integração ZoneSoft

**Prioridade:** P1
**Estado:** Não iniciado

Ações:

- aceitar apenas HTTPS;
- utilizar uma allowlist exata de hosts oficiais da ZoneSoft;
- impedir IPs privados, loopback, link-local e redes reservadas;
- resolver e validar DNS antes da chamada;
- desativar redirects ou validar cada destino novamente;
- não enviar credenciais antes de validar o destino;
- adicionar testes de bypass por IPv4, IPv6, DNS e redirects.

Critério de aceite:

- URLs fora da allowlist são rejeitadas;
- nenhum teste consegue atingir redes internas ou hosts alternativos;
- credenciais são enviadas apenas aos hosts aprovados.

### CR-102 - Implementar cabeçalhos e política de navegador

**Prioridade:** P1
**Estado:** Não iniciado

Ações:

- adicionar HSTS após validar todos os subdomínios necessários;
- criar CSP com nonce ou hash para scripts inline;
- impedir framing com `frame-ancestors`;
- adicionar `X-Content-Type-Options: nosniff`;
- definir `Referrer-Policy` e `Permissions-Policy`;
- ocultar versões do Nginx e PHP;
- restringir proxies confiáveis aos endereços reais da infraestrutura;
- validar cookies `Secure`, `HttpOnly` e `SameSite`.

Critério de aceite:

- os cabeçalhos estão presentes nas páginas públicas e autenticadas;
- login, PWA e dashboard funcionam sem violações CSP inesperadas;
- testes automatizados validam os cabeçalhos críticos.

### CR-103 - Reforçar identidade e ações administrativas

**Prioridade:** P1
**Estado:** Não iniciado

Ações:

- implementar 2FA obrigatório para administradores;
- exigir palavras-passe fortes e verificação contra palavras-passe comprometidas;
- exigir reautenticação para credenciais, exclusões e mudanças sensíveis;
- configurar e testar e-mail transacional para recuperação de palavra-passe;
- aplicar rate limit ao pedido de recuperação;
- criar trilha de auditoria imutável para ações administrativas;
- alertar alterações de credenciais, acessos suspeitos e falhas repetidas;
- definir política de duração e revogação de sessões.

Critério de aceite:

- nenhum administrador acede sem 2FA;
- ações sensíveis guardam ator, data, alvo e resultado;
- recuperação de palavra-passe é entregue sem expor tokens em logs;
- sessões podem ser revogadas individual e globalmente.

### CR-104 - Minimizar e proteger dados

**Prioridade:** P1
**Estado:** Não iniciado

Ações:

- inventariar os campos recebidos da ZoneSoft;
- guardar apenas os campos necessários ao produto;
- remover payloads integrais quando não forem necessários;
- classificar números de cartões e confirmar se são identificadores ZT ou PAN;
- mascarar, cifrar ou tokenizar identificadores sensíveis;
- definir retenção para dados brutos, importações e logs;
- criar processos de exportação, correção e eliminação de dados;
- restringir permissões dos ficheiros `.env`, base, logs e backups.

Critério de aceite:

- existe inventário de dados e finalidade por campo;
- payloads e logs não contêm dados desnecessários;
- permissões de ficheiros seguem o princípio do menor privilégio;
- retenção e eliminação estão implementadas e testadas.

### CR-105 - Tornar cálculos financeiros exatos

**Prioridade:** P1
**Estado:** Não iniciado

Ações:

- substituir acumulações monetárias em `float`;
- representar dinheiro em cêntimos inteiros ou decimal exato;
- documentar regras de FS, FT, NC, CM, ZT e devoluções;
- separar claramente total fiscal, total ZT e total combinado;
- criar casos dourados com resultados fornecidos pelo ZPOS;
- testar arredondamento, descontos, notas de crédito e pagamentos parciais.

Critério de aceite:

- não existem cálculos monetários de negócio em ponto flutuante;
- os casos dourados conciliam a 100% com o ZPOS;
- diferenças são explicadas por tipo documental e nunca ocultadas.

## 8. Fase 2 - Confiabilidade e Desempenho

### CR-201 - Migrar persistência para PostgreSQL e Redis

**Prioridade:** P1
**Estado:** Não iniciado
**Dependência:** CR-003

Ações:

- preparar PostgreSQL isolado, com TLS e utilizador de menor privilégio;
- migrar dados primeiro num clone da base real;
- validar contagens, chaves, totais e integridade;
- mover cache, locks, sessões e filas para Redis;
- criar índices com base nas consultas medidas;
- ensaiar migração, rollback e janela de indisponibilidade;
- executar manutenção segura da base SQLite apenas enquanto ela existir.

Critério de aceite:

- migração ensaiada pelo menos duas vezes;
- contagens e totais antes/depois são idênticos;
- aplicação rejeita acessos administrativos e conexões inseguras indevidas;
- rollback foi executado com sucesso no ensaio;
- produção não depende de SQLite para operações concorrentes.

### CR-202 - Implementar fila durável

**Prioridade:** P1
**Estado:** Não iniciado
**Dependência:** CR-201

Ações:

- utilizar Redis ou backend de fila durável;
- executar workers com Supervisor ou Horizon;
- definir timeout, tentativas, backoff e limite de memória;
- tornar jobs idempotentes;
- impedir jobs duplicados por evento;
- implementar cancelamento e encerramento seguro;
- alertar jobs falhados, presos ou atrasados;
- validar comportamento durante deploy, reboot e queda do worker.

Critério de aceite:

- jobs pendentes sobrevivem a reboot e deploy;
- não há publicação duplicada de snapshots;
- falhas transitórias são retomadas sem intervenção;
- falhas permanentes geram alerta e diagnóstico rastreável.

### CR-203 - Redesenhar a sincronização para escala segura

**Prioridade:** P1
**Estado:** Não iniciado
**Dependência:** CR-202

Ações:

- substituir o bloqueio global por locks por evento, quando seguro;
- manter um orçamento global de chamadas compatível com a ZoneSoft;
- aplicar rate limit, backpressure e circuit breaker;
- utilizar sincronização incremental por cursor ou janela;
- limitar páginas, documentos, tempo e memória por execução;
- detectar páginas repetidas e respostas inconsistentes;
- atualizar heartbeat dentro dos ciclos longos;
- definir concorrência por configuração, não por alteração de código;
- preservar sempre o último snapshot completo e consistente;
- criar métricas de duração, chamadas, throttling, erros e atraso.

Critério de aceite:

- dois eventos independentes podem avançar sem exceder o limite do fornecedor;
- nenhum job pode chamar a API indefinidamente;
- uma máquina com falha não publica um snapshot incompleto;
- a sincronização de 47 máquinas cumpre o SLA definido;
- testes de rate limit confirmam que a API não é sobrecarregada.

### CR-204 - Otimizar o dashboard

**Prioridade:** P1
**Estado:** Não iniciado

Ações:

- estabelecer orçamento de consultas, memória e tempo de resposta;
- normalizar documentos de pagamento hoje guardados em JSON;
- criar agregações e caches por snapshot e filtro;
- usar propriedades Inertia adiadas ou pedidos por secção;
- eliminar iterações repetidas sobre os mesmos conjuntos;
- medir eventos pequenos, médios e grandes;
- adicionar teste de regressão de desempenho.

Critério de aceite:

- dashboard inicial responde dentro do SLA definido;
- consultas não crescem proporcionalmente a dados históricos inativos;
- filtros críticos têm índices e planos de execução revistos;
- métricas de produção confirmam o resultado após o deploy.

## 9. Fase 3 - Operação e Produto Comercial

### CR-301 - Criar CI/CD e releases reproduzíveis

**Prioridade:** P1
**Estado:** Não iniciado

Ações:

- criar branch protegida e revisão obrigatória;
- executar testes, Pint, build e auditorias no CI;
- bloquear merges com segredos, dumps ou vulnerabilidades proibidas;
- versionar Dockerfile, Compose e configuração de infraestrutura;
- fixar versões e, quando viável, digests das imagens;
- construir artefacto imutável por release;
- executar migrations de forma controlada;
- registar versão, data, responsável e rollback;
- impedir alterações manuais permanentes apenas em produção.

Critério de aceite:

- produção corresponde a um commit e artefacto identificáveis;
- um deploy limpo pode ser repetido noutro ambiente;
- rollback da aplicação e da base foi testado;
- nenhum deploy depende de ficheiros existentes apenas no servidor.

### CR-302 - Endurecer containers e infraestrutura

**Prioridade:** P1
**Estado:** Não iniciado

Ações:

- configurar `restart` e `healthcheck`;
- definir limites e reservas de CPU/memória;
- executar processos como utilizador não-root;
- tornar código e filesystem somente leitura quando possível;
- separar volumes graváveis de storage e dados;
- remover capacidades Linux desnecessárias;
- isolar o Contacto Digital de serviços sem relação;
- planear capacidade, swap e crescimento;
- testar reboot completo do host.

Critério de aceite:

- aplicação volta automaticamente após reboot;
- falhas de saúde impedem tráfego e geram alerta;
- containers não alteram o código da release;
- consumo excessivo de outro serviço não derruba o produto.

### CR-303 - Implementar observabilidade

**Prioridade:** P1
**Estado:** Não iniciado

Ações:

- centralizar logs estruturados com correlation ID;
- monitorizar disponibilidade, latência, erros e recursos;
- monitorizar fila, sincronização, rate limit e idade do último snapshot;
- monitorizar backups, certificados, disco, memória e base;
- remover dados pessoais, tokens e segredos dos logs;
- definir alertas acionáveis e responsáveis;
- criar runbooks para os incidentes principais.

Critério de aceite:

- incidentes simulados geram alerta para a pessoa responsável;
- cada sincronização pode ser rastreada de ponta a ponta;
- logs têm retenção definida e não expõem informação sensível;
- dashboard operacional mostra saúde e frescura dos dados.

### CR-304 - Preparar requisitos jurídicos e de suporte

**Prioridade:** P1
**Estado:** Não iniciado

Ações:

- definir licença proprietária ou estratégia comercial pretendida;
- revisar licenças de todas as dependências;
- criar termos de serviço e política de privacidade;
- documentar base legal, retenção, subprocessadores e transferências;
- criar DPA quando aplicável;
- definir SLA, canais e horários de suporte;
- criar política de vulnerabilidades e resposta a incidentes;
- definir processo de comunicação de violação de dados.

Critério de aceite:

- documentação aprovada por aconselhamento jurídico adequado;
- clientes recebem termos, privacidade e SLA antes da contratação;
- existe responsável e procedimento para incidentes de segurança.

### CR-305 - Evoluir o modelo de clientes e permissões

**Prioridade:** P2
**Estado:** Não iniciado

Ações:

- substituir a relação de um utilizador por cliente por memberships;
- definir papéis como proprietário, gestor, operador e leitura;
- aplicar autorização por capacidade;
- garantir isolamento em todas as queries e jobs;
- adicionar convites, remoção e transferência de propriedade;
- testar horizontal privilege escalation entre clientes.

Critério de aceite:

- cada cliente pode ter vários utilizadores;
- permissões são verificadas no backend;
- nenhum utilizador acede a recursos de outro cliente;
- testes cobrem todas as rotas e jobs multi-tenant.

### CR-306 - Corrigir documentação e PWA

**Prioridade:** P2
**Estado:** Não iniciado

Ações:

- substituir o README padrão do Laravel;
- criar `.env.example` sem segredos;
- documentar instalação, arquitetura, operação e troubleshooting;
- corrigir ou remover o fallback PWA para `index.html`;
- definir claramente quais funções offline são suportadas;
- documentar compatibilidade de browsers e dispositivos.

Critério de aceite:

- um novo ambiente pode ser criado apenas com a documentação;
- PWA não apresenta erros de navegação ou cache desatualizado;
- nenhuma documentação contém dados reais ou credenciais.

## 10. Gates Obrigatórios

### Gate A - Contenção

- CR-001 a CR-005 concluídos.
- Nenhum segredo ou dump encontrado no Git.
- Backup e restauração aprovados.

### Gate B - Segurança

- CR-101 a CR-105 concluídos.
- Testes de autorização, SSRF e consistência financeira aprovados.
- 2FA e auditoria ativos.

### Gate C - Confiabilidade

- CR-201 a CR-204 concluídos.
- Testes de carga, reboot, fila e rollback aprovados.
- SLA técnico medido, não estimado.

### Gate D - Comercial

- CR-301 a CR-304 concluídos.
- Termos, privacidade, suporte e resposta a incidentes definidos.
- Aprovação formal para iniciar onboarding de clientes.

## 11. Evidência Obrigatória por Correção

Cada item concluído deve guardar:

- link do issue ou tarefa;
- commit e pull request;
- descrição do risco anterior;
- testes automatizados executados;
- teste manual ou operacional executado;
- evidência sem dados sensíveis;
- impacto em dados e migrations;
- plano e resultado de rollback;
- data do deploy;
- responsável pela validação;
- métricas antes e depois, quando aplicável.

## 12. Registo de Progresso

Atualizar esta secção após cada ciclo:

```text
ID:
Estado:
Responsável:
Data de início:
Data de conclusão:
Pull request:
Release:
Evidências:
Riscos residuais:
Próxima revisão:
```

## 13. Riscos Residuais Antes da Primeira Venda

Mesmo após as correções, a primeira venda deve começar com um número controlado de
clientes. O período inicial precisa de monitorização reforçada, limites conservadores
de sincronização e revisão diária de erros, tempos, reconciliação e capacidade.

Um aumento de concorrência na API ZoneSoft só pode ocorrer depois de confirmação
documentada dos limites do fornecedor e de testes que demonstrem ausência de punição
ou degradação.
