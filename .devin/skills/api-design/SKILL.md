---
name: api-design
description: Konvence pro návrh REST API Dev App Pro (PHP controllery). Použij při návrhu nových endpointů, review API kontraktů, přidávání paginace/filtrů nebo úpravě error odpovědí.
---

# API Design — Dev App Pro

Konvence pro `/api/*` endpointy. Interní API pro vlastní SPA — bez verzování v URL, bez Bearer auth (session + CSRF). Cíl: konzistentní kontrakty napříč ~20 controllery.

## Kdy aktivovat

- Nový endpoint nebo controller metoda
- Review API kontraktů
- Paginace, filtrování, řazení seznamů
- Error odpovědi a status kódy
- Job-queue endpointy (restore, delete, hosting, php-version)

## URL a routing

```
RewriteRule ^api/([a-z-]+)/(.*)$ api/$1.php
RewriteRule ^api/projects/[0-9]+/credentials/?$ api/project-credentials.php [L,QSA]
```

- [ ] Resource = podstatné jméno, množné číslo, kebab-case: `/api/clients`, `/api/invoice-payments`, `/api/project-credentials`
- [ ] Speciální cesty (nested/akce) = explicitní RewriteRule před obecným patternem (vzor: `/api/projects/{id}/credentials`)
- [ ] ID v URL = číslo (`ctype_digit` přes `getId()`), nested zdroje: `/api/projects/{id}/credentials`
- [ ] Akce bez CRUD mapování = podzdrobný endpoint, ne verb v resource: `POST /api/backups/restore`, `POST /api/projects/{id}/php-version`
- [ ] Query parametry: `?page=1&per_page=20&search=&sort=` — snake_case, `sort` = whitelist sloupec

## HTTP metody a status kódy

| Metoda | Použití | Typické status |
|---|---|---|
| GET | Seznam, detail | 200, 400 (chybějící ID), 401, 404 |
| POST | Vytvoření, akce (restore, php-version) | 201, 200 (akce), 422, 429 |
| PUT | Plná částečná úprava (projekt používá PUT pro obojí) | 200, 404, 422 |
| DELETE | Smazání / job pro smazání | 200/202 (job), 404 |

- [ ] POST create → **201** (ne 200)
- [ ] Job-queue akce (delete-project, restore, php-version, hosting) → **202** nebo 200 + `job_id` v response — fronta zpracuje worker
- [ ] Validace → **422** s `fields`; špatný formát/missing ID → **400**
- [ ] Neautorizováno → **401**; chybějící oprávnění → **403**; nenalezeno → **404**
- [ ] Rate limit → **429** (login, reset — `login_attempts`)
- [ ] Nikdy 500 s interní chybou — generická zpráva, detail do error logu
- [ ] CSRF selhání → **403** (`require_csrf`)

## Response formáty

### Seznam (paginovaný)
```php
$this->jsonSuccess([
    'data'     => $result['data'],
    'total'    => $result['total'],
    'page'     => $page,
    'per_page' => $perPage,
], 200);
```
```json
{"data":[...],"total":142,"page":1,"per_page":50}
```

### Detail / create
```php
$this->jsonSuccess($client, 201); // POST create
$this->jsonSuccess($client, 200); // GET detail, PUT update
```

### Chyby
```php
// Validace s field chybami — 422
json_response(['error' => 'Validace selhala.', 'fields' => $result['fields']], 422);

// Prostá chyba — 400/401/403/404/429
$this->jsonError('Klient nenalezen.', 404);
```
```json
{"error":"Validace selhala.","fields":{"email":"Neplatný formát."}}
{"error":"Neplatný CSRF token."}
```

- [ ] Success = `jsonSuccess()`, error = `jsonError()`/`json_response(['error'=>...])` — nikdy custom envelope
- [ ] `json_response()` automaticky loguje 4xx/5xx se `sanitize_for_log()` — vlastní chyby psát přes helper, ne `echo json_encode`
- [ ] 422 = `fields` objekt {field: message} — frontend formuláře je zobrazují inline
- [ ] Chybové zprávy česky, genericky (žádný SQL, cesty, `$e->getMessage()`)

## Paginace, řazení, hledání

```php
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? (int) $_GET['per_page'] : Constants::DEFAULT_PER_PAGE;
$perPage = max(1, min(Constants::MAX_PER_PAGE, $perPage));
$search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
$sort = isset($_GET['sort']) ? (string) $_GET['sort'] : 'last_name';
```

- [ ] Offset paginace `?page&per_page` — local admin, malé datasety, nepotřebuje cursor
- [ ] `per_page` clamp na `Constants::DEFAULT_PER_PAGE` (50) / `MAX_PER_PAGE` (100) — frontend používá stejné konstanty
- [ ] `sort` = whitelist sloupců (mapování v repository, nikdy volný string do ORDER BY)
- [ ] `search` = `LIKE` s escapovanými `%_`, prepared statement
- [ ] Filtry: `?status=active` proti `Constants::*_STATUSES`; `?entity_type=` proti whitelistu

## Job-queue endpointy (asynchronní práce)

Vzor: job v DB tabulce → worker/cron zpracuje → frontend polluje stav.

| Endpoint | Job tabulka | Worker |
|---|---|---|
| `POST /api/backups/restore` | `backup_restores` | `cli/restore-backup.php` (cron 1min) |
| `DELETE /api/projects/{id}` | `project_delete_jobs` | `cli/delete-project.php` (timer 5s) |
| `POST /api/projects/{id}/php-version` | `php_version_jobs` | `cli/change-php-version.php` (timer 5s) |
| create/update `folder_path` | `project_hosting_jobs` | `cli/regenerate-project-hosting.php` (timer 5s) |

- [ ] Endpoint validuje vstup, vytvoří job (status `pending`), vrátí `job_id` + okamžitou odpověď
- [ ] Stav jobů přes `GET /api/.../jobs` (posledních ~20) — frontend polluje (1–2 s při aktivních)
- [ ] Job status transitions: `pending → <steps> → completed/failed`, `error_message` zkrácené
- [ ] Worker běží jako root → vstupy z DB jobu validovat znovu (nikdy nevěřit job datům)

## Autentizace a autorizace

- [ ] `$this->requireAuth()` na začátku `handle()` (vyjma auth.php: login, reset-password)
- [ ] `require_csrf()` pro POST/PUT/DELETE — GET-only controllery bez CSRF (Dashboard, FinanceOverview)
- [ ] Ownership check před přístupem k záznamu (user_id v session, ne z requestu)
- [ ] Rate limit tam, kde brute-force dává smysl (login, reset, možná upload)

## Checklist před commitem endpointu

- [ ] URL kebab-case, plural, nested přes explicitní RewriteRule
- [ ] Správný status kód (201 create, 422 validace, 404 nenalezeno, 429 rate limit)
- [ ] `jsonSuccess`/`jsonError` envelope — žádný vlastní formát
- [ ] Paginace přes `Constants::DEFAULT_PER_PAGE/MAX_PER_PAGE`
- [ ] `sort`/filtry přes whitelist, `search` escapované
- [ ] `requireAuth` + `require_csrf` (POST/PUT/DELETE)
- [ ] Validace v controlleru → 422 `fields`; částky/součty na serveru
- [ ] GET-only controllery dokumentované proč nemají CSRF
- [ ] Nový endpoint v `docs/06-api.md` (dokumentace)
- [ ] Integration test v `tests/Integration/` (`@group <modul>`)

## Anti-patterns (co nedělat)

- ❌ Verb v URL resource: `/api/getClients` → použij `GET /api/clients`
- ❌ 200 pro všechno (včetně chyb) → status kódy sémanticky
- ❌ `['success' => false]` v envelope → `error`/`fields` konvence
- ❌ `page`/`per_page` bez clamp → `Constants::MAX_PER_PAGE`
- ❌ `sort` přímo z `$_GET` do SQL → whitelist mapování
- ❌ `$e->getMessage()` v response → generická zpráva
- ❌ Cursor paginace / URL versioning `/v1/` — zbytečné pro interní API
- ❌ Synchronní dlouhé operace v requestu → job-queue vzor
