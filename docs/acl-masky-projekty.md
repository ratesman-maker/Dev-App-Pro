# Selhávající WordPress aktualizace po importu projektu — ACL masky (post-mortem)

**Datum:** 7. 10. 2026
**Stav:** trvalá oprava **implementována** (PR #25) — `AclService::normalizeProjectTree` volána na konci restore i WP instalace, denní cron `cli/normalize-project-acls.php` domazává drift. Pozn.: `setfacl -R -m ...:rwX` přidává x i na obyčejné soubory (proti manuálu) a `group::` se dědí z default ACL rodiče s x → implementace používá per-type `find` průchody s `-perm /u=x` místo `X`.
**Závažnost:** střední — blokuje WP core/plugin aktualizace na lokálních kopiích projektů, web se přitom tváří zdravě
**Zasažené projekty:** `zsedvardabenese-vyvoj` (root projektu, mu-plugins, ~990 adresářů ve `wp-content/plugins`), `zsedvardabenese-ostry` (root projektu)

---

## 1. Symptom

WordPress v administraci hlásí dostupnou aktualizaci (WP 7.1.2 → 7.1.3), ale update přes tlačítko **selhává**, aniž by kál doplnil srozumitelnou chybu. Pluginové aktualizace zčásti projdou, zčásti ne. Web se přitom normálně zobrazuje — vše funguje, dokud některý proces (update, upload, install) nepotřebuje **zapsat** do stromu projektu.

## 2. Jak se to diagnostikuje

```bash
# maska na rootu projektu – klíčový indikátor
getfacl -p /home/ratesman/projekty/<projekt>
#   user:www-data:rwx	#effective:r-x     ← zapis je "přidělený", ale maska ho-ořízne
#   mask::r-x                                ← TOHLE je chyba

# rozsah poškození (adresáře se špatnou maskou)
find /home/ratesman/projekty/<projekt> -xdev -type d | while read -r d; do
  m=$(getfacl -p "$d" 2>/dev/null | awk -F: '/^mask::/{print $3}')
  [ "$m" != "rwx" ] && echo "$m  $d"
done

# oprávnění k zápisu (kdo vlastně spouští update)
sudo -u www-data test -w /home/ratesman/projekty/<projekt> && echo "zapis OK" || echo "zapis ODEPREN"
```

Poznatky z měření na `zsedvardabenese-vyvoj` (7. 10., před opravou):

| Oblast | Maska adresářů | Maska souborů |
|---|---|---|
| root projektu (`mkdir 0755` v restore workeru) | `r-x` | — |
| `wp-content/mu-plugins` (`mkdir 0755`) | `r-x` | — |
| `wp-content/plugins/*` (stromy) | převážně `r-x` | `r--` |
| `wp-admin`, `wp-includes`, `wp-content`, `uploads` | `rwx` | `r--` (soubory!) |
| `~/projekty` (rodič) | `rwx`, default ACL | — |

## 3. Kořenová příčina

Kombinace tří faktorů — **žádný z nich sám o sobě není chyba**, dohromady ale vytvoří past:

### 3.1 Úmyslné default ACL na `~/projekty`

`~/projekty` má default ACL (`default:user:www-data:rwx`, `default:user:ratesman:rwx`, `default:mask::rwx`). Důvod: restore workery běží jako **root**, takže projektové soubory jsou root-owned, a bez ACL by k nim nikdo jiný (www-data = Apache/PHP-FPM, ratesman = wp-cli) neměl zápis.

### 3.2 Mechanismus oříznutí masky při vytváření souborů

Když proces vytvoří soubor/adresář uvnitř adresáře s default ACL:

1. Nový objekt dostane **access ACL zkopírovanou z default ACL rodiče** (záznamy www-data/ratesman rwX).
2. **ALE maska** se počítá z módu, který vytvářející proces požadoval (`mode & ~umask`) — maska omezí efektivní práva VŠECH pojmenovaných záznamů.

Výsledek podle módu:

| Vytvářející operace | Požadovaný mód | Skupinové bity → maska | www-data/ratesman efektivně |
|---|---|---|---|
| PHP `mkdir($path, 0755)` (root, umask 022) | 0755 | `r-x` → **mask::r-x** | jen čtení + vstup |
| PHP `chmod(0644)` na souboru | 0644 | `r--` → **mask::r--** | jen čtení |
| `unzip` — adresáře s módy **z archivu** (Duplicator ukládá 0777) | 0777 | `rwx` → mask::rwx | plný zápis ✓ |

Ověřeno experimentálně: `mkdir` v `~/projekty` jako ratesman (umask 002 → 0775) → maska `rwx`; `touch` → maska `rw-`. Mechanismus se tedy chová přesně podle módu vytvářejícího procesu.

### 3.3 Restore worker vytváří soubory jako root s umask 022

`cli/restore-backup.php` (běží jako root přes cron):

- `mkdir($targetRoot, 0755, true)` — **root projektu → maska r-x** (klíčová chyba)
- DupArchive callback `@mkdir($path, 0755, true)` + `@chmod($path, 0644)` na souborech — **soubory → maska r--**
- `mkdir($muDir, 0755, true)` — **mu-plugins → maska r-x**
- `chmod($wpConfigPath, 0640)`, htaccess 0644
- ZIP cesta používá `unzip`, který respektuje módy z archivu (0777) → proto `wp-admin`, `wp-includes`, `wp-content`, `uploads` **fungují** a vypadá to, jako by vše bylo v pořádku

### 3.4 Proč core update selhává, plugin updaty zčásti ne

WP core update (spouští www-data přes admin, `FS_METHOD=direct`):

1. potřebuje zapsat `.maintenance` do rootu projektu → maska `r-x` → **nelze**
2. přepisuje existující soubory přes `copy()` → soubory mají masku `r--` → **nelze**
3. výsledek: „Aktualizace WordPressu se nezdařila"

Plugin updaty: `wp-content/plugins` vzniklo unzipem (maska `rwx`), takže www-data **dokáže smazat a znovu vytvořit pluginový adresář**. Ale nové podadresáře vytváří WP přes `mkdir 0755` → znovu maska `r-x` → **příští update stejného pluginu selže**. Pozorováno: `seo-by-rank-math`, `kadence-blocks`, `kadence-blocks-pro` updatovány 7. 10. ~13:32 jako www-data — jejich stromy mají po updatu masku r-x.

## 4. Operativní oprava (provedeno 7. 10. 2026)

Jeden příkaz na oba projekty (soubory jsou root-owned → vyžaduje sudo):

```bash
sudo setfacl -R -m u:www-data:rwX,u:ratesman:rwX \
  /home/ratesman/projekty/zsedvardabenese-vyvoj \
  /home/ratesman/projekty/zsedvardabenese-ostry
```

- **Velké `X`**: execute jen pro adresáře a soubory, které už execute mají → adresáře dostanou masku `rwx`, soubory `rw-`. Žádný soubor se nestane zbytečně spustitelným.
- Příkaz znovu nastaví pojmenované záznamy a **setfacl přepočítá masku jako sjednocení skupinové třídy** → masky se opraví, nemusí se adresně řešit `m::`.
- Doba běhu: pár sekund na ~20k objektů.

**Verifikace po opravě:**

- masky: root/plugins/mu-plugins/rank-math/kadence → `rwx`; soubory `rw-`/`rwX` ✓
- zápis test jako ratesman: OK ✓
- `wp core update` → **7.1.2 → 7.1.3 úspěšně** ✓
- DB na poslední verzi (61833), homepage i příspěvky HTTP 200 ✓
- `wp core verify-checksums` → Success (jen informativní warningy o ~20 souborech `wp-includes/php-ai-client/*` — pozůstatky staré verze, updater je standardně nemaže, neškodí) ✓

## 5. Co NE — zamítnuté řešení a proč

> `define('FS_CHMOD_DIR', 0775); define('FS_CHMOD_FILE', 0664);` v `wp-config.php`

- **wp-config putuje s webem**: export/import přes Duplicator na ostrý hosting přenese i lokální environmnent hacky. Na produkci je správné standardní `0755/0644`; skupinově zapisovatelné soubory (`0664`) mohou být na sdíleném hostingu bezpečnostní riziko (jiný tenant se stejnou skupinou).
- Problém je **lokálního nastavení stroje** (default ACL + umask root workera), ne WordPressu → fix patří do vrstvy, která ho způsobila (aplikace/filesystem), ne do webu.
- Pro srovnání: `FS_METHOD=direct` z generátoru wp-config je na produkci neutrální (jde o výchozí chování WP, když to filesystem umí) — to se nemění. Měnily by se efektivní módy souborů, což neutrální není.

## 6. Recurence — proč se maska bude znovu ořezávat

Oprava v §4 je jednorázová. **Každý budoucí soubor/adresář vytvořený s `mkdir 0755` / `chmod 0644` si masku znovu ořízne** (WP updater, uploady z adminu jako www-data s umask 022, jakýkoliv root worker bez ohledu na ACL). Bez trvalého řešení se dá očekávat, že se problém vrátí v řádech týdnů až měsíců podle toho, jak moc se projekt updatuje.

## 7. Trvalá oprava v aplikaci (návrh, neimplementováno)

**Kde:** `cli/restore-backup.php` — normalizace ACL jako **poslední souborová operace** restore jobu (až po extrakci, mazání cache, úpravě wp-config a .htaccess), těsně před označením jobu `completed`. Worker běží jako root → ideální místo.

**Co přidat** (konec souborové fáze):

```php
// Normalizace ACL: při tvorbě souborů root workerem (mkdir 0755, chmod 0644,
// umask 022) se maska ACL ořízne na r-x/r-- a www-data/ratesman ztratí zápis
// → selhávají WP updaty. Velké X = execute jen pro adresáře.
$cmd = sprintf(
    'setfacl -R -m u:www-data:rwX,u:ratesman:rwX %s 2>&1',
    escapeshellarg($targetRoot)
);
runCmd($cmd); // selhání jen logovat (warning), restore nesmí kvůli ACL padat
```

**Nutné zabezpečení:**

- Uživatelé `www-data`/`ratesman` mohou na jiném stroji neexistovat → `setfacl` by spadl na „unknown user". Buď předem `getpwnam()` check, nebo — čistěji — **normalizovat jen masku**: `setfacl -R -m m::rwX $targetRoot`. Pozor: neověřeno, zda setfacl akceptuje `X` v pozici masky — před implementací otestovat; pokud ne, zůstat u varianty s explicitními uživateli + exist-checkem.
- Selhání normalizace nesmí shodit celý restore (jinak zbytečně zhoršujeme dostupnost importu) — jen `logMsg()` warning.

**Stejný problém jinde v aplikaci:**

- `cli/install-wordpress.php` — taky root worker vytvářející strom projektu přes `mkdir 0755` → nové WP instalace budou mít identicky rozbité masky
- hosting worker a `change-php-version` worker — pokud vytváří/mění soubory v projektech
- Doporučení: jednotná normalizace na jednom místě (společný helper pro CLI workery), ne copy-paste

**Recurence (§6) — periodická normalizace:**

- Existující denní cron (`/etc/cron.d/devapppro-cleanup`) umí obsáhnout `setfacl` normalizaci všech projektů (~1–2 s/projekt) → masky se samy domaží i po plugin updatech
- Alternativa k cronu: normalizace přidaná do některého z často běžících systemd workerů (5s timery) — ale to je zbytečně časté; denní cron stačí

## 8. Shrnutí jednou větou

Default ACL na `~/projekty` záměrně dává www-data a ratesmanovi zápis, ale **módy souborů tvořených root workerem (0755/0644, umask 022) počítané do ACL masky** efektivně ten zápis oříznou — takže importy Dev App Pro rodí projekty, které WP umí číst, ale neumí updatovat; oprava je `setfacl -R -m u:www-data:rwX,u:ratesman:rwX` a trvale patří do restore workeru jako jeho poslední krok.
