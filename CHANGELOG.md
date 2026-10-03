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
