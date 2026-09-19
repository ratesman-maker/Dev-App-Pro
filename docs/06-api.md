# 06 - API specifikace

REST API. Vše na `/api/*`. JSON request/response. CSRF token vyžadován pro mutující operace.

---

## 1. Konvence

### 1.1 HTTP metody

| Metoda | Použití | CSRF |
|---|---|---|
| GET | Načtení seznamu nebo detailu | Ne |
| POST | Vytvoření záznamu | Ano |
| PUT | Úprava záznamu | Ano |
| DELETE | Smazání záznamu | Ano |

### 1.2 Status kódy

| Kód | Význam |
|---|---|
| 200 | OK - GET, PUT úspěch |
| 201 | Created - POST úspěch |
| 204 | No Content - DELETE úspěch |
| 400 | Bad Request - neplatný vstup |
| 401 | Unauthorized - nepřihlášen |
| 403 | Forbidden - neplatný CSRF |
| 404 | Not Found - záznam neexistuje |
| 422 | Unprocessable Entity - validace selhala |
| 429 | Too Many Requests - rate limit |
| 500 | Internal Server Error |

### 1.3 Response formát

```json
// Úspěch (200/201)
{
  "id": 1,
  "first_name": "Jan",
  "last_name": "Novák",
  "full_name": "Jan Novák",
  "email": "jan.novak@example.cz"
}

// Seznam (200)
{
  "data": [...],
  "total": 50,
  "page": 1,
  "per_page": 50
}

// Chyba (400/401/403/422/429/500)
{
  "error": "Neplatný vstup.",
  "fields": {
    "email": "Vyžadován platný email."
  }
}
```

### 1.4 Hlavičky

```http
Content-Type: application/json
X-CSRF-Token: <token z cookie>
```

---

## 2. Autentizace

### 2.1 POST /api/auth/login

**Request:**
```json
{
  "username": "admin",
  "password": "heslo123"
}
```

**Response 200:**
```json
{
  "user": {
    "id": 1,
    "name": "Administrátor",
    "username": "admin"
  }
}
```

**Response 401:**
```json
{
  "error": "Neplatné přihlašovací údaje."
}
```

**Response 429:**
```json
{
  "error": "Příliš mnoho pokusů. Zkuste to znovu za 5 min 30 s."
}
```

### 2.2 POST /api/auth/logout

**Response 200:**
```json
{
  "success": true
}
```

### 2.3 GET /api/auth/me

**Response 200:**
```json
{
  "user": {
    "id": 1,
    "name": "Administrátor",
    "username": "admin",
    "theme": "dark",
    "sidebar_collapsed": false,
    "per_page": 20
  }
}
```

**Response 401:**
```json
{
  "error": "Neautorizováno."
}
```

### 2.4 POST /api/auth/password-hint

Vrátí hint pro zadané username (pro reset hesla). **Nevyžaduje přihlášení.**

**Request:**
```json
{
  "username": "admin"
}
```

**Response 200:**
```json
{
  "username": "admin",
  "hint": "Jméno mého prvního psa"
}
```

**Response 200 (bez hintu):**
```json
{
  "username": "admin",
  "hint": null
}
```

**Response 404:**
```json
{
  "error": "Uživatel nenalezen."
}
```

**Bezpečnost:**
- Endpoint nevyžaduje přihlášení (používá se před přihlášením)
- Nevrací žádné další údaje (jen hint)
- Rate limit: 5 pokusů za hodinu na IP (stejné jako login)
- Hint je připomínka, ne ověření - lokální app na 127.0.0.1

### 2.5 POST /api/auth/reset-password

Resetuje heslo po zadání username a nového hesla. **Nevyžaduje přihlášení.**

**Request:**
```json
{
  "username": "admin",
  "new_password": "NovéHeslo123!",
  "new_password_confirm": "NovéHeslo123!"
}
```

**Response 200:**
```json
{
  "message": "Heslo bylo změněno. Nyní se můžete přihlásit."
}
```

**Response 422:**
```json
{
  "error": "Hesla se neshodují."
}
```

**Pravidla:**
- `new_password` musí být shodné s `new_password_confirm`
- Minimální délka hesla: 8 znaků
- Po resetu: **zničit všechny aktivní sessions** tohoto uživatele
- Po resetu: **přihlášení s novým heslem** (žádné automatické přihlášení)
- Rate limit: 5 pokusů za hodinu na IP
- Heslo se hashuje bcrypt (cost 12) před uložením

### 2.6 PUT /api/users/me/password-hint

Nastaví hint pro reset hesla. **Vyžaduje přihlášení.**

**Request:**
```json
{
  "password_hint": "Jméno mého prvního psa"
}
```

**Response 200:**
```json
{
  "password_hint": "Jméno mého prvního psa"
}
```

**Pravidla:**
- Max 255 znaků
- Prázdný string = smazat hint
- Lze nastavit jen pro self (přihlášený uživatel)

---

## 3. Klienti

### 3.1 GET /api/clients

**Query parametry:**
- `page` (int, default 1)
- `per_page` (int, default 50, max 100)
- `search` (string) - hledání v name, email
- `sort` (string, default "last_name") - last_name, company_name, email, created_at

**Response 200:**
```json
{
  "data": [
    {
      "id": 1,
      "type": "individual",
      "first_name": "Jan",
      "last_name": "Novák",
      "full_name": "Jan Novák",
      "company_name": null,
      "ico": null,
      "dic": null,
      "bank_account": null,
      "email": "jan.novak@example.cz",
      "phone": "+420 123 456 789",
      "address": "Praha 1",
      "note": "Důležitý klient",
      "created_at": "2026-01-15T10:30:00Z"
    },
    {
      "id": 2,
      "type": "company",
      "first_name": null,
      "last_name": null,
      "full_name": "Novák s.r.o.",
      "company_name": "Novák s.r.o.",
      "ico": "12345678",
      "dic": "CZ12345678",
      "bank_account": "123456789/0100",
      "email": "info@novak.cz",
      "phone": "+420 123 456 789",
      "address": "Praha 1",
      "note": "",
      "created_at": "2026-02-20T09:15:00Z"
    }
  ],
  "total": 25,
  "page": 1,
  "per_page": 50
}
```

### 3.2 GET /api/clients/{id}

**Response 200:**
```json
{
  "id": 1,
  "type": "individual",
  "first_name": "Jan",
  "last_name": "Novák",
  "full_name": "Jan Novák",
  "company_name": null,
  "ico": null,
  "dic": null,
  "bank_account": null,
  "email": "jan.novak@example.cz",
  "phone": "+420 123 456 789",
  "address": "Praha 1",
  "note": "Důležitý klient",
  "created_at": "2026-01-15T10:30:00Z",
  "projects_count": 3,
  "invoices_total_cents": 1500000
}
```

**Response 404:**
```json
{
  "error": "Klient nenalezen."
}
```

### 3.3 POST /api/clients

**Request (osoba):**
```json
{
  "type": "individual",
  "first_name": "Petr",
  "last_name": "Svoboda",
  "email": "petr.svoboda@example.cz",
  "phone": "+420 987 654 321",
  "address": "Brno",
  "note": ""
}
```

**Request (firma):**
```json
{
  "type": "company",
  "company_name": "Svoboda s.r.o.",
  "ico": "87654321",
  "dic": "CZ87654321",
  "bank_account": "987654321/0100",
  "email": "info@svoboda.cz",
  "phone": "+420 987 654 321",
  "address": "Brno, Novákova 10",
  "note": ""
}
```

**Response 201 (osoba):**
```json
{
  "id": 26,
  "type": "individual",
  "first_name": "Petr",
  "last_name": "Svoboda",
  "full_name": "Petr Svoboda",
  "company_name": null,
  "ico": null,
  "dic": null,
  "bank_account": null,
  "email": "petr.svoboda@example.cz",
  "phone": "+420 987 654 321",
  "address": "Brno",
  "note": "",
  "created_at": "2026-09-11T18:30:00Z"
}
```

**Validace:**
- `type = 'individual'`: first_name + last_name vyžadováno, company_name/ico/dic ignorovány
- `type = 'company'`: company_name vyžadováno, first_name/last_name ignorovány
- `ico`: 8 číslic (CZ), bez mezer
- `dic`: `CZ` + 8-10 číslic
- `email`: platný formát
- `bank_account`: formát `číslo/předčíslo` nebo IBAN

**Response 422:**
```json
{
  "error": "Validace selhala.",
  "fields": {
    "name": "Jméno je povinné.",
    "email": "Neplatný formát emailu."
  }
}
```

### 3.4 PUT /api/clients/{id}

**Request:** stejné jako POST

**Response 200:** stejné jako GET /api/clients/{id}

### 3.5 DELETE /api/clients/{id}

Smaže klienta trvale.

**Response 204**

**Pravidla:**
- `ON DELETE SET NULL`: `projects.client_id`, `invoices.client_id`,
  `transactions.client_id`, `noteables`, `fileables` (záznamy zůstanou, ztratí vazbu)
- **Nevratné**

---

## 4. Projekty

### 4.1 GET /api/projects

**Query parametry:**
- `page`, `per_page`, `search`
- `client_id` (int) - filtr podle klienta
- `status` (enum) - filtr podle statusu

**Response 200:**
```json
{
  "data": [
    {
      "id": 1,
      "client_id": 1,
      "client_name": "Jan Novák",
      "name": "Web redesign",
      "status": "active",
      "folder_path": "Web redesign",
      "budget_cents": 500000,
      "started_at": "2026-01-01",
      "deadline": "2026-03-31",
      "tasks_count": 12,
      "tasks_open": 5,
      "created_at": "2026-01-15T10:30:00Z"
    }
  ],
  "total": 15,
  "page": 1,
  "per_page": 50
}
```

### 4.2 GET /api/projects/{id}

**Response 200:**
```json
{
  "id": 1,
  "client_id": 1,
  "client_name": "Jan Novák",
  "name": "Web redesign",
  "description": "Kompletní redesign webu",
  "status": "active",
  "folder_path": "Web redesign",
  "budget_cents": 500000,
  "started_at": "2026-01-01",
  "deadline": "2026-03-31",
  "tasks": [...],
  "invoices": [...],
  "created_at": "2026-01-15T10:30:00Z"
}
```

### 4.3 POST /api/projects

```json
{
  "client_id": 1,
  "name": "Nový projekt",
  "description": "Popis projektu",
  "status": "active",
  "budget_cents": 300000,
  "started_at": "2026-09-01",
  "deadline": "2026-12-31"
}
```

### 4.4 PUT /api/projects/{id}

Stejné jako POST.

### 4.5 POST /api/projects/{id}/archive

Archivuje projekt (status → `archived`). Data zůstávají, projekt se skryje
z výchozích seznamů.

**Response 200:**
```json
{
  "id": 1,
  "status": "archived"
}
```

**Pravidla:**
- Lze archivovat jakýkoliv projekt (aktivní, pozastavený, dokončený)
- Archivace zachovává všechny vazby (úkoly, faktury, transakce, poznámky, soubory)
- Pokud projekt pochází ze složky (`folder_path`), složka na disku se **nemaže**
- Archivovaný projekt lze obnovit přes `POST /api/projects/{id}/restore`

### 4.6 POST /api/projects/{id}/restore

Obnoví projekt z archivu (status → `active`).

**Response 200:**
```json
{
  "id": 1,
  "status": "active"
}
```

**Pravidla:**
- Vrátí status na `active` (ne na původní - jednodušší a předvídatelné)
- Pokud projekt pochází ze složky a složka už neexistuje, obnova se provede
  ale `folder_path` zůstává (při dalším sync cronu se znovu archivuje,
  pokud složka stále chybí)
- Lze obnovit i ručně smazané projekty? **Ne** - smazané projekty neexistují.

### 4.7 DELETE /api/projects/{id}

Smaže projekt trvale (včetně úkolů, plateb, vazeb).

**Response 204**

**Pravidla:**
- Kaskádové mazání: `tasks` (ON DELETE CASCADE), `invoice_payments` (přes faktury)
- `ON DELETE SET NULL`: `invoices.project_id`, `transactions.project_id`,
  `noteables`, `fileables` (záznamy zůstanou, jen ztratí vazbu na projekt)
- Pokud projekt pochází ze složky (`folder_path`), složka na disku se **nemaže**
- **Nevratné** - na rozdíl od archivace

---

## 5. Úkoly

### 5.1 GET /api/tasks

**Query parametry:**
- `page`, `per_page`, `search`
- `project_id` (int)
- `status` (enum)
- `priority` (enum)
- `overdue` (bool) - pouze po termínu

**Response 200:**
```json
{
  "data": [
    {
      "id": 1,
      "project_id": 1,
      "project_name": "Web redesign",
      "title": "Navrhnout wireframy",
      "status": "done",
      "priority": "high",
      "due_date": "2026-02-15",
      "assigned_to": "admin",
      "estimated_minutes": 240,
      "spent_minutes": 215,
      "created_at": "2026-01-16T08:00:00Z"
    }
  ],
  "total": 30,
  "page": 1,
  "per_page": 50
}
```

### 5.2 POST /api/tasks

```json
{
  "project_id": 1,
  "title": "Nový úkol",
  "description": "Popis úkolu",
  "status": "todo",
  "priority": "medium",
  "due_date": "2026-10-01",
  "assigned_to": "admin",
  "estimated_minutes": 480
}
```

### 5.3 PUT /api/tasks/{id}

Stejné jako POST.

### 5.4 DELETE /api/tasks/{id}

Smaže úkol trvale.

**Response 204**

**Pravidla:**
- Žádné kaskády - úkol nemá podřízené entity
- `noteables`, `fileables` ztratí vazbu (ON DELETE přes aplikaci)
- **Nevratné**

---

## 6. Finance - Faktury

### 6.1 GET /api/invoices

**Query parametry:**
- `page`, `per_page`, `search`
- `client_id` (int)
- `status` (enum)
- `overdue` (bool)

**Response 200:**
```json
{
  "data": [
    {
      "id": 1,
      "client_id": 1,
      "client_name": "Jan Novák",
      "project_id": 1,
      "project_name": "Web redesign",
      "invoice_number": "2026001",
      "status": "paid",
      "subtotal_cents": 124000,
      "vat_rate_percent": 21,
      "vat_amount_cents": 26000,
      "amount_cents": 150000,
      "paid_cents": 150000,
      "currency": "CZK",
      "variable_symbol": "2026001",
      "constant_symbol": "0308",
      "iban": null,
      "issue_date": "2026-02-01",
      "due_date": "2026-02-15"
    }
  ],
  "total": 20,
  "page": 1,
  "per_page": 50
}
```

### 6.2 POST /api/invoices

```json
{
  "client_id": 1,
  "project_id": 1,
  "invoice_number": "2026021",
  "subtotal_cents": 165000,
  "vat_rate_percent": 21,
  "issue_date": "2026-09-11",
  "due_date": "2026-09-25",
  "variable_symbol": "2026021",
  "constant_symbol": "0308",
  "iban": "CZ6508000000192000000145",
  "note": ""
}
```

**Výpočet na serveru:**
- `vat_amount_cents` = `subtotal_cents * vat_rate_percent / 100`
- `amount_cents` = `subtotal_cents + vat_amount_cents`
- Pokud `vat_rate_percent = 0`: `vat_amount_cents = 0`, `amount_cents = subtotal_cents`

### 6.3 PUT /api/invoices/{id}

Stejné jako POST.

### 6.4 DELETE /api/invoices/{id}

Smaže fakturu trvale.

**Response 204**

**Pravidla:**
- Kaskádové mazání: `invoice_payments` (ON DELETE CASCADE)
- `noteables`, `fileables` ztratí vazbu
- **Nevratné** - smazaná faktura i se všemi platbami

---

## 7. Finance - Platby faktur

### 7.1 GET /api/invoice-payments?invoice_id={id}

### 7.2 POST /api/invoice-payments

```json
{
  "invoice_id": 1,
  "amount_cents": 50000,
  "payment_date": "2026-02-10",
  "method": "bank_transfer",
  "note": "Záloha"
}
```

### 7.3 DELETE /api/invoice-payments/{id}

Smaže platbu trvale.

**Response 204**

**Pravidla:**
- Žádné kaskády - platba nemá podřízené entity
- Po smazání přepočítat `invoices.paid_cents` (aplikace)
- **Nevratné**

---

## 8. Finance - Transakce

### 8.1 GET /api/transactions

**Query parametry:**
- `page`, `per_page`, `search`
- `project_id` (int)
- `client_id` (int)
- `type` (enum: income, expense)
- `category` (enum: office, software, travel, marketing, hardware, services, income_project, income_consulting, other)
- `from` (date) - od data
- `to` (date) - do data

### 8.2 POST /api/transactions

```json
{
  "project_id": 1,
  "client_id": 1,
  "type": "income",
  "amount_cents": 50000,
  "category": "income_project",
  "description": "Záloha za projekt",
  "transaction_date": "2026-09-11"
}
```

**Kategorie podle typu:**
- `type = 'expense'`: office, software, travel, marketing, hardware, services, other
- `type = 'income'`: income_project, income_consulting, other

### 8.3 PUT /api/transactions/{id}

Stejné jako POST.

### 8.4 DELETE /api/transactions/{id}

Smaže transakci trvale.

**Response 204**

**Pravidla:**
- Žádné kaskády - transakce nemá podřízené entity
- `noteables`, `fileables` ztratí vazbu
- **Nevratné**

---

## 9. Nastavení

### 9.1 GET /api/settings

Vrátí aplikační nastavení (ze `settings` tabulky).

**Response 200:**
```json
{
  "invoice_number_format": "{year}{seq:03d}",
  "default_vat_rate": "21",
  "default_due_days": "14",
  "timezone": "Europe/Prague",
  "first_day_of_week": "1",
  "fiscal_year_start": "01-01",
  "currency": "CZK",
  "currency_decimals": "0"
}
```

### 9.2 PUT /api/settings

Aktualizuje aplikační nastavení. Povoleno jen admin uživateli.

**Request:**
```json
{
  "default_vat_rate": "15",
  "default_due_days": "30"
}
```

**Response 200:** stejné jako GET

### 9.3 GET /api/company-profile

Vrátí údaje prodávajícího (vaše firma pro faktury).

**Response 200:**
```json
{
  "id": 1,
  "type": "company",
  "first_name": null,
  "last_name": null,
  "company_name": "Dev App Pro s.r.o.",
  "ico": "12345678",
  "dic": "CZ12345678",
  "email": "info@devapppro.cz",
  "phone": "+420 123 456 789",
  "address": "Praha 1, Národní 10",
  "bank_account": "123456789/0100",
  "iban": "CZ6508000000192000000145",
  "swift": "GIBACZPX"
}
```

### 9.4 PUT /api/company-profile

Aktualizuje údaje prodávajícího. Povoleno jen admin uživateli.

**Request:** stejné pole jako GET response.

**Response 200:** stejné jako GET

### 9.5 GET /api/users/me/preferences

Vrátí preference aktuálního uživatele.

**Response 200:**
```json
{
  "theme": "dark",
  "sidebar_collapsed": false,
  "per_page": 20
}
```

### 9.6 PUT /api/users/me/preferences

Aktualizuje preference aktuálního uživatele.

**Request:**
```json
{
  "theme": "light",
  "sidebar_collapsed": true,
  "per_page": 50
}
```

**Response 200:** stejné jako GET

### 9.7 GET /api/invoices/{id}/pdf

Vygeneruje PDF faktury pomocí mPDF.

**Response 200:**
```
Content-Type: application/pdf
Content-Disposition: attachment; filename="faktura-2026001.pdf"
```

**PDF obsahuje:**
- Údaje prodávajícího (z `company_profile`)
- Údaje kupujícího (z `clients`)
- Položky, DPH, celkovou částku
- Variabilní a konstantní symbol
- IBAN pro platbu
- Datum vystavení a splatnost (český formát)

---

## 10. Dashboard

### 9.1 GET /api/dashboard

**Response 200:**
```json
{
  "stats": {
    "clients": 25,
    "projects": 15,
    "active_projects": 8,
    "tasks": 120,
    "tasks_open": 45,
    "tasks_done": 75,
    "tasks_urgent": 3
  },
  "finance": {
    "total_paid_cents": 1500000,
    "total_open_cents": 300000,
    "overdue_count": 2,
    "overdue_cents": 100000
  },
  "recent_projects": [...],
  "upcoming_tasks": [...],
  "overdue_invoices": [...]
}
```

---

## 11. Poznámky

### 11.1 GET /api/notes

**Query parametry:**
- `page`, `per_page`, `search`
- `entity_type` (enum) - filtr podle typu entity
- `entity_id` (int) - filtr podle konkrétní entity

**Response 200:**
```json
{
  "data": [
    {
      "id": 1,
      "user_id": 1,
      "user_name": "Administrátor",
      "title": "Schůzka s klientem",
      "content": "Domluvit termín předání projektu...",
      "attachments": [
        {"entity_type": "client", "entity_id": 1},
        {"entity_type": "project", "entity_id": 3}
      ],
      "created_at": "2026-09-11T18:30:00Z",
      "updated_at": "2026-09-11T18:30:00Z"
    }
  ],
  "total": 15,
  "page": 1,
  "per_page": 50
}
```

### 11.2 GET /api/notes/{id}

**Response 200:**
```json
{
  "id": 1,
  "user_id": 1,
  "user_name": "Administrátor",
  "title": "Schůzka s klientem",
  "content": "Domluvit termín předání projektu...",
  "attachments": [
    {"entity_type": "client", "entity_id": 1, "entity_name": "Jan Novák"},
    {"entity_type": "project", "entity_id": 3, "entity_name": "Web redesign"}
  ],
  "created_at": "2026-09-11T18:30:00Z",
  "updated_at": "2026-09-11T18:30:00Z"
}
```

### 11.3 POST /api/notes

**Request:**
```json
{
  "title": "Nová poznámka",
  "content": "Obsah poznámky",
  "attachments": [
    {"entity_type": "client", "entity_id": 1},
    {"entity_type": "project", "entity_id": 3}
  ]
}
```

**Response 201:**
```json
{
  "id": 16,
  "title": "Nová poznámka",
  "content": "Obsah poznámky",
  "attachments": [
    {"entity_type": "client", "entity_id": 1},
    {"entity_type": "project", "entity_id": 3}
  ],
  "created_at": "2026-09-11T19:00:00Z"
}
```

### 11.4 PUT /api/notes/{id}

**Request:** stejné jako POST (aktualizuje i `attachments` - nahradí celý seznam)

### 11.5 DELETE /api/notes/{id}

Smaže poznámku trvale.

**Response 204**

**Pravidla:**
- Kaskádové mazání: `noteables` (ON DELETE CASCADE - vazby na entity)
- **Nevratné**

---

## 12. Soubory

### 12.1 GET /api/files

**Query parametry:**
- `page`, `per_page`
- `entity_type` (enum) - filtr podle typu entity
- `entity_id` (int) - filtr podle konkrétní entity

**Response 200:**
```json
{
  "data": [
    {
      "id": 1,
      "user_id": 1,
      "user_name": "Administrátor",
      "original_name": "nabidka.pdf",
      "mime_type": "application/pdf",
      "size_bytes": 245678,
      "is_image": false,
      "thumbnail_path": null,
      "medium_path": null,
      "attachments": [
        {"entity_type": "client", "entity_id": 1},
        {"entity_type": "project", "entity_id": 3}
      ],
      "created_at": "2026-09-11T18:30:00Z"
    },
    {
      "id": 2,
      "user_id": 1,
      "user_name": "Administrátor",
      "original_name": "screenshot.png",
      "mime_type": "image/png",
      "size_bytes": 1024000,
      "is_image": true,
      "thumbnail_path": "2026/09/abc_thumb.webp",
      "medium_path": "2026/09/abc_medium.webp",
      "attachments": [
        {"entity_type": "project", "entity_id": 3}
      ],
      "created_at": "2026-09-12T10:00:00Z"
    }
  ],
  "total": 8,
  "page": 1,
  "per_page": 50
}
```

### 12.2 GET /api/files/{id}

**Response 200:**
```json
{
  "id": 1,
  "original_name": "nabidka.pdf",
  "mime_type": "application/pdf",
  "size_bytes": 245678,
  "is_image": false,
  "thumbnail_path": null,
  "medium_path": null,
  "attachments": [...],
  "created_at": "2026-09-11T18:30:00Z"
}
```

### 12.3 GET /api/files/{id}/download

Stáhne soubor. Není JSON - vrací binární data s `Content-Disposition: attachment`.

```http
Content-Type: application/pdf
Content-Disposition: attachment; filename="nabidka.pdf"
Content-Length: 245678

<binární data souboru>
```

Server ověří, že uživatel je přihlášen a soubor existuje.

### 12.4 POST /api/files

**Request:** `multipart/form-data` (ne JSON)

```
Content-Type: multipart/form-data; boundary=...

--boundary
Content-Disposition: form-data; name="file"; filename="nabidka.pdf"
Content-Type: application/pdf

<binární data souboru>
--boundary
Content-Disposition: form-data; name="attachments"

[{"entity_type":"client","entity_id":1},{"entity_type":"project","entity_id":3}]
--boundary--
```

**Response 201:**
```json
{
  "id": 9,
  "original_name": "nabidka.pdf",
  "mime_type": "application/pdf",
  "size_bytes": 245678,
  "attachments": [
    {"entity_type": "client", "entity_id": 1},
    {"entity_type": "project", "entity_id": 3}
  ],
  "created_at": "2026-09-11T19:00:00Z"
}
```

**Response 422:**
```json
{
  "error": "Soubor není povolen.",
  "fields": {
    "file": "Nepodporovaný typ souboru."
  }
}
```

### 12.5 PUT /api/files/{id}

Aktualizuje pouze `attachments` (název, typ, obsah souboru se nemění).

**Request:**
```json
{
  "attachments": [
    {"entity_type": "client", "entity_id": 1}
  ]
}
```

### 12.6 DELETE /api/files/{id}

Smaže DB záznam i fyzický soubor na disku.

**Response 204**

**Pravidla:**
- Kaskádové mazání: `fileables` (ON DELETE CASCADE - vazby na entity)
- Smaže fyzický soubor z `storage/YYYY/MM/uuid.ext`
- Smaže thumbnail a medium varianty (pokud existují)
- **Nevratné** - soubor je trvale ztracen
