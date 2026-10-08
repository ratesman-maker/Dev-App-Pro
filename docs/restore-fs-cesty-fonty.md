# Restore nenahradil FS cesty → rozbité lokální fonty (Kadence)

> **Stav: OPRAVENO (PR #27)** — post-mortem incidentu z 8. 10. 2026 — projekt
> `zsedvardabenese-vyvoj` (restore Duplicator archivu z Webglobe
> `novyweb.zsedvardabenese.cz`, FS `/home/html/zsedvardabenese.cz/_sub/novyweb`).
> Téma: mezera v `cli/restore-backup.php` — search/replace nepracoval s cestami na disku,
> když je nelze detekovat z options. Důsledek: web běžel na fallback fontech.

---

## 1. Symptom

Po obnově webu se celá stránka renderuje s **fallback fonty** (Poppins a Open Sans se
vůbec nenačtou). V HTML:

```html
<link rel='stylesheet' id='kadence-fonts-gfonts-css'
      href='https://zsedvardabenese-vyvoj.localhost/wp-content/fonts/15ec13f343a8321ee06805db4bc56740.css?ver=1.5.2' />
<link rel="preload" href="/home/html/zsedvardabenese.cz/_sub/novyweb/wp-content//fonts/open-sans/memvYaGs….woff2" as="font" crossorigin>
```

A v generovaném `wp-content/fonts/<hash>.css`:

```css
@font-face {
  font-family: 'Open Sans';
  src: url(/home/html/zsedvardabenese.cz/_sub/novyweb/wp-content//fonts/open-sans/memvYaGs….woff2) format('woff2');
}
```

`url(/home/html/…)` = **absolutní FS cesta ze zdrojového serveru použitá jako URL** →
prohlížeč ji resolvuje jako `https://host/home/html/…` → 404 → žádný font.

## 2. Diagnostika (příkazy pro reprodukci)

```bash
# 1) Obsah generovaných font CSS — hledat FS cesty místo URL
grep -l 'home/html\|/var/www\|/usr/www' wp-content/fonts/*.css

# 2) Site option se sticky mapou remote → lokální cesta
wp option get downloaded_font_files
#   => 'https://fonts.gstatic.com/….woff2' => '/home/html/…/wp-content//fonts/open-sans/….woff2'

# 3) Detekční zdroje restore workeru — u tohoto webu všechny prázdné
wp db query "SELECT option_name, LEFT(option_value,120) FROM wp_options
  WHERE option_name IN ('recently_edited','et_images_temp_folder','upload_path','upload_url_path')"

# 4) Důkaz, že náhrada cest neproběhla VŮBEC (transient pořád drží starou cestu)
wp option get _transient_dirsize_cache | grep -c 'home/html'   # => >0
```

Naměřený stav na `zsedvardabenese-vyvoj` po restore:

| Option | Hodnota |
|---|---|
| `recently_edited` | *(prázdná)* |
| `upload_path` | *(prázdná)* |
| `upload_url_path` | *(prázdná)* |
| `et_images_temp_folder` | neexistuje |
| `siteurl` / `home` | správně `…-vyvoj.localhost` (URL náhrada OK) |
| `downloaded_font_files` | 13× mapa `gstatic → /home/html/…` |
| `_transient_dirsize_cache` | stovky klíčů `/home/html/…` |

## 3. Kořenová příčina — dva nezávislé mechanismy

### 3a. WPTT WebFont_Loader (vendrovaný v `kadence/inc/class-local-gfonts.php`)

1. **`downloaded_font_files`** (site option) = serializovaná mapa
   `remote gstatic URL → absolutní FS cesta`. Ukládá ji `get_local_files_from_css()`.
2. **Sticky path bug:** při regeneraci (`get_local_files_from_css()`, ř. ~405–414) platí:
   soubor existuje **a** klíč je v mapě → `continue` — **stará cesta se nikdy neopraví**,
   i když fyzický soubor leží jinde.
3. **`get_styles()`** převádí cestu na URL přes
   `str_replace( get_base_path(), get_base_url(), $local )` — kde `base_path` =
   `wp_content_dir()` aktuální instalace. Stará cesta `/home/html/…` nezačíná novým
   `wp-content` → replace nic neudělá → FS cesta propadne do `url()`.
4. **Název CSS** = `md5( base_url + base_path + remote_url + format )` → po restore vznikají
   **nové hashe** (proto staré soubory v archivu se správnými URL nepomůžou — neužívají se).
5. Cron `delete_fonts_folder` (monthly) maže celou složku → periodická plná regenerace.

### 3b. Restore worker (`cli/restore-backup.php`)

- Serialized-safe search/replace běží korektně (doména přepsána všude — `siteurl`,
  `home`, post_content i serialized data).
- **Detekce staré FS cesty** ale čte jen tři zdroje: `recently_edited`,
  `et_images_temp_folder`, `upload_path`. U tohoto webu **všechny prázdné/neexistující**
  → replace mapa žádnou cestu neobsahuje → **náhrada cest se tiše přeskočí**.
- Selhání je **tiché** — žádný warning v logu ani UI, že stará cesta nebyla detekována.
- Dostupné zdroje pravdy, které worker nevyužívá:
  - **metadata archivu** — `dup-installer/dup_descriptors_*` obsahují původní wp root
    (standardní Duplicator installer ho předvyplňuje do „Old path"),
  - **scan dumpu** — opakující se absolutní prefix (`/home/…`, `/var/www/…`) lze
    vyextrahovat regexem přímo z SQL.
- Souborová rovina archivu: vygenerovaná CSS z archivu obsahovala URL
  `novyweb.zsedvardabenese.cz` — SR pracuje jen s DB, soubory projdou verbatim.
  (Zde naštěstí irelevantní — nové hashe se stejně generují znovu.)

### Mechanismus jednou větou

> Duplicator restore nepoznal starou FS cestu (prázdné detekční options), takže v DB
> přežila mapa `gstatic → /home/html/…`; Kadence z ní generuje `url()` přes
> `str_replace(wp_content_dir → content_url)`, replace nesedí → FS cesta skončí jako URL
> v CSS i font preloadech → fonty 404 → fallback.

## 4. Provedená oprava (na devu, 8. 10. 2026)

```bash
cd /home/ratesman/projekty/zsedvardabenese-vyvoj
wp option delete downloaded_font_files
rm wp-content/fonts/*.css
# první načtení stránky regeneruje CSS; option se přepíše správnými cestami
```

Funguje, protože loader pak pro každý remote font vezme `file_exists($font_path)` na
**nové** cestě → zapíše `$stored[$url] = $font_path` (aktuální) → `str_replace`
správně převede na `content_url`.

Verifikace: `src: url(https://zsedvardabenese-vyvoj.localhost/wp-content/fonts/…)`,
woff2 → HTTP 200, preload linky správné, `grep home/html wp-content/fonts/*.css` → 0.

> Pozn.: smaže se jen cache (`downloaded_font_files` + hash CSS). woff2 soubory
> v `wp-content/fonts/<family>/` se přegenerují bez downloadu (existují na nové cestě).

## 5. Riziko recurrence — kdy se to stane znovu

- **Každý restore webu s Kadence „load fonts locally"** (nebo čímkoliv, co drží
  absolutní FS cesty v options/transients) na stroj s **jiným docrootem**.
- Stejně postižené cíle: `upload_path` u nestandardních instalací, pluginy cache
  (LiteSpeed, Autoptimize…), `et_*` (Divi) — vždy když je v DB FS cesta a detekce
  ji nepozná.
- Na produkční deploy (dev → Webglobe) se to projeví **obráceně**: cesty
  `/home/ratesman/projekty/…` by přežily → stejný fix zopakovat po importu.

## 6. Implementovaná trvalá oprava (PR #27)

1. **Fallback detekce staré cesty** v `findOldPath()` — tři úrovně:
   a. známé options (jako dosud),
   b. `dup-installer/dup_descriptors_*/archive.txt` →
      `wpInfo.configs.realValues.originalPaths.home` (autoritativní metadata archivu),
   c. frekvenční scan options — nejčastější absolutní root před `/wp-content`.
2. **Warning při selhání detekce** — `WARN:` do `/var/log/devapppro-restore.log`.
   `logMsg()` nově píše i do souboru — cron output se zahazuje, takže žádný
   persistentní restore log dosud neexistoval (proto bylo selhání tiché).
3. **Post-replace sweep (canary)** — `findLeftoverFsRoots()` po replace skenuje
   `options`/`postmeta`/`post_content` na zbylé absolutní rooty ≠ `$newPath`;
   nalezené se doplní do replace mapy a projedou znovu.
4. **Bezpečné cílené fix-upy** po restore (vždy): DELETE `downloaded_font_files`,
   `_transient_dirsize_cache` + timeout, a `wp-content/fonts/*.css` — čistá cache,
   zregeneruje se sama na nové cestě.

Ověřeno E2E na incidentním archivu (`restoretest-fscesty`): `downloaded_font_files`
s novou cestou, font CSS s URL, woff2 200, 0 zbylých `/home/html/` cest.

## 7. Ověřené fakty vs. předpoklady

- **Ověřeno:** option obsahuje starou cestu; CSS s FS cestou; transient drží starou
  cestu; detekční options prázdné; URL náhrada fungovala; fix přes delete option +
  regen ověřen renderem (200 na woff2, správné `src:`).
- **Předpoklad (neověřeno v kódu):** přesný tvar detekce/replace v
  `cli/restore-backup.php` — konkrétní implementaci doplnit při opravě.
