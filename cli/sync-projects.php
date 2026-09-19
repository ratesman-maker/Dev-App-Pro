<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use DevAppPro\Services\ProjectSyncService;
use DevAppPro\Repositories\ProjectRepository;

$service = new ProjectSyncService(new ProjectRepository(), PROJECTS_WATCH_DIR);
$result = $service->sync();

echo date('Y-m-d H:i:s') . " Sync: created={$result['created']}, archived={$result['archived']}" . PHP_EOL;

if (!empty($result['errors'])) {
    foreach ($result['errors'] as $error) {
        echo "ERROR: $error" . PHP_EOL;
    }
    exit(1);
}
