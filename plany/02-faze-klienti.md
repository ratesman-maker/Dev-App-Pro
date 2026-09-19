# Fáze 1b - Klienti (CRUD)

**Fáze:** Fáze 1 (kroky 6-7 z `10-testovani.md`)
**Datum vytvoření:** 2026-09-11
**Rozsah:** ~5 nových souborů
**Obtížnost:** Střední
**Riziko:** Nízké

---

## 1. Cíl

**Co se implementuje:**
- CRUD pro klienty (GET seznam, GET detail, POST vytvoření, PUT úprava, DELETE smazání)
- Podpora dvou typů: osoba (first_name, last_name) a firma (company_name, ico, dic, bank_account)
- Validace IČO (8 číslic), DIČ (CZ + 8-10 číslic), email
- Složení full_name (osoba: "first_name last_name", firma: "company_name")
- Mazání klienta nastaví project.client_id = NULL (ON DELETE SET NULL)

**Co NENÍ součástí:**
- Frontend (Fáze 5)
- Projekty, úkoly, faktury (Fáze 2)
- Poznámky, soubory (Fáze 3)

---

## 1b. Reálné riziko a obtížnost

### 1b.1 Obtížnost: Střední

**Snadné:** CRUD endpointy (vzor z AuthApiController, SettingsApiController)
**Složité:** Validace IČO/DIČ, podmíněná povinnost polí (osoba vs firma)
**Neznámé:** Žádné

### 1b.2 Riziko: Nízké

| Riziko | Pravděpodobnost | Dopad | Mitigace |
|---|---|---|---|
| Smazání klienta ne nastaví NULL | Nízká | Střední | FK ON DELETE SET NULL v schema, test |
| Validace IČO příliš striktní | Střední | Nízký | Test s reálnými IČO |

**Rollback:** git checkout, drop clients tabulka, recreate ze schema.sql

---

## 2. Předpoklady

- Fáze 1a dokončena (infrastruktura, auth, settings) ✅
- DB tabulka `clients` v schema.sql ✅
- Core/ApiController, Core/Repository, helpers.php ✅

---

## 3. Načtení reálného kódu

| Soubor | Proč | Co hledat |
|---|---|---|
| `src/Controllers/SettingsApiController.php` | Vzor controller | Struktura, handle(), show(), update() |
| `src/Controllers/AuthApiController.php` | Vzor controller | handle() dispatch, requireAuth, require_csrf |
| `src/Core/Repository.php` | Základní repository | find(), all(), create(), update(), delete() |
| `src/Core/ApiController.php` | Základní controller | getMethod(), getId(), jsonSuccess() |
| `database/schema.sql` | clients tabulka | Sloupce, FK, indexy |
| `docs/06-api.md` sekce 3 | API specifikace | Endpointy, request/response |

---

## 4. Dotčené soubory

### Nové:
```
src/Repositories/ClientRepository.php
src/Controllers/ClientApiController.php
api/clients.php
tests/Unit/ClientRepositoryTest.php
tests/Integration/ClientApiTest.php
```

### Upravované:
- `database/schema.sql` - ověřit, že clients tabulka existuje (již ano)
- `tests/test-router.php` - ověřit, že /api/clients routuje (již ano)

---

## 5. API endpointy

| Metoda | Cesta | Popis | Auth | CSRF |
|---|---|---|---|---|
| GET | `/api/clients` | Seznam (paginace) | Ano | Ne |
| GET | `/api/clients/{id}` | Detail | Ano | Ne |
| POST | `/api/clients` | Vytvoření | Ano | Ano |
| PUT | `/api/clients/{id}` | Úprava | Ano | Ano |
| DELETE | `/api/clients/{id}` | Smazání | Ano | Ano |

---

## 6. Testy

### tests/Unit/ClientRepositoryTest.php
- test_create_osoba
- test_create_firma
- test_find_by_id
- test_update
- test_delete_sets_project_client_id_null

### tests/Integration/ClientApiTest.php
- test_seznam_bez_prihlaseni_vrati_401
- test_seznam_po_prihlaseni_vrati_200
- test_vytvoreni_osoby
- test_vytvoreni_firmy
- test_osoba_bez_prijmeni_vrati_422
- test_firma_bez_nazvu_vrati_422
- test_neplatne_ico_vrati_422
- test_neplatny_email_vrati_422
- test_smazani_klienta_nastavi_project_client_id_null
- test_detail_vrati_404_pro_neexistujici

---

## 7. Kriteria dokončení

- [ ] Všechny testy procházejí (stávající 29 + nové)
- [ ] CRUD endpointy fungují přes Apache
- [ ] Validace IČO/DIČ/email funguje
- [ ] Smazání klienta nastaví project.client_id = NULL
- [ ] Žádný soubor > 500 řádků
