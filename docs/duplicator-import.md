# Import WordPress webu z Duplicator archivu

Postup pro rozbalení `*_archive.zip` (nebo `.daf`) z Duplicator Pro/Lite
a zprovoznění webu na `https://<slug>.localhost` jako projekt Dev App Pro.

## Primární cesta — přes UI (doporučeno)

1. Zálohu umísti do `/run/media/ratesman/Projekty/Zalohy/` (skenovaná složka).
2. V aplikaci otevři **Projekty → Zálohy** (`/projects/backups`).
3. U archivu klikni **„Vytvořit projekt"**, zadej slug (malá písmena,
   číslice, pomlčky — stane se názvem složky i `<slug>.localhost`).
4. Sleduj progress bar — job probíhá na pozadí přes cron worker
   (`cli/restore-backup.php --process-pending`, každou minutu jako root).
   Fáze: `extracting → creating_db → importing_sql → configuring →
   replacing_urls → regenerating_ssl → completed`.
5. Hotovo: web běží na `https://<slug>.localhost`, v detailu projektu
   funguje auto-login do wp-admin.

## CLI varianta

```bash
cd /var/www/devapppro
php cli/import-duplicator.php <cesta_k_archivu> <slug> [--wait]
```

Wrapper ověří archiv a zařadí stejný restore job jako UI (pipeline je
shodná). `--wait` vypisuje průběh statusů až do `completed`/`failed`.
Archiv může být kdekoliv — ale mimo `Zalohy/` se neobjeví v UI seznamu.

Příklad:

```bash
php cli/import-duplicator.php \
    "/run/media/ratesman/Projekty/Zalohy/20260911_obereggenannacom_..._archive.zip" \
    obereggen-anna --wait
```

## Co restore worker dělá (cli/restore-backup.php)

1. **Extrakce** — `.zip` přes unzip, `.daf` přes interní DupArchive knihovnu
   (`src/Libs/DupArchive/`).
2. **SQL dump** — hledá `dup-installer/dup_descriptors_*/db_dumps/*-dump.sql`
   (Pro), případně `database.sql` v kořeni (Lite).
3. **Databáze** — `wp_<slug>` + samostatný DB user `wp_<slug>` s náhodným
   heslem (grant jen na tuto DB).
4. **wp-config.php** — vygeneruje nový: lokální credentials, `table_prefix`
   z `archive.txt`/detekce z dumpu, čerstvé salts, `DEVAPPPRO_SECRET`,
   `WP_HOME`/`WP_SITEURL` na `<slug>.localhost`.
5. **.htaccess** — vytvoří čistý WP .htaccess (pretty permalinks).
6. **Search/replace** — serialization-aware náhrada staré URL (čte ji z
   `wp_options.siteurl` po importu — http i https varianty) a staré
   diskové cesty (detekce z `recently_edited`, `et_images_temp_folder`,
   `upload_path`). E-mailové adresy se nenahrazují.
7. **Font CSS fix** — opraví absolutní cesty v `wp-content/fonts/*.css`
   (Kadence).
8. **Cache cleanup** — smaže obsah `wp-content/et-cache/` a
   `wp-content/cache/`. Kritické: Divi et-cache drží CSS s produkční URL
   ikonního fontu → jinak se ikony vykreslují jako číslice (CORS).
9. **Deaktivace lokálně škodlivých pluginů** — bezpečnostní (BBQ,
   WPS Hide Login, limit-login, Really Simple SSL, Complianz — blokují
   `localhost` v URL / schovávají admin), cache pluginy (drží produkční
   URL), externí správa (ManageWP worker). Duplicator zůstává
   aktivní — používá se pro zálohování.
   SEO a funkční pluginy (WooCommerce, Kadence, Polylang…) zůstávají.
10. **Úklid Duplicator pozůstatků** — `installer.php`,
    `*_installer-backup.php`, `dup-installer/` (obsahuje kompletní dump DB —
    bezpečnostní riziko), vnořené `*_archive.zip`.
11. **SSL + vhost** — mkcert SAN regenerace, `generate-vhosts.php`,
    `apache2ctl configtest` + reload.
12. **Projekt** — `ProjectSyncService` zaregistruje složku jako projekt,
    nastaví `php_version=8.5`, `wp rewrite flush`.

## Ověření po importu

```bash
curl -sk -o /dev/null -w '%{http_code}\n' https://<slug>.localhost/
curl -sk https://<slug>.localhost/ | grep -oP '<title>[^<]+'
```

- HTTP 200 + správný `<title>` = OK.
- Ikony Divi ověř vizuálně (Ctrl+Shift+R — prohlížeč drží cache).
- Log: `sudo tail -f /var/log/devapppro-restore.log`.

## Známé zádrhely

- **Ikony jako číslice** — znamená starou URL v et-cache. Worker cache maže;
  pokud problém přetrvává, smaž `wp-content/et-cache/*` ručně a hard-refresh.
- **Job `failed`** — přečti `error_message` v `backup_restores` nebo restore
  log. Job lze retry: `UPDATE backup_restores SET status='pending' WHERE id=N`.
- **Složka už existuje** — restore odmítne přepsat existující složku
  (UI 409). Smaž ji nebo zvol jiný slug.
- **Multisite** — pipeline není testována na multisite instalacích.
- **`unserialize(): Error at offset`** — opraveno try/catch (bootstrap error
  handler převádí @-potlačená varování na výjimky).
