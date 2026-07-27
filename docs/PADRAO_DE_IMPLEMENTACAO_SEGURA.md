# Padrão de Implementação Segura

**Projeto:** Contacto Digital
**Aplicação:** obrigatória para código, infraestrutura, dados e documentação
**Versão:** 1.0
**Data:** 27 de julho de 2026

## 1. Objetivo

Este documento define como novas funcionalidades e correções devem ser planeadas,
implementadas, revistas, testadas e publicadas. O objetivo é evitar que velocidade
de entrega comprometa segurança, isolamento entre clientes, consistência financeira,
disponibilidade ou capacidade de recuperação.

As regras aplicam-se a:

- backend Laravel;
- frontend Vue/Inertia;
- integrações ZoneSoft/ZPOS;
- sincronizações e jobs;
- banco de dados, cache, filas e migrations;
- containers, proxy, servidor e deploy;
- documentação, suporte e operação.

## 2. Princípios Obrigatórios

### 2.1 Segurança por padrão

- o comportamento padrão deve negar acesso;
- permissões devem ser concedidas explicitamente;
- segurança não pode depender apenas da interface;
- validação e autorização acontecem no backend;
- funcionalidades inseguras não são ativadas por conveniência.

### 2.2 Menor privilégio

- utilizadores, processos e serviços recebem apenas os acessos necessários;
- contas humanas são individuais;
- aplicações não usam contas administrativas para operações normais;
- credenciais têm escopo, validade e rotação definidos.

### 2.3 Isolamento entre clientes

- toda informação comercial pertence a um tenant identificado;
- acesso deve ser filtrado pelo cliente autenticado;
- IDs enviados pelo browser nunca são prova de autorização;
- jobs e comandos também validam o tenant;
- administradores usam ações auditadas e explícitas.

### 2.4 Consistência antes da disponibilidade parcial

- snapshots incompletos não podem substituir dados válidos;
- falhas parciais devem preservar a última versão consistente;
- publicação e ativação de dados devem ser atómicas;
- reprocessamentos precisam ser idempotentes.

### 2.5 Evidência antes de deploy

- build aprovado não substitui teste funcional;
- teste unitário não substitui reconciliação de dados;
- alteração em produção precisa de evidência, versão e rollback;
- alegações de desempenho precisam de métricas antes e depois.

## 3. Fluxo Obrigatório de Mudança

Toda mudança segue estas etapas:

1. definir objetivo, utilizadores afetados e resultado esperado;
2. classificar risco e impacto;
3. documentar regras de negócio e dados;
4. definir ameaças, falhas possíveis e rollback;
5. implementar numa branch;
6. executar revisão técnica e de segurança;
7. executar testes e validações aplicáveis;
8. publicar primeiro num ambiente de validação;
9. fazer deploy controlado;
10. verificar produção e monitorizar;
11. fechar a tarefa apenas com evidências.

Mudanças críticas não podem saltar etapas. Hotfixes seguem o processo reduzido
descrito neste documento e precisam de revisão posterior.

## 4. Classificação de Risco

Uma mudança é de alto risco quando envolve pelo menos um destes itens:

- autenticação, autorização, sessões ou palavras-passe;
- acesso administrativo;
- dados pessoais, financeiros ou credenciais;
- cálculos de faturação;
- integrações externas;
- migrations ou alteração de dados existentes;
- filas, concorrência, locks ou sincronização;
- upload, parsing ou desserialização;
- URLs controladas por utilizadores;
- comandos de sistema;
- rede, proxy, containers ou servidor;
- exclusão, exportação ou retenção;
- alterações sem rollback simples.

Mudanças de alto risco exigem:

- plano escrito;
- pelo menos uma revisão adicional;
- testes negativos e de abuso;
- plano de rollback;
- validação em ambiente separado;
- janela e monitorização de deploy.

## 5. Planeamento Seguro

Antes de programar, a tarefa deve responder:

- qual problema será resolvido?
- quem pode executar a ação?
- a que cliente pertencem os dados?
- quais dados entram, saem, são guardados e são apagados?
- existe informação pessoal, financeira ou secreta?
- o que acontece se a API externa falhar?
- o que acontece se o job for executado duas vezes?
- o que acontece se o deploy parar no meio?
- como o comportamento será testado?
- como reverter sem perder dados?
- quais métricas confirmarão o resultado?

Se uma resposta relevante estiver indefinida, a implementação não deve começar.

## 6. Segredos e Dados Reais

É proibido versionar:

- `.env` reais;
- bases de dados e dumps;
- app keys, tokens, passwords, certificados ou chaves privadas;
- IDs/licenças de máquinas quando forem credenciais;
- dados pessoais ou vendas de clientes;
- logs de produção não sanitizados;
- ficheiros de backup.

Regras:

- usar gestor de segredos ou variáveis protegidas;
- exemplos devem usar placeholders inequívocos;
- logs devem aplicar redação de tokens e dados sensíveis;
- fixtures devem ser sintéticas;
- screenshots devem ser revistas antes de partilha;
- segredos expostos são rotacionados, não apenas apagados;
- o CI deve bloquear segredos e ficheiros proibidos.

## 7. Autenticação e Sessões

- utilizar os mecanismos oficiais do Laravel;
- regenerar sessão após login e mudança de privilégio;
- invalidar sessão no logout e após redefinição relevante;
- aplicar rate limit por conta e origem;
- exigir 2FA para administradores;
- não revelar se um e-mail existe em fluxos públicos;
- tokens de recuperação não podem aparecer em logs;
- cookies devem usar `Secure`, `HttpOnly` e `SameSite`;
- operações sensíveis exigem palavra-passe recente ou reautenticação;
- palavras-passe nunca são enviadas ou armazenadas em texto simples.

## 8. Autorização e Multi-Tenancy

Cada endpoint, action, job e command deve responder:

- o utilizador está autenticado?
- o cliente está ativo?
- o recurso pertence ao cliente?
- o papel permite esta ação?
- a ação administrativa será auditada?

Regras:

- preferir Policies, Gates e scopes reutilizáveis;
- não confiar apenas em middleware genérico;
- validar relações pai/filho em rotas aninhadas;
- retornar `404` quando necessário para não revelar recursos;
- consultas administrativas e de cliente devem ser explicitamente separadas;
- exports, downloads e jobs seguem as mesmas regras;
- testes devem tentar acesso horizontal a outro cliente.

## 9. Entradas, Saídas e Injeções

- validar tipo, formato, tamanho, intervalo e enumeração;
- rejeitar campos desconhecidos em payloads sensíveis quando possível;
- usar parâmetros vinculados e Eloquent em consultas;
- não concatenar entrada em SQL, comandos ou caminhos;
- escapar argumentos inevitáveis de processos;
- não usar `eval`;
- desserialização deve proibir classes e aceitar apenas origem confiável;
- Vue deve renderizar texto escapado por padrão;
- `v-html` exige sanitização e revisão de segurança;
- erros enviados ao cliente não revelam stack trace, SQL ou segredos.

## 10. Chamadas HTTP e Proteção SSRF

Para qualquer URL configurável:

- permitir apenas HTTPS;
- usar allowlist de scheme, host e porta;
- bloquear credenciais embutidas na URL;
- bloquear IPs privados, loopback, link-local e reservados;
- validar resolução DNS;
- desativar redirects ou validar todos os destinos;
- aplicar timeout de conexão e resposta;
- limitar tamanho da resposta;
- não enviar segredos antes de validar o destino;
- registar destino e resultado sem registar credenciais.

Chamadas ZoneSoft devem:

- respeitar limites documentados do fornecedor;
- aplicar retries apenas a falhas transitórias;
- usar backoff com jitter;
- respeitar `Retry-After`;
- parar perante autenticação permanentemente inválida;
- implementar circuit breaker para falhas prolongadas.

## 11. Sincronização, Filas e Concorrência

Todo job precisa de:

- identificador rastreável;
- timeout finito;
- número de tentativas;
- backoff;
- idempotência;
- lock com escopo correto;
- heartbeat quando for longo;
- cancelamento ou encerramento seguro;
- tratamento de falha;
- métricas e alertas.

Regras de sincronização:

- nunca aumentar concorrência sem medir rate limit;
- manter orçamento global de chamadas;
- usar limites por cliente e evento;
- paginar com limite máximo de páginas e itens;
- detectar páginas repetidas;
- limitar tempo total e memória;
- guardar cursor ou janela incremental;
- não publicar se uma parte obrigatória falhar;
- impedir workers antigos de publicar sobre dados novos;
- preservar o último snapshot completo;
- permitir repetição sem duplicar dados.

## 12. Banco de Dados e Migrations

- migrations devem ser compatíveis com dados existentes;
- alterações grandes são ensaiadas numa cópia do schema real;
- adicionar índices antes de introduzir consultas críticas;
- evitar locks longos em horário de uso;
- usar transações nos limites corretos;
- migrations destrutivas exigem estratégia em etapas;
- não remover coluna antes de retirar todos os leitores;
- migrations devem ter rollback quando tecnicamente seguro;
- alterações irreversíveis exigem backup e procedimento específico;
- validar integridade, contagens e totais depois da execução.

Para dados multi-tenant:

- tabelas de negócio devem identificar o cliente direta ou indiretamente;
- índices devem considerar o tenant;
- uniques globais só são usados quando a regra for realmente global;
- cascades e exclusões devem ser explicitamente aprovados.

## 13. Valores Financeiros

- dinheiro não pode ser calculado com `float`;
- usar cêntimos inteiros ou decimal exato;
- definir moeda em todos os limites externos relevantes;
- arredondar apenas em pontos documentados;
- preservar precisão recebida até aplicar a regra;
- notas de crédito e devoluções têm sinais documentados;
- tipos FS, FT, NC, CM e ZT têm perímetros explícitos;
- total com ZT e total sem ZT devem ser verificáveis;
- cada alteração financeira exige casos dourados do ZPOS;
- divergência nunca é corrigida com ajuste manual sem rastreabilidade.

## 14. Privacidade e Retenção

Antes de guardar um campo:

- identificar finalidade;
- definir base legal;
- definir quem acede;
- definir retenção;
- definir proteção;
- definir eliminação.

Regras:

- recolher apenas o necessário;
- preferir agregados a payloads brutos;
- mascarar identificadores em interfaces e logs;
- cifrar dados sensíveis em repouso;
- backups seguem a mesma retenção e proteção;
- exportação e eliminação devem respeitar relações e obrigações legais;
- novos subprocessadores precisam de avaliação.

## 15. Frontend e Navegador

- não guardar tokens sensíveis em `localStorage`;
- não depender do frontend para autorização;
- não incluir segredos no bundle;
- configurar CSP e evitar scripts inline sem nonce/hash;
- links externos usam proteção adequada;
- estados de erro não expõem detalhes internos;
- service workers não devem servir dados autenticados desatualizados;
- caches devem ser separados por sensibilidade;
- PWA deve ter fallback compatível com Laravel;
- formulários sensíveis precisam de prevenção contra submissão duplicada;
- acessibilidade de teclado, foco, contraste e mensagens deve ser testada.

## 16. Logs, Auditoria e Monitorização

Logs técnicos devem incluir:

- timestamp;
- ambiente;
- correlation ID;
- operação;
- resultado;
- duração;
- código de erro seguro.

Logs não podem incluir:

- palavras-passe;
- tokens ou segredos;
- cabeçalhos de autenticação;
- links completos de recuperação;
- payload integral com dados pessoais;
- números completos de cartões;
- stack traces apresentados ao cliente.

Auditoria de negócio deve guardar:

- ator;
- cliente;
- ação;
- recurso;
- antes/depois quando permitido;
- data;
- origem;
- resultado.

Alertas mínimos:

- aplicação indisponível;
- erro acima do limite;
- sincronização atrasada ou presa;
- rate limit do fornecedor;
- fila atrasada ou falhada;
- backup atrasado ou falhado;
- certificado próximo do vencimento;
- disco, memória ou base acima do limite;
- acesso administrativo suspeito.

## 17. Dependências e Supply Chain

- locks são obrigatórios e versionados;
- dependências não utilizadas devem ser removidas;
- atualizações são pequenas e frequentes;
- `composer audit` e `npm audit` executam no CI;
- vulnerabilidade crítica ou alta bloqueia release;
- exceção exige justificativa, mitigação, responsável e prazo;
- imagens Docker devem usar versões fixas;
- scripts de instalação de origem desconhecida são proibidos;
- pacotes novos exigem avaliação de manutenção, licença e segurança.

## 18. Infraestrutura e Deploy

- infraestrutura necessária deve estar versionada;
- produção corresponde a commit e release identificáveis;
- artefactos são imutáveis;
- containers têm `healthcheck`, `restart` e limites;
- processos usam utilizador não-root;
- código é somente leitura quando possível;
- redes e volumes seguem menor privilégio;
- migrations são uma etapa controlada;
- deploy inclui backup quando houver risco de dados;
- deploy inclui verificação pública e autenticada;
- rollback é definido antes da publicação;
- mudanças manuais de produção são reconciliadas imediatamente no Git.

## 19. Testes Mínimos

Toda mudança precisa dos testes aplicáveis:

- caminho de sucesso;
- validação de entrada;
- utilizador não autenticado;
- utilizador sem permissão;
- acesso a recurso de outro cliente;
- cliente inativo;
- recurso inexistente;
- repetição da mesma operação;
- falha de dependência externa;
- timeout;
- rate limit;
- concorrência;
- rollback;
- integridade dos dados.

Mudanças financeiras também exigem:

- arredondamento;
- valores negativos;
- desconto;
- nota de crédito;
- ZT separado e combinado;
- pagamentos parciais;
- comparação com caso real aprovado.

Mudanças de sincronização também exigem:

- uma máquina com falha;
- uma página repetida;
- resposta vazia;
- resposta incompleta;
- worker antigo;
- retry;
- reboot ou interrupção;
- publicação atómica.

## 20. Verificações Antes de Merge

Executar, conforme aplicável:

```bash
composer validate --strict
composer audit --locked
vendor/bin/pint --test
php artisan test
npm audit
npm run build
git diff --check
```

Além dos comandos:

- rever migrations;
- rever autorização;
- rever logs;
- rever dados e segredos;
- rever impacto multi-tenant;
- rever rollback;
- rever métricas.

## 21. Verificações Antes de Deploy

- pull request aprovado;
- CI aprovado;
- dependências auditadas;
- backup recente e verificável;
- migrations ensaiadas;
- rollback preparado;
- versão e artefacto identificados;
- janela de deploy definida;
- responsável pela validação disponível;
- monitorização aberta;
- clientes avisados quando necessário.

## 22. Verificações Depois do Deploy

- confirmar healthcheck;
- confirmar login;
- confirmar autorização de cliente e administrador;
- confirmar dashboard crítico;
- confirmar fila e scheduler;
- confirmar sincronização sem forçar carga indevida;
- confirmar logs e alertas;
- comparar métricas antes/depois;
- confirmar versão publicada;
- iniciar rollback se os limites de erro forem ultrapassados.

## 23. Definition of Done

Uma mudança está concluída apenas quando:

- requisito e regra de negócio estão documentados;
- ameaças e falhas foram consideradas;
- código e infraestrutura estão versionados;
- revisão foi aprovada;
- testes aplicáveis foram adicionados e aprovados;
- auditorias e build foram aprovados;
- documentação foi atualizada;
- deploy e rollback estão definidos;
- produção foi validada;
- monitorização não mostra regressão;
- evidências foram anexadas;
- risco residual foi registado e aceite.

## 24. Processo de Exceção

Uma exceção a este padrão precisa conter:

- regra que não será cumprida;
- motivo;
- risco criado;
- mitigação temporária;
- responsável;
- data limite;
- aprovação explícita.

Exceções sem prazo não são permitidas. Uma exceção não pode autorizar:

- segredo ou dado real no Git;
- ausência de backup numa alteração destrutiva;
- bypass de isolamento entre clientes;
- publicação consciente de vulnerabilidade crítica explorável;
- cálculos financeiros sem precisão definida.

## 25. Hotfix de Emergência

Um hotfix pode usar fluxo reduzido quando existe incidente ativo, mas exige:

1. registo do incidente;
2. backup quando houver risco de dados;
3. alteração mínima;
4. teste focado;
5. rollback imediato disponível;
6. validação em produção;
7. commit e release identificáveis;
8. revisão completa no máximo no próximo dia útil;
9. testes permanentes para impedir regressão.

Hotfix não pode existir apenas no servidor.

## 26. Modelo de Registo para Nova Implementação

Usar este bloco na issue ou pull request:

```text
Título:
Objetivo:
Clientes/utilizadores afetados:
Regra de negócio:
Dados tratados:
Classificação de risco:
Autorização e isolamento:
Integrações externas:
Impacto financeiro:
Impacto em banco/migrations:
Falhas esperadas:
Testes:
Métricas:
Deploy:
Rollback:
Monitorização:
Riscos residuais:
Aprovações:
```

## 27. Responsabilidade

Quem implementa é responsável por apresentar evidências. Quem revê é responsável
por verificar risco, isolamento, testes e rollback. Quem autoriza o deploy é
responsável por confirmar os gates operacionais.

Segurança não é uma etapa final. Ela faz parte da definição, implementação, revisão,
deploy e operação de cada mudança.
