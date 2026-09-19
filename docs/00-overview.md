# Dev App Pro - Přehled

## Účel

Dev App Pro je lokální business-management aplikace pro správu klientů, projektů, úkolů a financí.
Nahrazuje výchozí localhost landing page plnohodnotnou SaaS-style aplikací, která běží kompletně lokálně.

## Priority

1. **Zabezpečení** - bezpečnost první, bez kompromisů
2. **Modularita** - PSR-4 autoloading, Repository pattern, oddělené vrstvy
3. **Rychlost** - minimální JS, lokální assety, žádné CDN

## Stack

| Vrstva | Technologie |
|---|---|
| Web server | Apache 2.4 |
| Backend | PHP 8.3+ (strict types) |
| Databáze | MariaDB / MySQL 8+ |
| Frontend | React 19 + Vite 7 |
| UI knihovna | shadcn/ui (lokální komponenty) |
| Styling | Tailwind CSS 4 (lokální build) |
| Design systém | Linear theme (shadcnblocks) |
| Ikony | Lucide (lokální) |
| Fonty | Inter + JetBrains Mono (lokální WOFF2) |

## Architektura

```
┌─────────────────────────────────────────────┐
│  Prohlížeč (klient)                          │
│  ┌─────────────────────────────────────────┐ │
│  │  React SPA                              │ │
│  │  - React Router (client-side routing)   │ │
│  │  - shadcn/ui komponenty                  │ │
│  │  - Tailwind CSS (lokální build)         │ │
│  │  - Linear theme design tokens            │ │
│  └────────────────┬────────────────────────┘ │
└───────────────────┼──────────────────────────┘
                    │ HTTPS (lokálně HTTP)
                    │ fetch() JSON API
┌───────────────────┼──────────────────────────┐
│  Apache           │                          │
│  ┌────────────────▼────────────────────────┐ │
│  │  PHP REST API                            │ │
│  │  - Čisté JSON endpointy                  │ │
│  │  - PSR-4 autoloading                      │ │
│  │  - Repository pattern                     │ │
│  │  - ApiController abstrakce                 │ │
│  │  - Session-based auth (HTTP-only cookies) │ │
│  └────────────────┬────────────────────────┘ │
│  ┌────────────────▼────────────────────────┐ │
│  │  MariaDB / MySQL                         │ │
│  │  - PDO + prepared statements              │ │
│  │  - Databázové migrace                     │ │
│  └─────────────────────────────────────────┘ │
└─────────────────────────────────────────────┘
```

## Moduly aplikace

**Klienti jsou primárním zdrojem pravdy.** Vše začíná klientem. Projekt, faktura,
transakce, poznámka i soubor mohou být navázány na klienta. Projekt je volitelný.

| Modul | Popis |
|---|---|
| Dashboard | Přehled KPI, poslední projekty, nadcházející úkoly, finanční souhrn |
| Klienti | CRUD klientů, detail klienta s projekty a fakturami |
| Projekty | CRUD projektů, přiřazení klienta, status, detail s úkoly. Auto-sync ze složek |
| Úkoly | CRUD úkolů, přiřazení projektu, status, priorita, termín |
| Finance | Faktury, platby faktur, příjmy, výdaje, přehled obratu |
| Poznámky | Polymorfní poznámky navazatelné na klienta, projekt, úkol, fakturu |
| Soubory | Nahrávání a správa souborů, polymorfní vazba na entity |
| Nastavení | Profil uživatele, změna hesla, předvolby aplikace |
| Login | Autentizace, rate limiting, session management |

## Zásady

- **Vše lokálně** - žádné CDN, žádné externí služby, žádné Google Fonts
- **Bezpečnost první** - každá vrstva má bezpečnostní opatření
- **Modulární** - každá vrstva je nezávislá, snadno nahraditelná
- **Rychlé** - minimální payload, lokální assety, žádný runtime JS framework overhead
- **Denně použitelné** - profesionální UX/UI vhodné pro denní práci
- **Auto-sync projektů** - nová složka v `~/Projekty` = automaticky nový projekt
- **Český formát** - datum `26.3.2026`, měna `1 500 Kč`, jméno `first_name last_name`

## Dokumentace

| Soubor | Obsah |
|---|---|
| `01-security.md` | Zabezpečení - autentizace, CSRF, XSS, CSP, rate limiting |
| `02-modularity.md` | Modularita - PSR-4, Repository pattern, vrstvy |
| `03-performance.md` | Rychlost - build, caching, lazy loading |
| `04-architecture.md` | Architektura - PHP API + React SPA + Vite |
| `05-database.md` | Databázové schema, migrace |
| `06-api.md` | API specifikace - endpointy, request/response |
| `07-frontend.md` | Frontend - React, shadcn/ui, Linear theme |
| `08-deployment.md` | Deployment, konfigurace Apache, .htaccess |
| `09-checklisty.md` | Kontrolní checklisty (bezpečnost, modularita, rychlost) |
| `10-testovani.md` | Testování - TDD strategie, PHPUnit, Vitest |

## Plány implementace

Před každou implementací se vytváří plán ve složce `plany/`. Plán obsahuje:

- Cíl a rozsah
- **Reálné riziko a obtížnost** - hodnocení, rizika s mitigací, rollback plán
- Předpoklady a závislosti
- **Načtení reálného kódu** - stávající soubory k přečtení, konvence k dodržet
- Dotčené soubory (nové, upravované, mazané)
- Database změny (schema, seed, migrace)
- API změny (endpointy, request/response)
- Frontend změny (komponenty, stránky, hooks, typy)
- Testy (TDD - píšou se před implementací)
- Kontrolní body (odkaz na checklisty)
- Pořadí kroků (TDD cyklus pro každý krok)
- Kriteria dokončení

Šablona: `plany/_sablona.md`
