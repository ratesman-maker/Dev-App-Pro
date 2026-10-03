<?php
declare(strict_types=1);

namespace DevAppPro\Services;

use DevAppPro\Repositories\ProjectRepository;

/**
 * Služba pro automatickou synchronizaci projektů ze složek na disku.
 *
 * Cron jede každou minutu, skenuje PROJECTS_WATCH_DIR.
 * - Nová složka → INSERT projekt (name = název složky, status = active, folder_path = název)
 * - Smazaná složka → projekt se označí jako archived (data zůstanou)
 * - Přejmenování složky → starý projekt archived, nový vytvořen
 * - Skryté složky (začínající .) se ignorují
 * - Symlinky se ignorují
 * - Název složky validován (max 200 znaků, žádné .., žádné /)
 */
class ProjectSyncService
{
    private ProjectRepository $projects;
    private string $watchDir;

    /**
     * @param ProjectRepository $projects Repozitář projektů.
     * @param string             $watchDir Adresář, který se má skenovat.
     */
    public function __construct(ProjectRepository $projects, string $watchDir)
    {
        $this->projects = $projects;
        $this->watchDir = $watchDir;
    }

    /**
     * Zařadí hosting job do fronty pro regeneraci vhostů a SSL.
     */
    private function queueHostingJob(int $projectId, string $action, string $folderPath): void
    {
        $pdo = db();
        $stmt = $pdo->prepare(
            'INSERT INTO project_hosting_jobs (project_id, action, folder_path, status) VALUES (?, ?, ?, "pending")'
        );
        $stmt->execute([$projectId, $action, $folderPath]);
    }

    /**
     * Provede synchronizaci složek s projekty v DB.
     *
     * @return array{created: int, archived: int, errors: array<string>}
     */
    public function sync(): array
    {
        $created = 0;
        $archived = 0;
        $errors = [];

        // Kontrola existence a čitelnosti adresáře
        if (!is_dir($this->watchDir)) {
            $errors[] = "Adresář '{$this->watchDir}' neexistuje.";
            return ['created' => $created, 'archived' => $archived, 'errors' => $errors];
        }

        if (!is_readable($this->watchDir)) {
            $errors[] = "Adresář '{$this->watchDir}' nelze číst.";
            return ['created' => $created, 'archived' => $archived, 'errors' => $errors];
        }

        // Skenuje složky v watch dir
        $entries = @scandir($this->watchDir);
        if ($entries === false) {
            $errors[] = "Nelze načíst obsah adresáře '{$this->watchDir}'.";
            return ['created' => $created, 'archived' => $archived, 'errors' => $errors];
        }

        // Detekce: disk není připojen (mount point existuje ale je prázdný)
        // Nearchivovat projekty — disk může být dočasně odpojen
        $realEntries = array_filter($entries, fn($e) => $e !== '.' && $e !== '..');
        if (empty($realEntries)) {
            $errors[] = "Adresář '{$this->watchDir}' je prázdný — disk pravděpodobně není připojen. Přeskakuji archivaci.";
            return ['created' => $created, 'archived' => $archived, 'errors' => $errors];
        }

        $diskFolders = [];

        foreach ($entries as $entry) {
            // Ignoruj aktuální a nadřazený adresář
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $this->watchDir . DIRECTORY_SEPARATOR . $entry;

            // Ignoruj skryté složky (začínající .)
            if (str_starts_with($entry, '.')) {
                continue;
            }

            // Ignoruj systémové složky (Windows/exFAT) a složky, které nejsou webové projekty
            if (in_array($entry, ['$RECYCLE.BIN', 'System Volume Information', '.devin', 'Zalohy', 'Podklady', 'Dorsen', 'webspecialistacz', 'certs', 'devapppro', 'test', 'knowledge-base', 'Knowledge Base'], true)) {
                continue;
            }

            // Ignoruj symlinky
            if (is_link($fullPath)) {
                continue;
            }

            // Jen adresáře, ne soubory
            if (!is_dir($fullPath)) {
                continue;
            }

            // Validuj název složky
            if (!$this->isValidFolderName($entry)) {
                continue;
            }

            $diskFolders[$entry] = true;

            // Detekce typu projektu
            $projectType = $this->detectType($fullPath);

            // Najdi projekt v DB WHERE folder_path = název
            $existing = $this->projects->findByFolderPath($entry);

            // Pokud neexistuje → INSERT
            if ($existing === null) {
                $this->projects->create([
                    'name'        => $entry,
                    'status'      => 'active',
                    'folder_path' => $entry,
                    'type'        => $projectType,
                ]);
                $created++;
            } else {
                // Odarchivovat pokud projekt existuje ale je archivovaný (disk se vrátil)
                if ($existing['status'] === 'archived') {
                    $this->projects->update((int) $existing['id'], ['status' => 'active']);
                    $archived--; // oprava čítače
                    // Queue hosting job pro regeneraci vhostů a SSL
                    $this->queueHostingJob((int) $existing['id'], 'update', $entry);
                }
                // Aktualizuj typ pokud se změnil (např. přibyl index.php do statického webu)
                if ($projectType !== null && $existing['type'] !== $projectType) {
                    $this->projects->update((int) $existing['id'], ['type' => $projectType]);
                    // Regenerovat vhost - static nemá FPM bloky, php/wordpress ano
                    $this->queueHostingJob((int) $existing['id'], 'update', $entry);
                }
            }
        }

        // Pro projekty v DB s folder_path NOT NULL:
        // Pokud složka neexistuje na disku → UPDATE status = 'archived'
        $dbProjects = $this->projects->allWithFolderPath();
        foreach ($dbProjects as $project) {
            $folderPath = $project['folder_path'];
            if ($folderPath === null) {
                continue;
            }

            // Pokud složka neexistuje na disku a projekt není již archivovaný
            if (!isset($diskFolders[$folderPath]) && $project['status'] !== 'archived') {
                $this->projects->update((int) $project['id'], ['status' => 'archived']);
                $archived++;
            }
        }

        return ['created' => $created, 'archived' => $archived, 'errors' => $errors];
    }

    /**
     * Validuje název složky.
     * - Neprázdný
     * - Max 200 znaků
     * - Žádné '..' (path traversal)
     * - Žádné '/' (path separator)
     */
    public function isValidFolderName(string $name): bool
    {
        if ($name === '') {
            return false;
        }

        if (mb_strlen($name) > 200) {
            return false;
        }

        if (str_contains($name, '..')) {
            return false;
        }

        if (str_contains($name, '/')) {
            return false;
        }

        // Zpětné lomítko (Windows)
        if (str_contains($name, '\\')) {
            return false;
        }

        // Název složky se stává hostname <název>.localhost a názvem Apache confu -
        // musí být validní DNS hostname (jinak rozbije generovaný Apache config)
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $name)) {
            return false;
        }

        return true;
    }

    /**
     * Detekuje typ projektu podle obsahu složky.
     * - WordPress: obsahuje wp-config.php nebo wp-login.php
     * - PHP: obsahuje index.php nebo jakýkoliv .php v kořenu (a není WordPress)
     * - Static: obsahuje index.html (a žádné .php)
     * - null: nelze určit
     */
    public function detectType(string $fullPath): ?string
    {
        if (is_file($fullPath . '/wp-config.php') || is_file($fullPath . '/wp-login.php')) {
            return 'wordpress';
        }
        if (is_file($fullPath . '/index.php') || (glob($fullPath . '/*.php') ?: []) !== []) {
            return 'php';
        }
        if (is_file($fullPath . '/index.html') || is_file($fullPath . '/index.htm')) {
            return 'static';
        }
        return null;
    }
}
