# Release de Baseline da Produção

**Data:** 27 de julho de 2026
**Ambiente:** produção
**Branch candidata:** `codex/release-production-baseline`
**Commit da release:** definido após integração
**Estado:** em validação

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

Preencher após o cutover:

```text
Commit:
Tag:
Pull request:
Diretório da release:
Diretório de rollback:
Backup da base:
Checksum da base:
Início:
Fim:
Indisponibilidade:
Migration:
Healthcheck:
Login:
Dashboard:
Scheduler:
Resultado:
```
