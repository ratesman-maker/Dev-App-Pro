---
name: gha-security-review
description: Security audit GitHub Actions workflow pro Dev App Pro. Použij při úpravě .github/workflows/, přidání secrets/nových triggerů, nebo při review CI změn.
---

# GHA Security Review — Dev App Pro

Audit `.github/workflows/*.yml` proti zneužitelným zranitelnostem. Každý nález MUSÍ mít
konkrétní exploitation scénář — pokud útok nejde postavit, nereportuj ho.

## Kontext projektu (aktualizuj při změnách)

- Repo je **privátní** (`ratesman-maker/Dev-App-Pro`) — externí fork PR vyžaduje
  collaborator přístup; threat model "cizí útočník přes fork PR" je tedy omezený,
  ale supply-chain a permissions platí pořád.
- Inventář workflow: `tests.yml` (pull_request + push:main; mariadb service;
  config heredocy; composer install; `bin/test.sh full` + `e2e`; žádné secrets).
- `bin/hooks/pre-push` je lokální gate — nesouvisí s GHA.

## Threat model

Reportuj jen zranitelnosti zneužitelné **externím útočníkem bez write access**
(fork PR, issue, komentář). Nereportuj: `workflow_dispatch` input injection,
injection ve `push`-only workflow na protected branches, `workflow_call` interní
callers, secrets v dispatch/schedule-only workflow.

## Checklist

1. **Pwn request** — `pull_request_target` + checkout fork kódu (`ref:` na PR head,
   lokální actions `./.github/actions/` z forku). Bezpečné: `pull_request` (fork
   kontext, read-only token) nebo `pull_request_target` BEZ checkoutu fork kódu.
2. **Expression injection** — `${{ }}` v `run:` blocích s attacker-controlled hodnotou
   (PR title/body, branch name, komentář). Bezpečné: čísla (PR number), SHA,
   `github.repository`, `secrets.*`, `${{ }}` v `if:`/`with:`/`env:` (runtime eval,
   ne shell).
3. **Komentářové příkazy** — `issue_comment` trigger bez `author_association` checku.
4. **Credential escalation** — PAT/deploy keys/secrets dostupné untrusted kódu;
   blast radius každého secretu.
5. **Config poisoning** — workflow čte config z PR souborů (`AGENTS.md`, `Makefile`,
   shell skripty pod `.github/`).
6. **Supply chain** — third-party actions pinnuté na tag (`@v4`) místo full SHA —
   tag je mutabilní; pin na SHA jako doporučení (zvaž Dependabot pro updaty).
7. **Permissions** — chybějící `permissions:` blok = default write scope pro
   `GITHUB_TOKEN` na push běžích. Minimum: `contents: read` (test job nic nezapisuje).
8. **Runner infra** — self-hosted runnery, cache/artifact poisoning.

## Confidence

- **HIGH** — trasována celá attack path (entry point → payload → mechanismus →
  dopad → PoC kroky), reportuj s fixem
- **MEDIUM** — část cesty nejistá, označ "needs verification"
- **LOW** — teoretické/mitigované jinde → nereportuj

## Výstup

Pro každý nález: `[GHA-00N] název (severity)` + workflow:řádek + trigger +
confidence + scénář zneužití + fix. Pokud nic: "žádné zneužitelné
zranitelnosti, workflow reviewed and cleared".

## Stav posledního auditu (2026-10-03)

- `tests.yml`: `pull_request` (ne `_target`), žádná secrets, žádné `${{ }}` v `run:`,
  config heredocy mimo PR soubory → cleared.
- Hardening proveden: `permissions: contents: read` doplněno.
- Otevřené doporučení: pin akcí na SHA místo tagů (`actions/checkout@v4` → `@<sha>`).
