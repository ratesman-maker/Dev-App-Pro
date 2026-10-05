# Changelog

Všechny významné změny projektu Dev App Pro jsou dokumentovány v tomto souboru.

Formát vychází z [Keep a Changelog](https://keepachangelog.com/cs/1.1.0/),
projekt používá [Semantic Versioning](https://semver.org/lang/cs/).

## Pravidla vedení

- Každá změna do `main` (každý PR) přidá položku do `[Unreleased]` pod příslušnou sekci
- Sekce: `Added` (nové funkce), `Changed` (změny chování), `Fixed` (opravy), `Removed` (odebrané), `Security` (bezpečnost)
- Položky česky, jedna věta: co + proč. Příklad: `- Přihlášení přes e-mail místo uživatelského jména`
- Při release: `[Unreleased]` → `[X.Y.Z] - YYYY-MM-DD`, bump `APP_VERSION` v `config/config.php`, `git tag vX.Y.Z`
- SemVer: MAJOR = breaking změna API/DB, MINOR = nová funkce, PATCH = oprava

## [Unreleased]

### Changed
- Sync projektů ignoruje složku `fitness-denik` (není webový projekt)

### Fixed
- `projects.type` enum rozšířen o `'php'` (migration_017) — jinak sync padal na `Data truncated` a celá synchronizace včetně dokončení restore se zastavila
- Bootstrap error logger: `restore_error_handler()` před zápisem do error.log — CLI spuštěné jako `ratesman` nepadá na právech souboru a výjimka se vypíše na STDERR

### Added
- Playwright E2E testy (`bin/test.sh e2e`): 9 speců (login, chybné heslo, redirect nepřihlášeného, nástěnka, Projekty/Úkoly/Klienti, 404) proti `php -S` na `devapppro_test` — pokrývá SPA regrese, které API testy nevidí; běží v CI
- Statický security scan `bin/security-scan.sh` — 13 kontrol (tracked secrets, SQL interpolace, shell exec bez escapeshellarg, nebezpečné funkce, composer/npm audit, perms, .htaccess, CSRF coverage); exit 1 při CRITICAL, `--strict` i při WARNING
- `.devin/skills/` — projektové znalostní balíčky pro agenty: `security-review`, `api-design`, `frontend-patterns`, `database-migrations`
- `.devin/agents/` rozšířeni o `planner` (implementační plány do `plany/` před většími změnami) a `doc-updater` (docs+changelog po merge); `researcher` má Adopt/Extend/Build rozhodovací matici, `reviewer` novou fázi attack-surface mapping před checklistem
- MCP konfigurace `.devin/mcp_config.json` (context7 pro dokumentaci knihoven, sequential-thinking); API klíče se drží v gitignorovaném `.devin/mcp_config.local.json`
- Skill `.devin/skills/gha-security-review/` — audit GitHub Actions proti pwn request, expression injection a supply-chain; `tests.yml` má nyní `permissions: contents: read` (GITHUB_TOKEN nesmí mít write, který nepotřebuje)
- A11y pravidla ve `frontend-patterns`: povinný `aria-label` u icon-only tlačítek, `role="alert"` u inline chyb, klávesnicová navigace a focus handling
- Skill `.devin/skills/shadcn/` — adaptace oficiálního shadcn-ui skillu (CLI workflow, sémantické barvy, kompozice) navázaná na projektovou sadu komponent; `frontend-patterns` rozšířen o React výkonnostní pravidla distillovaná z Vercel react-best-practices (waterfalls, re-rendery, bundle)
- Skill `.devin/skills/e2e-testing/` — konvence pro psaní a rozšiřování Playwright speců (architektura, `devapppro_test` guard, role-based selektory, `page.route` mockování, `test.step`, debug tooling)

### Security
- `unserialize()` nyní voláno s `allowed_classes => false` u nedůvěryhodných dat (serializované bloby z WP dumpů v restore/search-replace, session soubory) — zamezuje PHP object injection
- Oprávnění `config/config.php` a `config/database.php` snížena na 640 — dříve 674 zpřístupňovala DB heslo všem lokálním uživatelům
- `npm audit fix` — odstraněna high-severity DoS zranitelnost v `brace-expansion` (frontend deps)

### Added
- Detekce typu projektu rozšířena o typ `php` (obsahuje `.php` v kořenu); statické weby mají vhosty bez FPM direktiv, PHP selektor v UI se zobrazuje jen pro `wordpress`/`php` projekty a API `/php-version` ostatní typy odmítá. Sync přepočítává typ při změně obsahu složky

### Fixed
- Přepínání PHP verzí: složka s nevalidním hostname (mezera, diakritika) rozbila Include v agregovaném Apache confu → configtest selhával → změna PHP verze i regenerace vhostů padaly pro všechny projekty. `isValidFolderName` nyní vyžaduje validní DNS hostname `[a-z0-9-]`, generátor vhostů nevalidní složky přeskočí a Include cesty jsou v uvozovkách
- Mazání projektu: DB uživatel se smaže pro `@localhost` i `@127.0.0.1` (restore oba vytváří, `@127.0.0.1` dříve přežíval jako osiřelý); výstup regenerace vhostů se loguje
- Restore worker: výstup generátoru vhostů se loguje a ověřuje se existence vhost souboru; selhání `wp plugin list` loguje varování místo tichého přeskočení deaktivace problematických pluginů; před stavem `completed` se provede HTTP health check obnoveného webu
- URL replace při restore i v `cli/search-replace-db.php` nově pokrývá i JSON-escapované varianty URL (`http:\/\/`), které Kadence/Colibri ukládají do serializovaných option blobů — dříve po restore zůstávaly `http://` odkazy na původní doménu → mixed content a varování "spojení není bezpečné" v prohlížeči

### Fixed
- URL replace při restore mapuje obě stará schémata na nové (vždy https) URL — dříve `http://` přežilo a způsobovalo mixed content s varováním „nezabezpečeno“ v prohlížeči; `search-replace-db.php` umí i čistou změnu schématu na stejném hostu
- Projektové vhosty bindují `*:80`/`*:443` místo `127.0.0.1` — `*.localhost` resolveuje na `::1` a prohlížeče IPv6 preferují; Apache teď poslouchá na obou loopback rodinách
- Restore worker selže hlasitě, když běží bez `DEVAPPPRO_WP_AUTOLOGIN_SECRET` v env — dříve tiše zapsal `cli_placeholder` do wp-config.php a autologin pak odmítal tokeny
- `wp-config.php` z restore obsahuje `FS_METHOD=direct` — soubory po obnově vlastní root, jinak WP_Filesystem padá na FTP metodu a např. Kadence fatal na `ftp_nlist()`
- Hardcoded cesta `/run/media/ratesman/Projekty/` nahrazena konstantou `PROJECTS_WATCH_DIR` (autologin WP, detekce DB z wp-config, generování vhostů, mazání projektů) — na novém stroji všechny tyto funkce tiše selhávaly
- Hardcoded `/var/www/devapppro` nahrazeno `__DIR__`/`PHP_BINARY` v cli skriptech — restore a PHP-version workery negenerovaly vhosty (volání na neexistující cestu tiše selhalo)
- Generátor vhostů už nepoužívá `php_admin_value` (mod_php direktiva, s FPM shodí configtest) — limity se zapisují do `.user.ini` v docrootu projektu


- Rate limit přihlášení: cutoff počítán v DB (`NOW() - INTERVAL`), ne PHP `date()` — při rozdílné TZ aplikace/DB (např. CI kontejner v UTC) se limit nikdy neaktivoval
- README: opraveny instalační příkazy DB (chyběl výběr databáze), secrets jsou `SetEnv` ve vhostu (ne `.env`), doplněn hosting timer a `finance-overview` endpoint

### Added
- Pre-push hook `bin/hooks/pre-push` — unit + smoke testy blokují push při selhání
- Changelog a PR workflow (viz AGENTS.md)

## [1.0.0] - 2026-10-01

Výchozí dokumentovaný stav — aplikace v produkčním provozu na localhostu:
kompletní CRM (klienti, projekty, úkoly, faktury s PDF/QR platbou, finance,
poznámky, soubory, worklogy, notifikace), restore Duplicator záloh, per-projekt
PHP verze přes FPM, automatické generování vhostů a SSL, WP instalátor,
smazání projektů, sync projektů ze složek.
