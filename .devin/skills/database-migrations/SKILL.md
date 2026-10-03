---
name: database-migrations
description: Bezpečné DB migrace pro Dev App Pro (MariaDB 11.8). Použij při každé změně schématu — nové tabulky, sloupce, indexy, datové migrace, rename, mazání sloupců.
---

# Database Migrations — Dev App Pro (MariaDB)

Každá změna DB = migrační soubor `database/migration_XXX.sql` + okamžitá synchronizace `database/schema.sql` (testy na něm stojí — už dvakrát se rozjelo proti live DB a lámalo testy).

## Povinný workflow

1. **Napiš `database/migration_XXX.sql`** — číslování navazuje na existující, idempotentní (bezpečné pro opakované spuštění)
2. **Aplikuj na live DB `devapppro`** vzápětí (dev user, `mariadb` CLI nebo phpMyAdmin)
3. **Aktualizuj `schema.sql`** — musí odpovídat live DB (ověř přes `information_schema`, ne odhadem)
4. **`seed.sql`/`seed_test.sql`** — doplnit pokud migrace přidává výchozí data potřebné pro testy
5. Ověření: `bin/test.sh unit` (UnitTestCase staví DB ze schema.sql)

## Idempotence

```sql
-- Tabulky / sloupce / indexy
CREATE TABLE IF NOT EXISTS xxx (...);
ALTER TABLE xxx ADD COLUMN IF NOT EXISTS col INT;
CREATE INDEX IF NOT EXISTS idx_xxx_col ON xxx (col);

-- Data (jen když jsou potřeba)
INSERT IGNORE INTO settings (`key`, `value`) VALUES ('xxx', '1');
```

- [ ] `IF NOT EXISTS` / `INSERT IGNORE` / `ON DUPLICATE KEY UPDATE` — migration se musí přežít 2× spuštění
- [ ] `schema.sql`: `SET FOREIGN_KEY_CHECKS=0` JEN na začátku a `=1` na konci — **žádné SET uprostřed** (DROP projektů pak selže na 1451 při datech v child tabulkách)
- [ ] Nasazená migrace se NEmění — změna = nová migrace (audit trail)

## Bezpečné patterny pro existující tabulky

### Nový sloupec

```sql
-- OK: nullable nebo s default
ALTER TABLE users ADD COLUMN nickname VARCHAR(100) NULL;
ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1;

-- POZOR: NOT NULL bez default — na prázdné tabulce OK, s daty spadne/kopíruje
ALTER TABLE users ADD COLUMN role VARCHAR(20) NOT NULL; -- nedělat
-- místo toho: NULL → backfill → (případně) constraint v další migraci
```

### Index na existující tabulku

```sql
-- MariaDB online DDL — neblokuje zápisy (na malých tabulkách irelevantní, návyk dobrý)
ALTER TABLE invoices ADD INDEX idx_due_date (due_date), ALGORITHM=INPLACE, LOCK=NONE;
```

### Rename sloupce — expand-contract (ne rename přes jednu migraci)

1. Migrace A: `ADD COLUMN new_name` + backfill ze starého
2. Kód čte/píše nový sloupec (deploy)
3. Migrace B (až ověřené): `DROP COLUMN old_name`

Na lokální single-user appce přijatelné i přímé `RENAME COLUMN` v jedné migraci — ale jen když se kód deployuje současně (stejný commit).

### Drop sloupce

1. Nejprve odebrat všechny reference v kódu (repositáře, typy, frontend)
2. Deploy bez reference
3. Pak migrace s `DROP COLUMN` — nikdy obráceně

## Datové migrace (backfill)

- [ ] **Schema a data v oddělených migracích** — DDL v `migration_XXX.sql`, backfill v `migration_YYY.sql` (snazší rollback i debug)
- [ ] Větší tabulky → batch po dávkách, ne jeden UPDATE:

```sql
UPDATE invoices SET status = 'overdue'
 WHERE status = 'sent' AND due_date < CURDATE()
 ORDER BY id LIMIT 5000;
-- opakovat dokud affected_rows > 0 (v CLI skriptu nebo ručně)
```

- [ ] Backfill čísel/částek přepočítat deterministicky — a ověřit vzorek proti původním datům
- [ ] **Testovat na `devapppro_copy`** (trvalá kopie produkce: `mariadb-dump devapppro | mariadb devapppro_copy`), ne na produkční — a teprve pak live

## Rollback

- [ ] Před migrací se destruktivní změnou (DROP, UPDATE velké tabulky): dump tabulky `mariadb-dump devapppro <tabulka> > /tmp/xxx.sql`
- [ ] Rollback plán do poznámky v migraci (DROP nové tabulky, DROP COLUMN zpět nelze bez dat → proto expand-contract)
- [ ] `schema.sql` = zdroj pravdy; po rollbacku znovu sesynchronizovat

## Checklist před commitem

- [ ] `migration_XXX.sql` existuje, idempotentní, aplikovaný na live DB
- [ ] `schema.sql` odpovídá live DB (porovnat `information_schema` — tabulky, sloupce, indexy, FK)
- [ ] `seed_test.sql` doplněný pokud testy potřebují data
- [ ] Žádné `SET FOREIGN_KEY_CHECKS` uprostřed schema.sql
- [ ] NOT NULL bez default neřešen na tabulce s daty
- [ ] Data backfill v oddělené migraci, ověřený na `devapppro_copy`
- [ ] `bin/test.sh unit` projde (UnitTestCase staví ze schema.sql)
- [ ] Nové FK mají smysl pro `ON DELETE` chování projektů (viz project_delete_jobs)
