# Changelog

Všechny významné změny projektu Dev App Pro jsou dokumentovány v tomto souboru.

Formát vychází z [Keep a Changelog](https://keepachangelog.com/cs/1.1.0/),
projekt používá [Semantic Versioning](https://semver.org/lang/cs/).

## Pravidla vedení

- Každá změna do `main` (každý PR) přidá položku do `[Unreleased]` pod příslušnou sekci
- Sekce v pořadí: `Added` (nové funkce), `Changed` (změny chování), `Deprecated` (nabývá na vyřazení), `Removed` (odebrané), `Fixed` (opravy), `Security` (bezpečnost)
- Tvar záznamu: **tučný úvod s výsledkem pro uživatele**, pak příčina a oprava; česky, jeden až dva věty
- Breaking changes uvozují záznam verze nad kategoriemi, každý s povinnou migrační akcí
- Při release: `[Unreleased]` → `[X.Y.Z] - YYYY-MM-DD`, bump `APP_VERSION` v `config/config.php`, `git tag vX.Y.Z`
- SemVer: MAJOR = breaking změna API/DB, MINOR = nová funkce, PATCH = oprava
- Vydané záznamy jsou historie: nikdy nepřepisovat, opravy řešit novým záznamem

## [Unreleased]

## [1.1.0] - 2026-10-08

### Added

- **Zmrazení PDF při vydání faktury.** Přechod na sent/paid/overdue uloží PDF do `storage/invoices/faktura-<číslo>.pdf` (migration_020, `invoices.frozen_pdf`) a PDF endpoint servíruje archivní kopii; vydaná faktura se zpětně nemění úpravou položek ani profilu firmy. DELETE faktury soubor uklidí, detail ukazuje „Archivní PDF".
- **Kontaktní osoba u organizací.** Nová pole `clients.contact_name/contact_email/contact_phone` (migration_019); formulář ukazuje sekci jen u firem/nezisku/státní správy, search prohledává zástupce, tabulka klientů má sloupec „Zástupce" a detail ho zobrazuje.
- **Detekce typu projektu `php`.** Složka obsahující `.php` v kořenu se detekuje jako PHP projekt; statické weby mají vhosty bez FPM direktiv, PHP selektor v UI se zobrazuje jen pro `wordpress`/`php` a API `/php-version` ostatní typy odmítá. Sync přepočítává typ při změně obsahu složky.
- **Playwright E2E testy (`bin/test.sh e2e`).** 9 speců (login, chybné heslo, redirect nepřihlášeného, nástěnka, Projekty/Úkoly/Klienti, 404) proti `php -S` na `devapppro_test`; pokrývá SPA regrese, které API testy nevidí; běží v CI.
- **Statický security scan `bin/security-scan.sh`.** 13 kontrol (tracked secrets, SQL interpolace, shell exec bez escapeshellarg, nebezpečné funkce, composer/npm audit, perms, .htaccess, CSRF coverage); exit 1 při CRITICAL, `--strict` i při WARNING.
- **Pre-push hook `bin/hooks/pre-push`.** Unit + smoke testy blokují push při selhání.
- **Agent tooling v `.devin/`:** skills `security-review`, `api-design`, `frontend-patterns`, `database-migrations`, `gha-security-review`, `shadcn`, `e2e-testing` a agenti `planner` + `doc-updater`; `reviewer` má novou fázi attack-surface mapping, `researcher` Adopt/Extend/Build matici. MCP konfigurace `.devin/mcp_config.json` (context7, sequential-thinking), klíče v gitignorovaném `.devin/mcp_config.local.json`.

### Changed

- **Sync projektů ignoruje složku `fitness-denik`** (není webový projekt).

### Fixed

- **Restore dohledá starou FS cestu i když options nic neřeknou.** Fallback `LIKE` vzorce míjely cesty `/home/html/` (Webglobe), replace cest se tiše přeskočil a `downloaded_font_files` držela produkční cestu, takže lokální fonty (Kadence) padaly na 404. Nově 3 úrovně detekce (options, Duplicator descriptor, frekvenční scan), WARN při selhání, post-replace sweep doplňující zbylé rooty do replace a fix-upy mažící `downloaded_font_files`, dirsize transient a `wp-content/fonts/*.css`. `logMsg` restore workeru píše do `/var/log/devapppro-restore.log` (cron output se zahazuje, persistentní log chyběl).
- **WP updaty už neselhávají na oříznuté ACL masce.** Root workery (restore, WP instalace) i www-data vytvářely soubory s maskou oříznutou na `r-x`/`r--`, takže www-data ztrácelo zápis. `AclService::normalizeProjectTree` se volá na konci restore i instalace a denní cron `cli/normalize-project-acls.php` normalizaci drží trvale.
- **Klient typu Neziskový/Státní správa už nepadá na HTTP 500.** Enumy `clients.type` a `company_profile.type` rozšířeny o `nonprofit` a `government` (migration_018); dříve INSERT končil `Data truncated`.
- **Sync už nepadá na projektech typu `php`.** Enum `projects.type` rozšířen (migration_017); dříve `Data truncated` zastavil celou synchronizaci včetně dokončení restore.
- **Bootstrap error logger si nehodí vlastní fatal.** `restore_error_handler()` před zápisem do error.log; CLI spuštěné jako `ratesman` už nepadá na právech souboru a výjimka se vypíše na STDERR.
- **Přepínání PHP verzí a regenerace vhostů neshodí nevalidní složka.** Složka s nevalidním hostname (mezera, diakritika) rozbila Include v agregovaném Apache confu a configtest pak padal pro všechny projekty. `isValidFolderName` vyžaduje validní DNS hostname `[a-z0-9-]`, generátor nevalidní složky přeskočí a Include cesty jsou v uvozovkách.
- **Mazání projektu uklidí DB uživatele kompletně.** Smaže se pro `@localhost` i `@127.0.0.1` (restore oba vytváří, `@127.0.0.1` dříve přežíval jako osiřelý); výstup regenerace vhostů se loguje.
- **Restore worker je pozorovatelný.** Výstup generátoru vhostů se loguje a ověřuje se existence vhost souboru; selhání `wp plugin list` loguje varování místo tichého přeskočení deaktivace problematických pluginů; před stavem `completed` proběhne HTTP health check obnoveného webu.
- **URL replace pokrývá JSON-escapované varianty** (`http:\/\/`) v serializovaných option blobech Kadence/Colibri a mapuje obě stará schémata (http i https) na nové https URL; dříve přežívaly `http://` odkazy na původní doménu, mixed content a varování „spojení není bezpečné". `cli/search-replace-db.php` umí i čistou změnu schématu na stejném hostu.
- **Projektové vhosty fungují i přes IPv6.** Bind `*:80`/`*:443` místo `127.0.0.1`; `*.localhost` resolveuje na `::1` a prohlížeče IPv6 preferují, takže Apache poslouchá na obou loopback rodinách.
- **Restore bez `DEVAPPPRO_WP_AUTOLOGIN_SECRET` selže hlasitě.** Dříve tiše zapsal `cli_placeholder` do wp-config.php a autologin pak odmítal tokeny.
- **`wp-config.php` z restore obsahuje `FS_METHOD=direct`.** Soubory po obnově vlastní root, bez direct WP_Filesystem padá na FTP metodu (např. Kadence fatal na `ftp_nlist()`).
- **Hardcoded cesty nahrazeny konstantami.** `/run/media/ratesman/Projekty/` → `PROJECTS_WATCH_DIR` (autologin WP, detekce DB z wp-config, generování vhostů, mazání projektů) a `/var/www/devapppro` → `__DIR__`/`PHP_BINARY` v cli skriptech; na novém stroji všechny tyto funkce tiše selhávaly.
- **Generátor vhostů už nepoužívá `php_admin_value`.** Mod_php direktiva s FPM shodila configtest; limity se zapisují do `.user.ini` v docrootu projektu.
- **Rate limit přihlášení funguje i při rozdílné TZ.** Cutoff se počítá v DB (`NOW() - INTERVAL`), ne PHP `date()`; při rozdílné časové zóně aplikace a DB (CI kontejner v UTC) se limit nikdy neaktivoval.
- **README opraveno:** instalační příkazy DB (chyběl výběr databáze), secrets jsou `SetEnv` ve vhostu (ne `.env`), doplněn hosting timer a `finance-overview` endpoint.

### Security

- **`unserialize()` s `allowed_classes => false`** u nedůvěryhodných dat (serializované bloby z WP dumpů v restore/search-replace, session soubory); zamezuje PHP object injection.
- **Oprávnění `config/config.php` a `config/database.php` snížena na 640.** Dříve 674 zpřístupňovala DB heslo všem lokálním uživatelům.
- **`npm audit fix` odstranil high-severity DoS zranitelnost** v `brace-expansion` (frontend deps).

## [1.0.0] - 2026-10-01

Výchozí dokumentovaný stav, aplikace v produkčním provozu na localhostu:
kompletní CRM (klienti, projekty, úkoly, faktury s PDF/QR platbou, finance,
poznámky, soubory, worklogy, notifikace), restore Duplicator záloh, per-projekt
PHP verze přes FPM, automatické generování vhostů a SSL, WP instalátor,
smazání projektů, sync projektů ze složek.
