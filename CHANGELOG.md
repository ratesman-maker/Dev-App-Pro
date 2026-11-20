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

### Added

- **Data ze staré aplikace DevSuite Pro jsou importovaná do Dev App Pro.** Pracovní deník (34 záznamů + 20 příloh), soubory klientů (8 souborů navázaných na klienta i projekt) a finance (13 transakcí) teď žijí v aktuální aplikaci. Jednorázový skript `cli/import-devsuite.php` mapuje SQLite dump na MariaDB schéma: explicitní mapování klientů a projektů, chybějící zakládá jako archivované (`folder_path=NULL`, sync je nemaže), částky převádí Kč na centy, soubory ukládá do `storage/YYYY/MM/uuid.ext`. Záznamy bez ekvivalentu (sirotčí položky faktur, recurring, e-maily) se nepřenášejí. Skript je idempotentní a má `--dry-run`.

### Changed

- **Kód bez chyb ESLintu a bez PHPUnit deprecations.** Testy používají atributy `#[Group]` místo `@group` v doc-comment (PHPUnit 12 je nepodporuje). Frontend: odstraněna `any` (typ metody platby, `api.upload<T>`), závislost `useEffect` v transakcích a `useMemo` pro úlohy obnovy, kontext toastů přesunut do `components/ui/toast-context.ts`. Chování se nemění.
- **Nástěnka se vykreslí rychleji, graf příjmů a výdajů se načte zvlášť.** Graf (recharts) je v `components/dashboard/FinanceAreaChart.tsx` a načítá se líně; hlavní kód nástěnky klesl z 376 kB na 15 kB. Recharts se na nástěnce stáhne i tak, jen později.
- **Sync projektů ignoruje staging složky končící `-fix`.** Pomocné složky `<projekt>-fix` (staging oprav pluginů, rozpracované části) se už nezakládají jako projekty; složky na disku zůstávají nedotčené. Existující řádek `jirijuracek-cz-fix` byl smazán z DB.
- **Restore worker je rozdělený do modulů.** `cli/restore-backup.php` (1021 řádků) má teď hlavní pipeline (470 řádků) a pomocné funkce v `cli/restore/` (`helpers.php`, `sql.php`, `search-replace.php`, `paths.php`, `ssl.php`). Chování se nemění; ověřeno obnovou stejného archivu (146 řádků search/replace, stejné deaktivované pluginy, health check HTTP 200).

### Fixed

- **Restore Duplicator záloh s DB rozdělenou po tabulkách.** Import končil chybou „SQL soubor nebyl nalezen“, protože Duplicator v `dup-installer/dup_descriptors_*/db_dumps/` ukládá dump jako `*.sql.gz` (schema + jedna část na tabulku) a worker hledal jen nekomprimované `.sql`. `findSqlFile()` teď tyto části složí do jednoho `combined-dump.sql` (schema první), který se po importu smaže spolu s `dup-installer/`.
- **Řádky tabulek s otevíráním detailu jdou ovládat klávesnicí.** Řádek na nástěnce (klienti, projekty) dostal `tabIndex` a Enter otevře detail; dříve šel otevřít jen myší.
- **Dialogy a řazení tabulek jsou přístupné klávesnicí a čtečkou obrazovky.** Dialog má `role="dialog"` a `aria-modal`, zavírá se klávesou Esc a drží fokus uvnitř. Sloupce řazení v `DataTable` jsou tlačítka s `aria-sort`, dříve šlo o klikatelné `div`. Vyhledávání klientů má popisek.
- **Vyhledávací, datumová a položkové fáze formulářů mají popisky.** Pole bez popisku (jen `placeholder`) dostala `aria-label`, u položek faktury včetně čísla řádku. Detail klienta/projektu se chová jako dialog (`role="dialog"`, `aria-modal`, Esc). Zóna pro nahrání souborů jde ovládat klávesnicí (`role="button"`, Enter a mezerník).
- **Seznam projektů už neobsahuje fiktivní projekty z DevSuite importu.** Slugs použité jen pro záznamy pracovního deníku (akademiefnusa, fnusahpp) zakládaly prázdné archivované projekty; teď se mapují na NULL a záznam drží vazbu přes klienta. Slug dortemprotirakovine se řeší přes `folder_path` na reálný projekt `dortem-proti-rakovine-cz`. Na live DB opraveno: projekty Akademie FNUSA a Dortem proti rakovině (virtuální) smazány, jejich worklog záznamy přemapovány.

- **Dashboard graf Příjmy/výdaje a karta „Příjmy" započítávají platby faktur.** Oba čerpaly jen z `transactions`, takže při čistě fakturovaných příjmech byl graf prázdný a Příjmy ukazovaly 0 Kč. `finance_series` nově UNIONuje `invoice_payments` po `payment_date` a `total_income_cents` sčítá transakce + platby; sémantika sjednocená s finance-overview.

- **Přepnutí faktury na Zaplaceno započítá částku do statistik.** Status `paid` se nastavoval bez záznamu platby, takže `paid_cents` zůstávalo 0 a „Celkem zaplaceno" fakturu ignorovalo. `ensurePaidPayment()` teď při `store` i `update` doplní platbu na zbývající částku (poznámka „Automaticky při přepnutí na Zaplaceno"); symetricky k existujícímu `recalculatePaid`, který platby → status řešil. Faktura 2026004 dostala nápravnou platbu 150 000 Kč.

- **Editace faktury ze seznamu načte položky a neblokuje změnu statusu.** Editační dialog dostával řádek ze seznamu bez položek (vrací je jen detail), takže ukazoval jednu prázdnou položku a validace bránila submitu; při vyplnění by se skutečné položky přepsaly. Nový sdílený hook `useInvoice(id)` dohoní `GET /api/invoices/{id}` v dialogu i detailu; typ `Invoice.items` je nově optional. Regresní E2E spec `invoices.spec.ts` pokrývá celý tok (vystavení → editace → přepnutí na Zaplaceno).

### Security

- **Hesla k projektovým přístupům se neposílají v seznamu.** `GET /api/projects/{id}/credentials` vrací jen příznak `has_password`; heslo je v detailu `GET /api/project-credentials/{id}` a v UI se načte až na kliknutí (zobrazit, kopírovat). Úprava přístupu bez pole `password` ponechá stávající heslo, dříve se přepsalo prázdným řetězcem.
- **Názvy souborů v hlavičkách stahování jsou bezpečné.** `Content-Disposition` se sestavuje přes `content_disposition()` (uvozovky a lomítka se nahradí, plný název jde v `filename*`). SVG a soubory bez náhledu se servírují jen jako stažení, nikdy inline.
- **Nastavení a profil firmy vyžadují přihlášení a CSRF v dispatchi.** `SettingsApiController` a `CompanyProfileApiController` mají `requireAuth` a `require_csrf` v `handle()`, ne jen v jednotlivých metodách.

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
