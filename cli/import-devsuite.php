<?php

declare(strict_types=1);

/**
 * Import dat ze staré aplikace DevSuite Pro (SQLite) do Dev App Pro (MariaDB).
 *
 * Použití:
 *   php8.5 cli/import-devsuite.php --src=/cesta/k/extrahovane/zaloze [--dry-run]
 *
 * Očekávaná struktura --src (extrahovaný devsuite_backup_*.tar.gz + worklog/):
 *   devsuite.db   — SQLite DB naplněná z devsuite_db.sql
 *   files/        — klientské soubory files/<old_client_id>/<filename>
 *   worklog/      — worklog přílohy worklog/<project>/<filename>  (v záloze
 *                   není, stáhnout zvlášť z /var/lib/devsuite/worklog)
 *
 * Mapování je záměrně explicitní (old id → new id / slug → project id),
 * protože názvy klientů a slugy projektů se mezi aplikacemi liší.
 * Skript je idempotentní — před každým insertem kontroluje, zda záznam
 * už neexistuje (podle přirozených klíčů), a označuje importované záznamy
 * poznámkou "Importováno z DevSuite".
 *
 * Nepřenáší (v nové appce bez ekvivalentu): invoices, invoice_items,
 * recurring_transactions, accounts, email_*, settings, users.
 *
 * Cílovou DB lze přepnout přes DB_NAME env (dry-run na devapppro_copy).
 */

require_once __DIR__ . '/../bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

// ---------------------------------------------------------------
// Parametry
// ---------------------------------------------------------------
$opts = getopt('', ['src:', 'dry-run', 'help']);
if (isset($opts['help']) || empty($opts['src'])) {
    echo "Použití: php8.5 cli/import-devsuite.php --src=<adresar_zalohy> [--dry-run]\n";
    exit(empty($opts['src']) ? 1 : 0);
}
$srcDir = rtrim((string) $opts['src'], '/');
$dryRun = isset($opts['dry-run']);

$sqliteFile = $srcDir . '/devsuite.db';
$filesDir = $srcDir . '/files';
$worklogDir = $srcDir . '/worklog';
$storageDir = dirname(__DIR__) . '/storage';

foreach ([$sqliteFile, $filesDir] as $required) {
    if (!file_exists($required)) {
        fwrite(STDERR, "Chybí povinný vstup: {$required}\n");
        exit(1);
    }
}
if (!is_dir($worklogDir)) {
    fwrite(STDERR, "VAROVÁNÍ: {$worklogDir} neexistuje — worklog přílohy se nepřenesou.\n");
}

$src = new PDO('sqlite:' . $sqliteFile, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$dst = db();

echo ($dryRun ? '=== DRY RUN ===' : '=== IMPORT ===') . " src={$srcDir} db={$dst->query('SELECT DATABASE()')->fetchColumn()}\n\n";

$stats = ['clients' => 0, 'projects' => 0, 'worklog' => 0, 'attachments' => 0, 'files' => 0, 'fileables' => 0, 'transactions' => 0, 'skipped' => 0];

// ---------------------------------------------------------------
// Mapování klientů: old_id => new_id ('new' = založit z old řádku)
// ---------------------------------------------------------------
// Nové klienty zakládáme z údajů staré DB; typ odvozený od charakteru.
$clientMap = [
    3  => 6,      // Kloknerův ústav → Kloknerův ústav ČVUT v Praze
    23 => 7,      // ZŠ Edvarda Beneše, Opava → ZŠ Edvarda Beneše
    24 => 'new',  // GymPark Chodov
    29 => 3,      // Andrea Prknová
    30 => 2,      // Iva Hemská
    31 => 'new',  // Obereggen Anna (apartmány)
    32 => 'new',  // FNUSA
    33 => 'new',  // Jana Malá (skloprozeny.cz)
    34 => 'new',  // Jiří Zahradník → firma Dortem proti rakovině z.s.
    35 => 9,      // Marie Pavelková → Mluvím anglicky s.r.o.
];
$clientNewType = [
    24 => 'company', 31 => 'company', 32 => 'government',
    33 => 'individual', 34 => 'nonprofit',
];
// old 35 (Marie Pavelková) → doplníme jako kontakt na firmu 9
$clientContactBackfill = [35 => 9];

// ---------------------------------------------------------------
// Mapování projektů: old slug => project_id ('new' = založit archived)
// Slugy se normalizují odstraněním pomlček (mluvim-anglicky ≈ mluvimanglicky)
// ---------------------------------------------------------------
$projectMap = [
    'klokner'                  => 24,  // klok-cvut-cz
    'kloknernovy'              => 24,  // starý+nový web → jeden projekt
    'heartofaristocrat'        => 5,
    'zakladniskola'            => 22,  // default zsedvardabenese-ostry; výjimky níže
    'zakladniskolahtml'        => 10,
    'obereggenanna'            => 19,
    'hemska'                   => 20,
    'mluvimanglicky'           => 15,
    'miroslavbartik'           => 14,
    'global'                   => null,
    'gympark'                  => 'new',
    'skloprozeny'              => 'new',
    // Reálné projekty existující jako složka → resolver přes folder_path
    'dortemprotirakovinehtml'  => 'folder:dortem-proti-rakovine-cz',
    'dortemprotirakovine'      => 'folder:dortem-proti-rakovine-cz', // alias z worklog příloh
    // Pouze deníkové záznamy bez reálného projektu → zůstávají bez projektu
    'akademiefnusa'            => null,
    'fnusahpp'                 => null,
];
// Výjimky: konkrétní záznamy zakladniskola patří do vývojové kopie (21).
// Zprovoznění starého webu (5, 14) → ostry (22); redesign → vyvoj (21).
$zakladniskolaVyvojEntries = [8, 18];

$projectNewMeta = [
    'gympark'    => ['name' => 'GymPark Chodov', 'client' => 24],
    'skloprozeny' => ['name' => 'skloprozeny.cz', 'client' => 33],
];
// Projekty, kterým se po importu nastaví client_id podle client_project
$projectClientBackfill = [
    5  => 3,   // heartofaristocrat → Andrea Prknová
    10 => 7,   // zakladniskola-html → ZŠ Beneše
    21 => 7, 22 => 7, // zsedvardabenese → ZŠ Beneše
    24 => 6,   // klok-cvut-cz → Klokner
    19 => 'client:31', // obereggen → nový klient
    20 => 2,   // ivahemska → Iva Hemská
    15 => 9,   // mluvimanglicky → Mluvím anglicky
];

// ---------------------------------------------------------------
// Kategorie transakcí: [old_category][type] => new enum
// ---------------------------------------------------------------
$txCategoryMap = [
    'web_dev'     => ['income' => 'income_project', 'expense' => 'services'],
    'maintenance' => ['income' => 'income_consulting', 'expense' => 'services'],
    'salary'      => ['income' => 'income_consulting', 'expense' => 'other'],
    'tools'       => ['income' => 'income_consulting', 'expense' => 'software'],
    'other'       => ['income' => 'other', 'expense' => 'other'],
];

function normSlug(string $slug): string
{
    return strtolower(str_replace(['-', '_', ' '], '', trim($slug)));
}

/** Generuje storage cestu YYYY/MM/<uuid>.<ext> a zkopíruje soubor. */
function storeFile(string $srcFile, string $ext, string $createdAt, string $storageDir, bool $dryRun): ?array
{
    $ts = strtotime($createdAt) ?: time();
    $relDir = date('Y', $ts) . '/' . date('m', $ts);
    $uuid = bin2hex(random_bytes(16));
    $storedName = $uuid . '.' . $ext;
    $relPath = $relDir . '/' . $storedName;
    if (!$dryRun) {
        $absDir = $storageDir . '/' . $relDir;
        if (!is_dir($absDir)) {
            mkdir($absDir, 0750, true);
        }
        if (!copy($srcFile, $absDir . '/' . $storedName)) {
            return null;
        }
        chmod($absDir . '/' . $storedName, 0640);
    }
    return ['stored_name' => $storedName, 'storage_path' => $relPath];
}

// ---------------------------------------------------------------
// 1) Klienti — založit chybějící
// ---------------------------------------------------------------
echo "--- Klienti ---\n";
$oldClients = $src->query('SELECT * FROM clients')->fetchAll();
$newClientIds = [];

foreach ($oldClients as $c) {
    $oldId = (int) $c['id'];
    $target = $clientMap[$oldId] ?? null;
    if ($target === null) {
        echo "  PŘESKOČENO klient #{$oldId} {$c['name']} (bez mapování)\n";
        $stats['skipped']++;
        continue;
    }
    if ($target !== 'new') {
        $newClientIds[$oldId] = $target;
        echo "  #{$oldId} {$c['name']} → existing #{$target}\n";
        continue;
    }
    // Založit nového klienta (deduplikace podle jména/firmy)
    $type = $clientNewType[$oldId] ?? 'individual';
    $isCompany = in_array($type, ['company', 'nonprofit', 'government'], true);
    $companyName = $isCompany ? ($c['company'] !== '' ? $c['company'] : $c['name']) : null;
    [$first, $last] = $isCompany ? [null, null] : array_pad(explode(' ', $c['name'], 2), 2, null);

    $existing = null;
    if ($companyName !== null) {
        $st = $dst->prepare('SELECT id FROM clients WHERE company_name = ?');
        $st->execute([$companyName]);
        $existing = $st->fetchColumn();
    } else {
        $st = $dst->prepare('SELECT id FROM clients WHERE first_name = ? AND last_name = ?');
        $st->execute([$first, $last]);
        $existing = $st->fetchColumn();
    }
    if ($existing) {
        $newClientIds[$oldId] = (int) $existing;
        echo "  #{$oldId} {$c['name']} → nalezen existing #{$existing}\n";
        continue;
    }
    if (!$dryRun) {
        $st = $dst->prepare(
            'INSERT INTO clients (type, first_name, last_name, company_name, contact_name,
                contact_email, contact_phone, ico, dic, email, phone, address, note, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $st->execute([
            $type, $first, $last, $companyName,
            $isCompany ? $c['contact_person'] : null,
            $c['email'] ?: null, $c['phone'] ?: null,
            $c['ic'] ?: null, $c['dic'] ?: null,
            $isCompany ? null : ($c['email'] ?: null),
            $isCompany ? null : ($c['phone'] ?: null),
            $c['address'] ?: null,
            'Importováno z DevSuite' . ($c['notes'] !== '' ? ': ' . $c['notes'] : ''),
            $c['created_at'],
        ]);
        $newClientIds[$oldId] = (int) $dst->lastInsertId();
    } else {
        $newClientIds[$oldId] = -$oldId; // placeholder pro dry-run
    }
    $stats['clients']++;
    echo "  #{$oldId} {$c['name']} → NEW ({$type}" . ($companyName ? ", {$companyName}" : '') . ")\n";
}

// Kontakt na existující klienty (Pavelková → Mluvím anglicky)
foreach ($clientContactBackfill as $oldId => $newId) {
    $old = array_values(array_filter($oldClients, fn($r) => (int) $r['id'] === $oldId))[0] ?? null;
    if ($old !== null && !$dryRun) {
        $dst->prepare('UPDATE clients SET contact_name = COALESCE(contact_name, ?) WHERE id = ?')
            ->execute([$old['name'], $newId]);
    }
}

// ---------------------------------------------------------------
// 2) Projekty — založit chybějící (archived, folder_path=NULL)
// ---------------------------------------------------------------
echo "\n--- Projekty ---\n";
$resolvedProjects = [];
$createdProjectIds = [];
foreach ($projectMap as $slug => $target) {
    if ($target === 'new') {
        $meta = $projectNewMeta[$slug] ?? null;
        if ($meta === null) {
            echo "  PŘESKOČENO slug {$slug} (chybí meta)\n";
            $stats['skipped']++;
            continue;
        }
        $clientNewId = $newClientIds[$meta['client']] ?? null;
        $st = $dst->prepare('SELECT id FROM projects WHERE name = ? AND folder_path IS NULL');
        $st->execute([$meta['name']]);
        $existing = $st->fetchColumn();
        if ($existing) {
            $resolvedProjects[$slug] = (int) $existing;
            echo "  {$slug} → nalezen existing #{$existing}\n";
            continue;
        }
        if (!$dryRun) {
            $st = $dst->prepare(
                'INSERT INTO projects (client_id, name, description, status, type, folder_path)
                 VALUES (?, ?, ?, "archived", NULL, NULL)'
            );
            $st->execute([
                $clientNewId, $meta['name'],
                'Importováno z DevSuite (slug: ' . $slug . ')',
            ]);
            $resolvedProjects[$slug] = (int) $dst->lastInsertId();
            $createdProjectIds[] = $resolvedProjects[$slug];
        } else {
            $resolvedProjects[$slug] = -99;
        }
        $stats['projects']++;
        echo "  {$slug} → NEW archived \"{$meta['name']}\" (klient #{$meta['client']})\n";
    } elseif (is_string($target) && str_starts_with($target, 'folder:')) {
        $folder = substr($target, 7);
        $st = $dst->prepare('SELECT id FROM projects WHERE folder_path = ?');
        $st->execute([$folder]);
        $resolvedProjects[$slug] = ($id = $st->fetchColumn()) ? (int) $id : null;
        echo "  {$slug} → folder:{$folder} → #" . var_export($resolvedProjects[$slug], true) . "\n";
    } else {
        $resolvedProjects[$slug] = $target;
    }
}

// Backfill client_id na projektech podle client_project
echo "\n--- client_id na projektech ---\n";
foreach ($projectClientBackfill as $projectId => $clientRef) {
    $newClientId = is_string($clientRef) && str_starts_with($clientRef, 'client:')
        ? ($newClientIds[(int) substr($clientRef, 7)] ?? null)
        : $clientRef;
    if ($newClientId === null || $newClientId < 0) {
        echo "  project #{$projectId}: klient nenalezen, přeskočeno\n";
        continue;
    }
    if (!$dryRun) {
        $dst->prepare('UPDATE projects SET client_id = COALESCE(client_id, ?) WHERE id = ?')
            ->execute([$newClientId, $projectId]);
    }
    echo "  project #{$projectId} → client #{$newClientId} (jen pokud byl NULL)\n";
}

// ---------------------------------------------------------------
// 3) Worklog záznamy + přílohy
// ---------------------------------------------------------------
echo "\n--- Worklog ---\n";
$entries = $src->query('SELECT * FROM log_entries ORDER BY id')->fetchAll();
$entryIdMap = [];

foreach ($entries as $e) {
    $oldId = (int) $e['id'];
    $slug = normSlug((string) $e['project']);
    $projectId = $resolvedProjects[$slug] ?? null;
    if ($slug === 'zakladniskola' && in_array($oldId, $zakladniskolaVyvojEntries, true)) {
        $projectId = 21; // zsedvardabenese-vyvoj
    }
    $clientId = isset($e['client_id']) ? ($newClientIds[(int) $e['client_id']] ?? null) : null;
    if ($clientId !== null && $clientId < 0) {
        $clientId = null; // dry-run placeholder
    }

    // deduplikace: title + created_at
    $st = $dst->prepare('SELECT id FROM worklog_entries WHERE title = ? AND created_at = ?');
    $st->execute([$e['title'], $e['created_at']]);
    $dup = $st->fetchColumn();
    if ($dup) {
        $entryIdMap[$oldId] = (int) $dup;
        echo "  #{$oldId} existuje jako #{$dup} — přeskočeno\n";
        continue;
    }
    if (!$dryRun) {
        $st = $dst->prepare(
            'INSERT INTO worklog_entries
                (user_id, project_id, client_id, category, severity, title, description,
                 hours, is_done, done_at, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $st->execute([
            1, $projectId, $clientId,
            $e['category'], $e['severity'], $e['title'], $e['description'],
            $e['hours'], (int) $e['is_done'], $e['done_at'], $e['created_at'],
        ]);
        $entryIdMap[$oldId] = (int) $dst->lastInsertId();
    } else {
        $entryIdMap[$oldId] = -$oldId;
    }
    $stats['worklog']++;
    $projLabel = $projectId === null ? 'žádný' : '#' . $projectId;
    echo "  #{$oldId} [{$slug}→{$projLabel}] {$e['title']}\n";
}

// Přílohy
echo "\n--- Worklog přílohy ---\n";
$atts = $src->query('SELECT * FROM worklog_attachments ORDER BY id')->fetchAll();
foreach ($atts as $a) {
    $newEntryId = $entryIdMap[(int) $a['entry_id']] ?? null;
    if ($newEntryId === null) {
        echo "  att #{$a['id']}: entry {$a['entry_id']} bez mapování — přeskočeno\n";
        $stats['skipped']++;
        continue;
    }
    $srcFile = $worklogDir . '/' . $a['project'] . '/' . $a['filename'];
    if (!is_file($srcFile)) {
        echo "  att #{$a['id']}: chybí soubor {$srcFile} — přeskočeno\n";
        $stats['skipped']++;
        continue;
    }
    $st = $dst->prepare('SELECT id FROM worklog_attachments WHERE entry_id = ? AND original_name = ?');
    $st->execute([$newEntryId, $a['original_name']]);
    if ($st->fetchColumn()) {
        echo "  att #{$a['id']} {$a['original_name']}: už existuje — přeskočeno\n";
        continue;
    }
    $ext = strtolower(pathinfo($a['original_name'], PATHINFO_EXTENSION));
    $stored = storeFile($srcFile, $ext, $a['uploaded_at'], $storageDir, $dryRun);
    if ($stored === null) {
        echo "  att #{$a['id']}: kopie souboru selhala — přeskočeno\n";
        $stats['skipped']++;
        continue;
    }
    $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) ? 1 : 0;
    if (!$dryRun) {
        $st = $dst->prepare(
            'INSERT INTO worklog_attachments
                (entry_id, original_name, stored_name, mime_type, size_bytes, storage_path, is_image, created_at)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $st->execute([
            $newEntryId, $a['original_name'], $stored['stored_name'],
            $a['mime_type'], (int) $a['size'], $stored['storage_path'], $isImage,
            $a['uploaded_at'],
        ]);
    }
    $stats['attachments']++;
    echo "  att #{$a['id']} {$a['original_name']} → entry #{$newEntryId}\n";
}

// ---------------------------------------------------------------
// 4) Klientské soubory → files + fileables (klient + projekt)
// ---------------------------------------------------------------
echo "\n--- Soubory klientů ---\n";
$cfRows = $src->query('SELECT * FROM client_files ORDER BY id')->fetchAll();
foreach ($cfRows as $f) {
    $newClientId = $newClientIds[(int) $f['client_id']] ?? null;
    $srcFile = $filesDir . '/' . $f['client_id'] . '/' . $f['filename'];
    if (!is_file($srcFile)) {
        echo "  file #{$f['id']}: chybí {$srcFile} — přeskočeno\n";
        $stats['skipped']++;
        continue;
    }
    $st = $dst->prepare('SELECT id FROM files WHERE original_name = ? AND size_bytes = ?');
    $st->execute([$f['original_name'], (int) $f['size']]);
    if ($st->fetchColumn()) {
        echo "  file #{$f['id']} {$f['original_name']}: už existuje — přeskočeno\n";
        continue;
    }
    $ext = strtolower(pathinfo($f['original_name'], PATHINFO_EXTENSION));
    $stored = storeFile($srcFile, $ext, $f['uploaded_at'], $storageDir, $dryRun);
    if ($stored === null) {
        echo "  file #{$f['id']}: kopie selhala — přeskočeno\n";
        $stats['skipped']++;
        continue;
    }
    $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) ? 1 : 0;
    $fileId = null;
    if (!$dryRun) {
        $st = $dst->prepare(
            'INSERT INTO files
                (user_id, original_name, stored_name, mime_type, size_bytes, storage_path, is_image, created_at)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $st->execute([
            1, $f['original_name'], $stored['stored_name'], $f['mime_type'],
            (int) $f['size'], $stored['storage_path'], $isImage, $f['uploaded_at'],
        ]);
        $fileId = (int) $dst->lastInsertId();

        // fileables: klient vždy, projekt pokud slug existuje
        $links = [];
        if ($newClientId !== null && $newClientId > 0) {
            $links[] = ['client', $newClientId];
        }
        $projSlug = normSlug((string) $f['project_slug']);
        $projId = $resolvedProjects[$projSlug] ?? null;
        if ($projId !== null && $projId > 0) {
            $links[] = ['project', $projId];
        }
        // zakladniskola soubory → vyvoj (zálohová faktura + prototyp = redesign)
        if ($projSlug === 'zakladniskola') {
            $links = array_filter($links, fn($l) => $l[0] !== 'project');
            $links[] = ['project', 21];
        }
        $ins = $dst->prepare('INSERT INTO fileables (file_id, entity_type, entity_id) VALUES (?,?,?)');
        foreach ($links as [$etype, $eid]) {
            $ins->execute([$fileId, $etype, $eid]);
            $stats['fileables']++;
        }
    }
    $stats['files']++;
    echo "  file #{$f['id']} {$f['original_name']} → files + fileables (klient #{$newClientId})\n";
}

// ---------------------------------------------------------------
// 5) Transakce
// ---------------------------------------------------------------
echo "\n--- Transakce ---\n";
$txRows = $src->query('SELECT * FROM transactions ORDER BY id')->fetchAll();
foreach ($txRows as $t) {
    $type = $t['type'] === 'expense' ? 'expense' : 'income';
    $amountCents = (int) round(((float) $t['amount']) * 100);
    $category = $txCategoryMap[$t['category']][$type] ?? 'other';
    $newClientId = isset($t['client_id']) ? ($newClientIds[(int) $t['client_id']] ?? null) : null;
    if ($newClientId !== null && $newClientId < 0) {
        $newClientId = null;
    }
    $slug = normSlug((string) ($t['project_slug'] ?? ''));
    $projectId = $resolvedProjects[$slug] ?? null;
    if ($projectId !== null && $projectId < 0) {
        $projectId = null;
    }
    if ($slug === 'zakladniskola') {
        $projectId = 21; // záloha redesignu → vyvoj
    }

    $desc = trim((string) $t['description']);
    $meta = [];
    if ($t['counterparty'] !== '') {
        $meta[] = 'Protistrana: ' . $t['counterparty'];
    }
    if ($t['account'] !== '') {
        $meta[] = 'Účet: ' . $t['account'];
    }
    if ($t['variable_symbol'] !== '') {
        $meta[] = 'VS: ' . $t['variable_symbol'];
    }
    if ($meta !== []) {
        $desc = trim($desc . ($desc !== '' ? ' | ' : '') . implode(' | ', $meta));
    }

    $st = $dst->prepare('SELECT id FROM transactions WHERE transaction_date = ? AND amount_cents = ? AND description = ?');
    $st->execute([$t['payment_date'], $amountCents, $desc]);
    if ($st->fetchColumn()) {
        echo "  tx #{$t['id']}: duplicita — přeskočeno\n";
        continue;
    }
    if (!$dryRun) {
        $st = $dst->prepare(
            'INSERT INTO transactions
                (project_id, client_id, type, amount_cents, category, description, transaction_date, created_at)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $st->execute([
            $projectId, $newClientId, $type, $amountCents, $category, $desc,
            $t['payment_date'], $t['created_at'],
        ]);
    }
    $stats['transactions']++;
    echo "  tx #{$t['id']} {$type} {$amountCents}¢ {$t['payment_date']} → projekt " . var_export($projectId, true) . "\n";
}

// ---------------------------------------------------------------
// Souhrn
// ---------------------------------------------------------------
echo "\n=== Souhrn" . ($dryRun ? ' (DRY RUN)' : '') . " ===\n";
foreach ($stats as $k => $v) {
    printf("  %-12s %d\n", $k, $v);
}
