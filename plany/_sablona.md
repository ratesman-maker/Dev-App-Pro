# Šablona implementačního plánu

Tento soubor je šablona. Zkopíruj ho, přejmenuj (např. `01-faze-autentizace.md`)
a vyplň. Každý plán **musí** obsahovat všechny sekce níže.

---

# [Název plánu]

**Fáze:** [číslo fáze z `10-testovani.md`, např. Fáze 1]
**Datum vytvoření:** [YYYY-MM-DD]
**Rozsah:** [počet nových/upravovaných souborů]
**Obtížnost:** [Nízká / Střední / Vysoká / Very High]
**Riziko:** [Nízké / Střední / Vysoké / Kritické]

---

## 1. Cíl

**Co se implementuje:**
- [1-2 věty popisující cíl]

**Co NENÍ součástí (scope boundaries):**
- [Co se v tomto plánu nedělá - explicitní omezení]
- [Např. "neimplementuje se PDF generování - to je v plánu 03"]

---

## 1b. Reálné riziko a obtížnost

> Umožňuje realisticky zhodnotit, co nás čeká. Říká, **kde se mohou objevit problémy**,
> a pomáhá rozhodnout, zda je potřeba plán rozdělit nebo zda je bezpečné pokračovat.

### 1b.1 Obtížnost implementace

**Hodnocení:** [Nízká / Střední / Vysoká / Very High]

**Odůvodnění:**
- [Proč je tato obtížnost - co je složité, co je rutinní]
- [Např. "Střední - CRUD je rutinní, ale DPH výpočet a číslování faktur vyžaduje pozornost"]

**Co je snadné (rutinní):**
- [Seznam částí, které jsou standardní, dobře známé]
- [Např. "CRUD endpointy - vzor z ClientApiController"]

**Co je složité:**
- [Seznam částí, které vyžadují pozornost, neobvyklé řešení]
- [Např. "Auto-sync ze složek - filesystem operace, detekce přejmenování"]
- [Např. "PDF generování - mPDF konfigurace, šablona, česká diakritika"]

**Neznámé (co se musí dohledat):**
- [Co není na 100% jasné před implementací]
- [Např. "Jak přesně mPDF zpracuje českou diakritiku - ověřit"]

### 1b.2 Reálné riziko

**Hodnocení:** [Nízké / Střední / Vysoké / Kritické]

**Odůvodnění:**
- [Proč je toto riziko - co se může pokazit, jaký je dopad]

**Rizika a mitigace:**

| Riziko | Pravděpodobnost | Dopad | Mitigace |
|---|---|---|---|
| [Popis rizika] | [Nízká/Střední/Vysoká] | [Nízký/Střední/Vysoký/Kritický] | [Jak se mu vyhnout nebo řešit] |
| [Např. "DB migrace smaže data"] | Nízká | Kritický | [Záloha před migrací, test na devapppro_test] |
| [Např. "mPDF nezvládne UTF-8"] | Střední | Střední | [Ověřit na jednoduché faktuře před plnou implementací] |
| [Např. "Auto-sync smaže projekt při přejmenování"] | Střední | Vysoký | [Archivace místo mazání, test sync-projects.php] |
| [Např. "CSRF token neplatný po session regeneraci"] | Střední | Vysoký | [Test login → akce, token rotace] |

**Dopad na produkci:**
- [Co se stane, pokud implementace selže nebo bude mít chybu]
- [Např. "Uživatel neztratí data - jen nemůže vytvořit novou fakturu"]
- [Např. "Může dojít ke ztrátě dat při špatné migraci - záloha nutná"]

**Rollback plán:**
- [Jak vrátit změny, pokud implementace selže]
- [Např. "git checkout před commitem, restore DB ze zálohy"]
- [Např. "Smazat nové soubory, revert upravených, drop nových tabulek"]

### 1b.3 Rozdělení (pokud je potřeba)

Pokud je obtížnost **Vysoká** nebo riziko **Vysoké/Kritické**, zvážit rozdělení plánu:

- **Plán A:** [část 1 - např. "CRUD bez DPH"]
- **Plán B:** [část 2 - např. "DPH výpočet a číslování"]
- **Plán C:** [část 3 - např. "PDF generování"]

Každý dílčí plán musí mít vlastní testy a kriteria dokončení.

---

## 2. Předpoklady

**Co musí být hotové předem:**
- [Seznam plánů, které musí být dokončeny]
- [Infrastruktura - Apache, PHP, MariaDB, Composer, npm]

**Závislosti:**
- **Composer balíčky:** [např. `mpdf/mpdf`]
- **npm balíčky:** [např. `@tanstack/react-query`]
- **DB tabulky:** [seznam tabulek, které musí existovat]
- **Konfigurace:** [např. `PROJECTS_WATCH_DIR` v config.php]

---

## 3. Načtení reálného kódu (kontext)

> **KRITICKÉ:** Před implementací **načíst a projít** reálný kód, který se týká tohoto plánu.
> Cílem je pochopit stávající stav, konvence a vzory, než se začne psát nový kód.

### 3.1 Existující soubory k načtení

Seznam souborů, které je potřeba **přečíst** před implementací:

| Soubor | Proč načíst | Co hledat |
|---|---|---|
| `bootstrap.php` | Inicializace aplikace | Jak se načítá config, DB, session |
| `config/config.php` | Stávající konstanty | Co už existuje, co přidat |
| `src/Core/ApiController.php` | Základní controller | Vzor pro nové controllery |
| `src/Core/Repository.php` | Základní repository | Vzor pro nové repositories |
| `src/Auth.php` | Autentizace | Jak login/session funguje |
| `src/helpers.php` | Sdílené funkce | Co už existuje (Rule of Three) |
| `api/*.php` | Existující endpointy | Vzor pro nové endpointy |
| `frontend/src/lib/api.ts` | API klient | Jak se volá API z frontendu |
| `frontend/src/hooks/useAuth.ts` | Auth hook | Vzor pro nové hooks |
| `frontend/src/components/shared/DataTable.tsx` | Sdílené komponenty | Vzor pro nové komponenty |

### 3.2 Konvence k dodržet

Po načtení kódu **zapsat** zjištěné konvence (aby se dodržely):

- **Pojmenování tříd:** [např. PascalCase, suffix `Controller`, `Repository`]
- **Pojmenování souborů:** [např. `ClientApiController.php`]
- **Pojmenování metod:** [např. `camelCase`, `index()`, `show()`, `store()`, `update()`, `destroy()`]
- **Struktura controlleru:** [vzor z `Core/ApiController`]
- **Struktura repository:** [vzor z `Core/Repository`]
- **JSON response formát:** [vzor z existujících endpointů]
- **Error handling:** [vzor z existujících endpointů]
- **Frontend hook struktura:** [vzor z `useAuth`]
- **Frontend komponenta struktura:** [vzor z `shared/`]

### 3.3 Pravidla modularity k dodržet

- [ ] Max 500 řádků na soubor (520 tolerováno)
- [ ] Max 4 úrovně zanoření logiky (ne JSX)
- [ ] Rule of Three - extrahovat při 3. výskytu
- [ ] Žádné duplikace sdílených funkcí
- [ ] Sdílené funkce v `helpers.php` / `lib/utils.ts`

---

## 4. Dotčené soubory

### 4.1 Nové soubory (co se vytvoří)

```
src/Controllers/XxxApiController.php
src/Repositories/XxxRepository.php
api/xxx.php
frontend/src/pages/XxxPage.tsx
frontend/src/hooks/useXxx.ts
frontend/src/types/xxx.ts
tests/Integration/XxxApiTest.php
tests/Unit/XxxRepositoryTest.php
```

### 4.2 Upravované soubory (co se mění)

| Soubor | Co se mění | Proč |
|---|---|---|
| `config/config.php` | Přidat konstantu `XXX` | Nová konfigurace |
| `frontend/src/App.tsx` | Přidat routu `/xxx` | Nová stránka |
| `frontend/src/components/layout/Sidebar.tsx` | Přidat položku | Nový modul |
| `database/schema.sql` | Přidat tabulku | Nová tabulka |

### 4.3 Mazané soubory (pokud něco)

- [Seznam nebo "žádné"]

---

## 5. Database změny

### 5.1 Nové tabulky

```sql
CREATE TABLE xxx (
    ...
);
```

### 5.2 Upravované tabulky

```sql
ALTER TABLE xxx ADD COLUMN ...;
```

### 5.3 Seed data

```sql
INSERT INTO xxx (...) VALUES (...);
```

### 5.4 Migrace

- [ ] `database/schema.sql` aktualizováno
- [ ] `database/seed.sql` aktualizováno (pokud seed)
- [ ] `database/seed_test.sql` aktualizováno (pro testy)
- [ ] Změna idempotentní (bezpečná pro opakované spuštění)

---

## 6. API změny

### 6.1 Nové endpointy

| Metoda | Cesta | Popis | Request | Response |
|---|---|---|---|---|
| GET | `/api/xxx` | Seznam | `?page=1&per_page=20` | `{data: [...], total}` |
| POST | `/api/xxx` | Vytvoření | `{...}` | `201 {id, ...}` |
| GET | `/api/xxx/{id}` | Detail | - | `{...}` |
| PUT | `/api/xxx/{id}` | Úprava | `{...}` | `200 {...}` |
| DELETE | `/api/xxx/{id}` | Smazání | - | `204` |

### 6.2 Upravované endpointy

- [Seznam nebo "žádné"]

### 6.3 Příklady request/response

```json
// Request POST /api/xxx
{
  "name": "Příklad"
}

// Response 201
{
  "id": 1,
  "name": "Příklad",
  "created_at": "2026-01-15T10:30:00Z"
}
```

---

## 7. Frontend změny

### 7.1 Nové komponenty

- `components/xxx/XxxDialog.tsx` - dialog pro vytvoření/úpravu
- `components/xxx/XxxTable.tsx` - DataTable pro seznam

### 7.2 Nové stránky

- `pages/XxxPage.tsx` - hlavní stránka modulu

### 7.3 Nové hooks

- `hooks/useXxx.ts` - CRUD operace přes TanStack Query

### 7.4 Nové typy

```typescript
// types/xxx.ts
export interface Xxx {
  id: number;
  name: string;
  created_at: string;
}
```

### 7.5 Routa

- [ ] `App.tsx` - přidat lazy import a `<Route>`
- [ ] `Sidebar.tsx` - přidat položku (pokud nový modul)

---

## 8. Testy (TDD - píšou se PŘED implementací)

> Testy se píšou **před** kódem. Red → Green → Refactor.

### 8.1 Seznam testů k napsání

Odkaz na `10-testovani.md` - konkrétní testy pro tento plán:

| Test soubor | Co ověřuje | Stav |
|---|---|---|
| `tests/Integration/XxxApiTest.php` | CRUD endpointy | [ ] napsán |
| `tests/Unit/XxxRepositoryTest.php` | Repository metody | [ ] napsán |
| `tests/Security/XxxTest.php` | Bezpečnost | [ ] napsán |
| `frontend/tests/pages/XxxPage.test.tsx` | UI komponenta | [ ] napsán |

### 8.2 Co každý test ověřuje

```
XxxApiTest:
  - test_vytvoreni_projde_s_platnymi_daty
  - test_vytvoreni_vrati_422_pro_neplatna_data
  - test_seznam_bez_prihlaseni_vrati_401
  - test_smazani_vrati_204
  - test_detail_vrati_404_pro_neexistujici
```

### 8.3 Test data (seed pro testy)

```sql
-- database/seed_test.sql (doplnit)
INSERT INTO xxx (...) VALUES (...);
```

---

## 9. Kontrolní body (odkaz na `09-checklisty.md`)

### 9.1 Bezpečnost (priorita 1)

- [ ] [Konkrétní položky z checklistu, které se týkají tohoto plánu]
- [ ] [Např. "1.1 Autentizace - login, session_regenerate_id"]
- [ ] [Např. "1.5 CSRF - token pro POST/PUT/DELETE"]
- [ ] [Např. "1.7 SQL Injection - prepared statements"]

### 9.2 Modularita (priorita 2)

- [ ] [Např. "2.2 Backend moduly - Repository pattern"]
- [ ] [Např. "2.4 Pravidla - max 500 řádků, 4 úrovně"]
- [ ] [Např. "2.5 Konfigurace - konstanty v config"]

### 9.3 Rychlost (priorita 3)

- [ ] [Např. "3.3 API - SELECT jen potřebných sloupců"]
- [ ] [Např. "3.4 React - memo, useCallback"]
- [ ] [Např. "3.6 Skeleton loading"]

---

## 10. Pořadí kroků

> Sekvenční seznam. Každý krok = TDD cyklus (Red → Green → Refactor).

### Krok 1: [Název kroku]

- [ ] **Red:** Napsat test `tests/.../XxxTest.php` (selže - funkce neexistuje)
- [ ] **Green:** Napsat minimální kód v `src/...` (test projde)
- [ ] **Refactor:** Upravit kód, test stále projde
- [ ] **Spustit:** `./vendor/bin/phpunit tests/.../XxxTest.php`

### Krok 2: [Název kroku]

- [ ] **Red:** Napsat test ...
- [ ] **Green:** Napsat kód ...
- [ ] **Refactor:** ...
- [ ] **Spustit:** ...

### Krok 3: [Název kroku]

- [ ] ...

### Krok N: Finální ověření

- [ ] **Spustit všechny testy:** `./vendor/bin/phpunit && npx vitest run`
- [ ] **PHPStan:** `./vendor/bin/phpstan analyse src/ --level=6`
- [ ] **ESLint:** `npx eslint frontend/src/`
- [ ] **Odškrtnout checklisty** v `09-checklisty.md`

---

## 11. Kriteria dokončení

Plán je dokončen, když **všechny** podmínky platí:

- [ ] Všechny testy procházejí (`phpunit` + `vitest`)
- [ ] Bezpečnostní testy procházejí (CSRF, SQL injection, auth)
- [ ] PHPStan čistý (level 6+)
- [ ] ESLint čistý
- [ ] Žádný soubor > 500 řádků (520 tolerováno)
- [ ] Žádné duplikace sdílených funkcí
- [ ] Checklist položky odškrtnuty v `09-checklisty.md`
- [ ] Database změny idempotentní
- [ ] Dokumentace aktualizována (pokud změna ovlivňuje API/DB schema)

---

## 12. Poznámky

[Volný prostor pro poznámky, rozhodnutí během implementace, změny oproti plánu]

---

## 13. Změny oproti plánu

> Pokud se během implementace plán změní, zapsat sem co a proč.

| Datum | Změna | Důvod |
|---|---|---|
| YYYY-MM-DD | [co se změnilo] | [proč] |
