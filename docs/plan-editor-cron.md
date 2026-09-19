# Implemetační plán: Editor souborů projektů + Editor cron úloh

## Cíl

Přidat do Dev App Pro dva nové moduly:

1. **Editor souborů projektů** — full editor (včetně PHP) pro úpravu souborů v `folder_path` projektů
2. **Editor cron úloh** — správa naplánovaných úloh s možností testování

## Kontext

- **Lokální vývojové prostředí** — jediný uživatel, bezpečnostní omezení minimální
- **PHP-FPM běží jako `ratesman`** — zápis do `/run/media/ratesman/Projekty/` funguje bez sudo
- **Existující patterns**: DB job tabulky + systemd timer + CLI worker (php_version_jobs, project_hosting_jobs)
- **Existující bezpečnostní funkce**: `is_safe_path()`, `is_symlink_safe()` v `src/helpers.php`

---

## Část A: Editor souborů projektů

### A1. Databáze

Nová tabulka `file_edit_history` (verzování změn):

```sql
CREATE TABLE file_edit_history (
  id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT(10) UNSIGNED NOT NULL,
  file_path VARCHAR(500) NOT NULL,        -- relativní cesta v projektu
  action ENUM('created','modified','deleted','renamed') NOT NULL,
  old_content LONGTEXT NULL,              -- předchozí obsah (pro rollback)
  new_content LONGTEXT NULL,              -- nový obsah
  user_id INT(10) UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  PRIMARY KEY (id),
  KEY idx_project (project_id),
  KEY idx_file (file_path(255)),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Účel**: Audit trail + možnost rollback. Obsah se ukládá do DB (LONGTEXT), ne do gitu (jednodušší, žádné externí závislosti).

### A2. Backend — `FileEditorApiController.php`

**Soubor**: `src/Controllers/FileEditorApiController.php`
**API endpoint**: `api/file-editor.php`

#### Routování

| Metoda | URL | Akce |
|--------|-----|------|
| GET    | `/api/file-editor/projects` | Seznam projektů s `folder_path` |
| GET    | `/api/file-editor/projects/{id}/tree` | Strom souborů projektu |
| GET    | `/api/file-editor/projects/{id}/file?path=...` | Obsah souboru |
| PUT    | `/api/file-editor/projects/{id}/file?path=...` | Uložení souboru |
| POST   | `/api/file-editor/projects/{id}/file?path=...` | Vytvoření souboru |
| DELETE | `/api/file-editor/projects/{id}/file?path=...` | Smazání souboru |
| POST   | `/api/file-editor/projects/{id}/rename` | Přejmenování/přesun |
| GET    | `/api/file-editor/projects/{id}/history` | Historie změn |
| POST   | `/api/file-editor/history/{id}/rollback` | Rollback na předchozí verzi |

#### Bezpečnostní opatření

- **Path traversal**: Použít `is_safe_path($fullPath, $projectRoot)` pro všechny operace
- **Symlink**: Použít `is_symlink_safe()` — blokovat symlinky
- **Zakázané cesty**: `vendor/`, `node_modules/`, `.git/` (čtení ano, zápis ne)
- **Omezení velikosti**: Max 1 MB na soubor (konfigurovatelné v `Constants`)
- **Binární soubory**: Detekce přes `mb_check_encoding()` — pouze textové soubory editovatelné
- **Kódování**: UTF-8 only

#### Strom souborů

```php
function buildFileTree(string $dir, string $baseDir, int $maxDepth = 10): array
{
    // Vrací nested array:
    // [{ name: "index.html", type: "file", size: 48273, modified: "..." },
    //  { name: "assets", type: "dir", children: [...] }]
    //
    // Ignoruje: .git, vendor, node_modules, .DS_Store, Thumbs.db
    // Max 1000 souborů na projekt (ochrana proti obrovským složkám)
}
```

#### Uložení souboru

```php
protected function saveFile(int $projectId): void
{
    $input = json_input();
    $relativePath = $input['path'] ?? '';
    $content = $input['content'] ?? '';

    // 1. Validace projektu
    $project = $repo->find($projectId);
    if (!$project || !$project['folder_path']) {
        $this->jsonError('Projekt nemá složku.', 400);
        return;
    }

    $projectRoot = '/run/media/ratesman/Projekty/' . $project['folder_path'];
    $fullPath = $projectRoot . '/' . ltrim($relativePath, '/');

    // 2. Path traversal kontrola
    if (!is_safe_path($fullPath, $projectRoot)) {
        $this->jsonError('Neplatná cesta.', 400);
        return;
    }

    // 3. Symlink kontrola
    if (file_exists($fullPath) && !is_symlink_safe($fullPath)) {
        $this->jsonError('Symlinky nelze editovat.', 400);
        return;
    }

    // 4. Velikost kontrola
    if (strlen($content) > Constants::MAX_EDITABLE_FILE_SIZE) {
        $this->jsonError('Soubor je příliš velký.', 413);
        return;
    }

    // 5. Binární kontrola (pokud soubor existuje)
    if (file_exists($fullPath) && !isEditable($fullPath)) {
        $this->jsonError('Binární soubory nelze editovat.', 400);
        return;
    }

    // 6. Záloha původního obsahu do file_edit_history
    $oldContent = file_exists($fullPath) ? file_get_contents($fullPath) : null;

    // 7. Vytvořit adresář pokud neexistuje
    $dir = dirname($fullPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    // 8. Zapsat obsah
    file_put_contents($fullPath, $content);

    // 9. Zaznamenat do historie
    $this->recordHistory($projectId, $relativePath, 'modified', $oldContent, $content);

    $this->jsonSuccess(['path' => $relativePath, 'size' => strlen($content)]);
}
```

### A3. Constants

Přidat do `src/Core/Constants.php`:

```php
public const MAX_EDITABLE_FILE_SIZE = 1024 * 1024; // 1 MB
public const MAX_FILE_TREE_ENTRIES = 1000;
public const FILE_EDITOR_IGNORED_DIRS = ['.git', 'vendor', 'node_modules', '.Trash-1000', '$RECYCLE.BIN', 'System Volume Information'];
public const FILE_EDITOR_IGNORED_FILES = ['.DS_Store', 'Thumbs.db'];
```

### A4. Frontend — `FileEditorPage.tsx`

**Soubor**: `frontend/src/pages/FileEditorPage.tsx`
**Route**: `/editor` (přidat do `App.tsx` a `Sidebar.tsx`)

#### Layout

```
┌─────────────────────────────────────────────────────────┐
│  [Projekt ▼]                              [Uložit] [Historie] │
├──────────────┬──────────────────────────────────────────┤
│  Strom       │  Editor (CodeMirror)                      │
│  souborů     │                                          │
│              │  <?php                                    │
│  📁 assets   │    echo "Hello";                          │
│  📁 css      │  ?>                                       │
│  📄 index    │                                          │
│  📄 404      │                                          │
│              │                                          │
│  [+ Nový]    │                                          │
│  [↑ Upload]  │                                          │
└──────────────┴──────────────────────────────────────────┘
```

#### Komponenty

1. **`FileTree.tsx`** — rekurzivní strom souborů
   - Ikony: 📁 složka, 📄 soubor (podle přípony)
   - Klik → načtení souboru do editoru
   - Pravé tlačítko → kontextové menu (přejmenovat, smazat, duplikovat)
   - Lazy loading podsložek (ne načítat vše najednou)
   - Search/filter souborů

2. **`CodeEditor.tsx`** — CodeMirror 6 wrapper
   - Jazyky: PHP, HTML, CSS, JS, JSON, MD, XML, YAML, SQL
   - Téma: light/dark (podle globálního tématu)
   - Line numbers, syntax highlight, auto-indent
   - Klávesová zkratka Ctrl+S → uložit
   - Dirty indicator (změněno/neuloženo)

3. **`FileEditorToolbar.tsx`** — horní lišta
   - Select projektu
   - Cesta aktuálního souboru (breadcrumb)
   - Tlačítka: Uložit, Nový soubor, Nová složka, Upload, Historie
   - Indikátor: "Neuloženo" (červený puntík)

4. **`FileHistoryDialog.tsx`** — dialog s historií změn
   - Seznam změn (datum, uživatel, akce)
   - Diff náhled (předchozí vs. aktuální)
   - Tlačítko Rollback

#### CodeMirror 6 — instalace

```bash
cd frontend
npm install @uiw/react-codemirror @codemirror/lang-php @codemirror/lang-html @codemirror/lang-css @codemirror/lang-javascript @codemirror/lang-json @codemirror/lang-markdown @codemirror/lang-xml @codemirror/lang-yaml @codemirror/lang-sql
```

**Verze**: ověřit před instalací, preferovat stabilní (publikované > 7 dní)

#### Hook: `useFileEditor.ts`

```typescript
export function useProjectFiles(projectId: number | null) {
  // GET /api/file-editor/projects/{id}/tree
  return useQuery({ queryKey: ['file-tree', projectId], ... });
}

export function useFileContent(projectId: number, path: string) {
  // GET /api/file-editor/projects/{id}/file?path=...
  return useQuery({ queryKey: ['file-content', projectId, path], ... });
}

export function useSaveFile(projectId: number) {
  // PUT /api/file-editor/projects/{id}/file?path=...
  return useMutation({ ... });
}

export function useCreateFile(projectId: number) { ... }
export function useDeleteFile(projectId: number) { ... }
export function useRenameFile(projectId: number) { ... }
export function useFileHistory(projectId: number) { ... }
export function useRollbackFile() { ... }
```

---

## Část B: Editor cron úloh

### B1. Databáze

Nová tabulka `cron_jobs`:

```sql
CREATE TABLE cron_jobs (
  id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  description TEXT NULL,
  schedule VARCHAR(100) NOT NULL,         -- cron výraz (*/5 * * * *)
  command TEXT NOT NULL,                    -- příkaz k spuštění
  project_id INT(10) UNSIGNED NULL,        -- volitelná vazba na projekt
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  last_run_at TIMESTAMP NULL,
  last_status ENUM('pending','running','success','failed','timeout') NOT NULL DEFAULT 'pending',
  last_output LONGTEXT NULL,
  last_exit_code INT NULL,
  last_duration_ms INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (id),
  KEY idx_enabled (enabled),
  KEY idx_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Nová tabulka `cron_job_runs` (historie spuštění):

```sql
CREATE TABLE cron_job_runs (
  id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  cron_job_id INT(10) UNSIGNED NOT NULL,
  status ENUM('pending','running','success','failed','timeout') NOT NULL,
  output LONGTEXT NULL,
  exit_code INT NULL,
  duration_ms INT NULL,
  started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  finished_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  KEY idx_job (cron_job_id),
  KEY idx_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### B2. Backend — `CronJobsApiController.php`

**Soubor**: `src/Controllers/CronJobsApiController.php`
**API endpoint**: `api/cron-jobs.php`

#### Routování

| Metoda | URL | Akce |
|--------|-----|------|
| GET    | `/api/cron-jobs` | Seznam úloh |
| GET    | `/api/cron-jobs/{id}` | Detail úlohy |
| POST   | `/api/cron-jobs` | Vytvoření úlohy |
| PUT    | `/api/cron-jobs/{id}` | Úprava úlohy |
| DELETE | `/api/cron-jobs/{id}` | Smazání úlohy |
| POST   | `/api/cron-jobs/{id}/run` | Manuální spuštění (okamžitě) |
| POST   | `/api/cron-jobs/{id}/toggle` | Povolit/zakázat |
| GET    | `/api/cron-jobs/{id}/runs` | Historie spuštění |
| GET    | `/api/cron-jobs/{id}/runs/{runId}` | Detail spuštění (output) |

#### Cron výraz — validace

```php
function validateCronExpression(string $expr): bool
{
    // Podpora: */5 * * * *  nebo  0 2 * * *  nebo  @daily @hourly @reboot
    if (str_starts_with($expr, '@')) {
        return in_array($expr, ['@reboot', '@yearly', '@monthly', '@weekly', '@daily', '@hourly']);
    }
    $parts = preg_split('/\s+/', trim($expr));
    if (count($parts) !== 5) return false;
    // Validace každého pole (minute, hour, day, month, weekday)
    // Povolit: *, */N, N, N-M, N,M, N/M
    foreach ($parts as $i => $part) {
        if (!isValidCronField($part, $i)) return false;
    }
    return true;
}
```

### B3. CLI Worker — `cli/cron-worker.php`

**Soubor**: `cli/cron-worker.php`
**Systemd**: `devapppro-cron-worker.timer` (každých 60s)

#### Logika

```php
// 1. Najít všechny enabled cron_jobs
// 2. Pro každou: zjistit, jestli má být spuštěna (porovnat schedule s aktuálním časem)
// 3. Pokud ano:
//    a. Vytvořit záznam v cron_job_runs (status=running)
//    b. Spustit příkaz přes shell_exec s timeout (60s default, konfigurovatelné)
//    c. Zaznamenat output, exit_code, duration
//    d. Update cron_jobs.last_run_at, last_status, last_output
//    e. Update cron_job_runs (status=success/failed/timeout, finished_at)
```

#### Vyhodnocení cron výrazu

```php
function shouldRunCron(string $schedule, DateTime $now): bool
{
    // @daily → 0 0 * * *
    // @hourly → 0 * * * *
    // */5 * * * * → každých 5 minut
    // Porovnat s aktuálním časem (minute, hour, day, month, weekday)
}
```

#### Systemd timer

```ini
# /etc/systemd/system/devapppro-cron-worker.timer
[Unit]
Description=Dev App Pro - cron worker timer

[Timer]
OnBootSec=30
OnUnitActiveSec=60
AccuracySec=1
Unit=devapppro-cron-worker.service

[Install]
WantedBy=timers.target

# /etc/systemd/system/devapppro-cron-worker.service
[Unit]
Description=Dev App Pro - cron worker
After=network.target

[Service]
Type=oneshot
ExecStart=/usr/bin/php /var/www/devapppro/cli/cron-worker.php --process
User=ratesman
StandardOutput=append:/var/log/devapppro-cron-worker.log
StandardError=append:/var/log/devapppro-cron-worker.log
```

**Poznámka**: Worker běží jako `ratesman` (ne root), protože úlohy jsou lokální. Pokud nějaká úloha potřebuje root, uživatel ji může spustit přes `sudo` v příkazu (má NOPASSWD sudo).

### B4. Manuální spuštění

```php
protected function runNow(int $id): void
{
    $job = $repo->find($id);
    if (!$job) { $this->jsonError('Úloha nenalezena.', 404); return; }

    // Vytvořit run záznam
    $runId = $this->createRun($id);

    // Asynchronně spustit — vytvořit job v DB, worker zpracuje
    // Nebo synchronně (pro malé úlohy):
    $startTime = microtime(true);
    $output = shell_exec($job['command'] . ' 2>&1');
    $duration = (int)((microtime(true) - $startTime) * 1000);

    $this->updateRun($runId, 'success', $output, 0, $duration);
    $this->jsonSuccess(['run_id' => $runId, 'output' => $output]);
}
```

**Bezpečnost**: Příkaz se spouští jako `ratesman` (FPM user). Žádné omezení příkazů — lokální dev.

### B5. Frontend — `CronJobsPage.tsx`

**Soubor**: `frontend/src/pages/CronJobsPage.tsx`
**Route**: `/cron` (přidat do `App.tsx` a `Sidebar.tsx`)

#### Layout

```
┌─────────────────────────────────────────────────────────┐
│  Cron úlohy                              [+ Nová úloha]  │
├─────────────────────────────────────────────────────────┤
│  ┌─────────────────────────────────────────────────────┐│
│  │ Záloha DB          */5 * * * *   ✓ Success  [Run]   ││
│  │   /usr/bin/php /var/www/...backup-db.php            ││
│  │   Poslední: 2026-09-14 14:30 (2.1s, exit 0)         ││
│  ├─────────────────────────────────────────────────────┤│
│  │ Cleanup logs       0 3 * * *      ✓ Success  [Run] ││
│  │   /usr/bin/php /var/www/...cleanup.php              ││
│  │   Poslední: 2026-09-14 03:00 (0.5s, exit 0)         ││
│  └─────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────┘
```

#### Komponenty

1. **`CronJobList.tsx`** — seznam úloh (DataTable)
   - Sloupce: Název, Schedule, Stav, Poslední běh, Akce
   - Akce: Spustit, Upravit, Povolit/Zakázat, Smazat, Historie

2. **`CronJobFormDialog.tsx`** — formulář vytvoření/úpravy
   - Název, Popis, Schedule (cron výraz), Příkaz, Projekt (volitelný)
   - Validace cron výrazu (na frontendu i backendu)
   - Helper: rozbalovací seznam běžných schedule (každá minuta, 5 min, hodina, den)

3. **`CronJobRunDialog.tsx`** — výsledek spuštění
   - Output (stdout/stderr)
   - Exit code, doba trvání
   - Auto-refresh pokud běží

4. **`CronJobHistory.tsx`** — historie spuštění
   - Tabulka: Datum, Stav, Doba, Exit code
   - Klik → detail (output)

#### Hook: `useCronJobs.ts`

```typescript
export function useCronJobs() { ... }
export function useCronJob(id: number) { ... }
export function useCreateCronJob() { ... }
export function useUpdateCronJob(id: number) { ... }
export function useDeleteCronJob() { ... }
export function useRunCronJob(id: number) { ... }
export function useToggleCronJob(id: number) { ... }
export function useCronJobRuns(jobId: number) { ... }
```

---

## Část C: Společné

### C1. Sidebar navigace

Přidat do `frontend/src/components/layout/Sidebar.tsx`:

```typescript
{ to: '/editor', label: 'Editor', icon: FileCode },
{ to: '/cron', label: 'Cron', icon: Clock },
```

### C2. Frontend router

Přidat do `frontend/src/App.tsx`:

```tsx
const FileEditorPage = lazy(() => import('@/pages/FileEditorPage'));
const CronJobsPage = lazy(() => import('@/pages/CronJobsPage'));

<Route path="/editor" element={<Suspense fallback={<PageSkeleton />}><FileEditorPage /></Suspense>} />
<Route path="/cron" element={<Suspense fallback={<PageSkeleton />}><CronJobsPage /></Suspense>} />
```

### C3. API endpointy

Vytvořit `api/file-editor.php` a `api/cron-jobs.php` (thin entry pointy):

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
use DevAppPro\Controllers\FileEditorApiController;
(new FileEditorApiController())->handle();
```

### C4. Constants

Přidat do `src/Core/Constants.php`:

```php
// File editor
public const MAX_EDITABLE_FILE_SIZE = 1024 * 1024;
public const MAX_FILE_TREE_ENTRIES = 1000;
public const FILE_EDITOR_IGNORED_DIRS = ['.git', 'vendor', 'node_modules', '.Trash-1000', '$RECYCLE.BIN', 'System Volume Information'];
public const FILE_EDITOR_IGNORED_FILES = ['.DS_Store', 'Thumbs.db'];

// Cron
public const CRON_DEFAULT_TIMEOUT = 60;
public const CRON_MAX_OUTPUT_SIZE = 1024 * 1024;
```

### C5. Frontend constants

Přidat do `frontend/src/lib/constants.ts`:

```typescript
export const MAX_EDITABLE_FILE_SIZE = 1024 * 1024;
export const CRON_DEFAULT_TIMEOUT = 60;
export const CRON_PRESETS = [
  { label: 'Každá minuta', value: '* * * * *' },
  { label: 'Každých 5 minut', value: '*/5 * * * *' },
  { label: 'Každých 15 minut', value: '*/15 * * * *' },
  { label: 'Každou hodinu', value: '0 * * * *' },
  { label: 'Denně (2:00)', value: '0 2 * * *' },
  { label: 'Týdně (neděle 3:00)', value: '0 3 * * 0' },
  { label: 'Měsíčně (1. den, 4:00)', value: '0 4 1 * *' },
];
```

---

## Implementační fáze

### Fáze 1: Editor souborů (backend)
1. DB tabulka `file_edit_history`
2. `FileEditorApiController.php` (tree, read, save, create, delete, rename, history, rollback)
3. `api/file-editor.php` entry point
4. Constants (limits, ignored dirs/files)
5. PHP syntax check + ruční test přes curl

### Fáze 2: Editor souborů (frontend)
1. Instalace CodeMirror 6 balíčků
2. `useFileEditor.ts` hook
3. `FileTree.tsx` komponenta
4. `CodeEditor.tsx` komponenta (CodeMirror wrapper)
5. `FileEditorPage.tsx` (layout, integrace)
6. `FileHistoryDialog.tsx` (historie + rollback)
7. Route + Sidebar
8. Build + test

### Fáze 3: Cron editor (backend)
1. DB tabulky `cron_jobs`, `cron_job_runs`
2. `CronJobsApiController.php` (CRUD, run, toggle, runs)
3. `api/cron-jobs.php` entry point
4. `cli/cron-worker.php` (worker)
5. Systemd timer + service
6. Cron výraz validace (PHP)
7. PHP syntax check + ruční test

### Fáze 4: Cron editor (frontend)
1. `useCronJobs.ts` hook
2. `CronJobList.tsx` (DataTable)
3. `CronJobFormDialog.tsx` (formulář + cron helper)
4. `CronJobRunDialog.tsx` (output)
5. `CronJobHistory.tsx` (historie)
6. `CronJobsPage.tsx` (layout, integrace)
7. Route + Sidebar
8. Build + test

### Fáze 5: Dokumentace
1. Aktualizace `AGENTS.md` (nové moduly, cesty, příkazy)
2. Aktualizace `docs/revize.md` (nové checklist položky)
3. Aktualizace `docs/opravy.md` (vlna 10)

---

## Soubory k vytvoření

### Backend
- `src/Controllers/FileEditorApiController.php`
- `src/Controllers/CronJobsApiController.php`
- `api/file-editor.php`
- `api/cron-jobs.php`
- `cli/cron-worker.php`
- `database/migrations/010_file_edit_history.sql`
- `database/migrations/011_cron_jobs.sql`
- `database/migrations/012_cron_job_runs.sql`

### Frontend
- `frontend/src/pages/FileEditorPage.tsx`
- `frontend/src/pages/CronJobsPage.tsx`
- `frontend/src/hooks/useFileEditor.ts`
- `frontend/src/hooks/useCronJobs.ts`
- `frontend/src/components/editor/FileTree.tsx`
- `frontend/src/components/editor/CodeEditor.tsx`
- `frontend/src/components/editor/FileEditorToolbar.tsx`
- `frontend/src/components/editor/FileHistoryDialog.tsx`
- `frontend/src/components/cron/CronJobList.tsx`
- `frontend/src/components/cron/CronJobFormDialog.tsx`
- `frontend/src/components/cron/CronJobRunDialog.tsx`
- `frontend/src/components/cron/CronJobHistory.tsx`

### Systém
- `/etc/systemd/system/devapppro-cron-worker.timer`
- `/etc/systemd/system/devapppro-cron-worker.service`

## Soubory k úpravě
- `src/Core/Constants.php` (nové konstanty)
- `frontend/src/lib/constants.ts` (nové konstanty)
- `frontend/src/App.tsx` (2 nové routy)
- `frontend/src/components/layout/Sidebar.tsx` (2 nové položky)
- `frontend/src/types/index.ts` (nové typy)
- `frontend/package.json` (CodeMirror 6 deps)

---

## Závislosti (npm)

```
@uiw/react-codemirror
@codemirror/lang-php
@codemirror/lang-html
@codemirror/lang-css
@codemirror/lang-javascript
@codemirror/lang-json
@codemirror/lang-markdown
@codemirror/lang-xml
@codemirror/lang-yaml
@codemirror/lang-sql
```

Před instalací ověřit: verze publikovaná > 7 dní, stabilní, kompatibilní s React 19.

---

## Odhad složitosti

| Komponenta | Složitost | Poznámka |
|-----------|-----------|----------|
| FileEditor backend | Střední | Path traversal, strom, historie |
| FileEditor frontend | Vysoká | CodeMirror integrace, strom, dirty state |
| CronJobs backend | Střední | Cron parser, worker, timeout |
| CronJobs frontend | Střední | DataTable, formulář, output |
| Systemd timer | Nízká | Stejný pattern jako existující |

---

## Rizika a mitigace

| Riziko | Mitigace |
|--------|----------|
| CodeMirror 6 + React 19 inkompatibilita | Ověřit před instalací, fallback: textarea + PrismJS |
| Velké soubory (minified JS) | Max 1 MB limit, varování v UI |
| Cron příkaz visí | Timeout 60s, status=timeout |
| Path traversal | `is_safe_path()` + `is_symlink_safe()` na všech operacích |
| Soubor změněn externě | ETag/Last-Modified kontrola před uložením (optimistic locking) |
| Cron výraz neplatný | Validace na frontendu i backendu |
