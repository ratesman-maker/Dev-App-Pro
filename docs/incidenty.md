# Zápisy incidentů

Historické post-mortemy. Související: `docs/restore-fs-cesty-fonty.md`,
`docs/acl-masky-projekty.md` (novější incidenty, 10/2026).

---

## Oprava vhost routing (2026-09-14)

### Problém
- Per-project vhosty používaly `<VirtualHost *:80>` a `*:443`
- Hlavní vhost Dev App Pro používá `127.0.0.1:80` a `127.0.0.1:443`
- Apache preferuje konkrétní IP před wildcard, takže se Dev App Pro vhost použil pro všechny domény
- Následek: statické projekty přesměrovány na `/login` (React SPA)

### Oprava
- `cli/generate-vhosts.php`: `*:80` → `127.0.0.1:80`, `*:443` → `127.0.0.1:443`
- `/etc/apache2/sites-available/wildcard-localhost.conf`: stejná oprava
- `DirectoryIndex`: pro statické projekty `index.html index.php`, pro WordPress `index.php index.html`
- `type` z DB se používá pro určení pořadí DirectoryIndex

## Auto-login WordPress — oprava 2026-09-14

### Problém
- WP projekty byly restore ze záloh, ne instalovány přes install-wordpress.php
- MU plugin pro auto-login chyběl ve všech WP instalacích
- DEVAPPPRO_SECRET chyběl ve všech wp-config.php
- Auto-login nemohl fungovat

### Oprava
- MU plugin (`cli/wp-mu-plugin.php`) zkopírován do `wp-content/mu-plugins/devapppro-autologin.php` pro 7 aktivních WP projektů
- `define('DEVAPPPRO_SECRET', '...')` přidáno do `wp-config.php` před `require_once ABSPATH` pro 7 aktivních WP projektů
- Klokner (id=34, archivovaný) vynechán
- Zálohy wp-config.php vytvořeny (.bak.{timestamp})

### Ověření
- detectWpAdminUser: funguje pro všech 7 projektů (čte admina z WP DB)
- Auto-login: 302 redirect na wp-admin pro všech 7 projektů
- WP cookies: 3 cookies (logged_in, sec, sec) po přihlášení pro všech 7 projektů

### WP projekty a admin uživatelé (stav k 14. 9. 2026 — historický, projekty se změnily)
- gympark (id=31): Jan Rohlik
- heartofaristocrat (id=32): Andrea
- hemska (id=33): miroslavbartik
- klokner-novy (id=35): bartimir
- obereggen-anna (id=36): admin4632
- skloprozeny (id=37): admin8328
- zakladniskola (id=38): urona
- klokner (id=34): archivovaný (vynechán)

### Druhá oprava — .htaccess a WP_HOME/WP_SITEURL

**Problém**: Všechny WP projekty měly `.htaccess` nastavený pro subdirectory instalaci:
- `RewriteBase /gympark/` místo `RewriteBase /`
- `RewriteRule . /gympark/index.php [L]` místo `RewriteRule . /index.php [L]`

To způsobovalo, že WordPress přesměrovával na neexistující cesty a auto-login končil na `wp-login.php?reauth=1`.

**zakladniskola** měl navíc `WP_HOME`/`WP_SITEURL` v `wp-config.php` nastavené na `https://localhost/zakladniskola` místo `https://zakladniskola.localhost`.

**Oprava**:
- `.htaccess`: `RewriteBase /` + `RewriteRule . /index.php [L]` pro všech 7 projektů
- `zakladniskola/wp-config.php`: `WP_HOME`/`WP_SITEURL` → `https://zakladniskola.localhost`
- Zálohy vytvořeny

**Ověření**: Všech 7 projektů — 302 na wp-admin, 200 OK, WP cookies, dashboard načten.
