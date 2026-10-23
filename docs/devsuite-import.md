# Import dat z DevSuite Pro (10/2026)

Jednorázová migrace dat ze staré aplikace DevSuite Pro (SQLite) do Dev App Pro
(MariaDB). Skript: `cli/import-devsuite.php` (idempotentní, má `--dry-run`).

## Zdroj

- Záloha `devsuite_backup_2026-09-20_130317.tar.gz` ze starého stroje
  (`staralaptop`, `~/Backups/`). Obsahuje `devsuite_db.sql` (SQLite dump),
  `files/<old_client_id>/<filename>` (soubory klientů) a `mariadb/` dumpy
  projektových WP databází (nepřenášely se).
- Worklog přílohy v záloze nejsou — leží na `staralaptop` ve
  `/var/lib/devsuite/worklog/<project>/<filename>`, stáhly se zvlášť.

## Co se přeneslo

| Oblast | Zdroj (SQLite) | Cíl (MariaDB) | Množství |
|---|---|---|---|
| Pracovní deník | `log_entries` | `worklog_entries` | 34 |
| Přílohy deníku | `worklog_attachments` + `worklog/` | `worklog_attachments` + `storage/` | 20 |
| Soubory klientů | `client_files` + `files/` | `files` + `fileables` (klient i projekt) | 8 / 15 vazeb |
| Finance | `transactions` | `transactions` | 13 |

Soubory fyzicky do `storage/YYYY/MM/<uuid>.<ext>` podle data uploadu.
Částky `REAL` Kč → `amount_cents`. Kategorie transakcí mapovány:
`web_dev` → `income_project`, `maintenance`/`salary` → `income_consulting`,
`tools` → `software`, jinak `other`. Protistrana, účet a VS se připojily
do `description` (nové schéma pro ně sloupce nemá).

## Mapování klientů (old id → new id)

| Old | Jméno | New |
|---|---|---|
| 3 | Kloknerův ústav | 6 (Kloknerův ústav ČVUT) |
| 23 | ZŠ Edvarda Beneše | 7 |
| 24 | GymPark Chodov | nový (company) |
| 29 | Andrea Prknová | 3 |
| 30 | Iva Hemská | 2 |
| 31 | Obereggen Anna | nový (company) |
| 32 | FNUSA | nový (government) |
| 33 | Jana Malá | nový (individual) |
| 34 | Jiří Zahradník | nový (nonprofit, Dortem proti rakovině z.s.) |
| 35 | Marie Pavelková | 9 (Mluvím anglicky s.r.o., doplněn contact_name) |

## Mapování projektů (slug → projekt)

Existující: klokner+klokner-novy → klok-cvut-cz, zakladniskola →
zsedvardabenese-ostry (záznamy 8, 18 a související soubory/transakce výjimečně
do zsedvardabenese-vyvoj, jde o redesign), zakladniskola-html →
zakladniskola-html, obereggen-anna → obereggenanna-com, hemska →
ivahemska-cz, mluvimanglicky → mluvimanglicky-html, miroslavbartik →
miroslavbartik-cz, dortemprotirakovine(+html) → dortem-proti-rakovine-cz.

Nově založené (archived, `folder_path=NULL`): GymPark Chodov, skloprozeny.cz.
Jde o reálné minulé projekty s finančními/souborovými vazbami. Sync projektů
je ignoruje (`folder_path` NULL se nearchivuje ani nemaže) — to je záměr.

Bez projektu (jen deníkové záznamy, vazba přes `client_id`): akademiefnusa,
fnusahpp, global.

## Co se nepřeneslo

- `invoices` (0 řádků), `invoice_items` (18 sirotčích/testovacích řádků)
- `recurring_transactions` (1× mzda FNUSA; recurring v nové appce neexistuje)
- `accounts` (2 řádky; hodnota přešla do popisu transakcí)
- `email_*` (434 zpráv; e-mail modul v nové appce není)
- `settings`, `users`, `schema_migrations`, `project_credentials`,
  `project_meta`, `client_project`

## Úklid po importu (PR #32)

Původní import zakládal archived projekty i pro čistě deníkové slugs
(Akademie FNUSA, Dortem proti rakovině jako duplicita reálného projektu).
Opraveno: fiktivní projekty smazány, worklog přemapován na klienta, resp.
na reálný projekt `dortem-proti-rakovine-cz` přes `folder_path` resolver.

## Re-run

```bash
php8.5 cli/import-devsuite.php --src=/cesta/k/zaloze [--dry-run]
```

`--src` očekává `devsuite.db`, `files/`, `worklog/`. Cílovou DB přepíná
`DB_NAME` env. Deduplikace: klienti podle jména/firmy, worklog podle
title+created_at, soubory podle original_name+size, transakce podle
data+částky+popisu.
