# Release de Baseline da Produção

**Data:** 27 de julho de 2026
**Ambiente:** produção
**Branch candidata:** `codex/release-production-baseline`
**Commit da release:** `82d5d58f9e4a9ef1831cc036e99c47944bbf7188`
**Tag:** `production-2026-07-27.1`
**Estado:** implantada e validada

## Objetivo

Transformar as alterações funcionais que já estavam aplicadas diretamente no
servidor numa release versionada, testada, reproduzível e com rollback.

Esta release não adiciona uma nova regra de negócio. Ela consolida:

- dashboard de eventos e relatórios;
- sincronização ZoneSoft;
- sincronização automática e contador;
- término obrigatório do evento;
- otimizações de consultas e snapshots;
- regras de documentos fiscais e ZT;
- testes correspondentes.

## Validações da Candidata

- `composer validate --strict`: aprovado;
- `vendor/bin/pint --test`: aprovado;
- `git diff --check`: aprovado;
- `scripts/security/check-repository.sh`: aprovado;
- `php artisan test`: 66 testes e 465 asserções aprovados;
- `npm run build`: cliente, SSR e PWA aprovados;
- Gitleaks: zero achados.

## Exceção Temporária de Dependências

Regra não cumprida:

- a política de implementação segura bloqueia release com vulnerabilidades críticas
  ou altas conhecidas.

Motivo:

- o runtime atual de produção já utiliza exatamente os mesmos locks;
- esta release formaliza e protege o estado funcional existente;
- atualizar dependências no mesmo cutover aumentaria o risco de regressão e
  dificultaria o rollback.

Risco:

- `composer audit` regista 34 avisos em 12 pacotes;
- `npm audit` regista 19 vulnerabilidades, incluindo 2 críticas e 11 altas.

Mitigações:

- nenhuma dependência será adicionada ou alterada nesta release;
- repositório privado, histórico saneado e Gitleaks automático estão ativos;
- deploy terá backup de código, configuração e base;
- o CR-004 será executado numa release independente, com testes próprios.

Responsável:

- proprietário do produto.

Prazo:

- concluir CR-004 antes de iniciar onboarding de novos clientes comerciais.

## Backup Obrigatório

Antes do cutover:

- guardar código atual de produção;
- guardar `.env` com permissões restritas;
- executar backup consistente da base SQLite usada pelo Laravel;
- registar checksums;
- guardar estado dos containers e commit anterior;
- testar a leitura da cópia da base.

## Estratégia de Deploy

1. construir uma release limpa a partir do commit integrado;
2. instalar dependências a partir dos locks;
3. gerar assets frontend e SSR;
4. copiar apenas dados persistentes durante a janela de cutover;
5. parar os containers antes da cópia final do SQLite;
6. apontar `src` para a nova release;
7. recriar containers;
8. executar migrations e caches;
9. validar saúde, login, dashboard e scheduler.

## Rollback

Se qualquer validação crítica falhar:

1. parar os containers novos;
2. restaurar o apontamento para o diretório anterior;
3. restaurar a base apenas se uma migration tiver alterado dados;
4. recriar os containers anteriores;
5. validar `/up`, login e dashboard;
6. registar o motivo da reversão.

## Evidências Pós-Deploy

```text
Commit: 82d5d58f9e4a9ef1831cc036e99c47944bbf7188
Tag: production-2026-07-27.1
Pull request: https://github.com/Valtersystem/contacto_digital/pull/1
Diretório da release: /srv/projects/contacto-digital/releases/82d5d58f9e4a9ef1831cc036e99c47944bbf7188
Diretório de rollback: /srv/projects/contacto-digital/releases/pre-20260727-194950-cc9b9c8
Backup: /srv/backups/contacto-digital/deploy-20260727-194950
Checksum da base: f23141bf9b091419b15130ad426c22c6c5e5c1e9ddbdeb941985d8bd9d0e7d59
Início: 2026-07-27T19:53:03+02:00
Fim: 2026-07-27T19:53:23+02:00
Janela de cutover: 20 segundos
Indisponibilidade: não medida separadamente da janela de cutover
Migration: Nothing to migrate
Healthcheck: /up = 200 em 0,146 s
Login: /login = 200 em 0,125 s
Raiz: / = 302 para /login
PWA: /build/sw.js = 200
Dashboard: rota protegida; validação visual autenticada não executada por falta de sessão
Scheduler: cron ativo; ciclos 19:54 e 19:55 concluídos; dry-run sem evento devido
Resultado: aprovado, rollback não acionado
```

## Integridade Pós-Deploy

- SQLite: `PRAGMA integrity_check` retornou `ok`;
- utilizadores: 4 antes e 4 depois;
- clientes: 2 antes e 2 depois;
- eventos: 2 antes e 2 depois;
- importações: 83 antes e 83 depois;
- linhas de relatório: 8.128 antes e 8.128 depois;
- importações concluídas: 78 antes e 78 depois;
- importações falhadas históricas: 5 antes e 5 depois;
- importações em processamento no cutover: 0;
- manifesto Vite: `f163b7406575f953e300440f2b72f30d45d0539760bd9bac4313ac14bc40f049`;
- imagem PHP: `sha256:1ca58885e8a3d944fbecaffb3f1ab066eaae5059d106faa134b7805855b96a16`;
- requisitos PHP, incluindo `gd`, `intl`, SQLite e `zip`: aprovados;
- assets JS e CSS referenciados pelo manifesto: HTTP 200;
- logs PHP-FPM e Nginx após o cutover: sem erros.

## Observações Operacionais

- o disparador está em `/etc/cron.d/contacto-digital-scheduler`;
- o serviço `cron` está ativo e executa `schedule:run` a cada minuto;
- `events:sync-due-reports --dry-run` confirmou que não havia evento devido;
- os containers continuam sem healthcheck Docker e com política de restart
  indefinida; esta melhoria permanece fora desta baseline;
- o servidor continua com atualizações pendentes e pedido de reinício. A
  manutenção deve ser feita numa janela separada, com validação dos demais
  serviços hospedados.
