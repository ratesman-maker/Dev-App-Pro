<?php
declare(strict_types=1);

namespace DevAppPro\Services;

/**
 * Služba pro detekci systémových informací a nástrojů.
 * Poskytuje detect* metody pro zjištění stavu PHP, Apache, MariaDB, Node.js,
 * WP-CLI, mkcert, Composer, SSL certifikátu, disku a cronu.
 */
class SystemInfoService
{
    public function __construct()
    {
    }

    public function detectPhp(): array
    {
        $modules = get_loaded_extensions();
        $keyModules = ['pdo', 'pdo_mysql', 'mbstring', 'openssl', 'curl', 'gd', 'xml', 'zip', 'intl', 'bcmath', 'redis', 'apcu', 'imagick'];
        $loaded = [];
        foreach ($keyModules as $m) {
            $loaded[$m] = extension_loaded($m);
        }
        // OPcache není extension v PHP 8.5 - detekovat přes ini + funkci
        $loaded['opcache'] = (bool) ini_get('opcache.enable') && function_exists('opcache_get_status');
        return [
            'name' => 'PHP',
            'running' => true,
            'version' => PHP_VERSION,
            'sapi' => php_sapi_name(),
            'details' => [
                'SAPI' => php_sapi_name(),
                'Memory limit' => ini_get('memory_limit'),
                'Max execution time' => ini_get('max_execution_time') . 's',
                'Upload max' => ini_get('upload_max_filesize'),
                'Post max' => ini_get('post_max_size'),
            ],
            'modules' => $loaded,
        ];
    }

    public function detectApache(): array
    {
        $running = false;
        $version = null;
        $modules = [];

        // Zkusit systemctl
        $status = @shell_exec('systemctl is-active apache2 2>/dev/null');
        if ($status !== null) {
            $status = trim($status);
            $running = ($status === 'active');
        }

        // Verze
        $verOut = @shell_exec('apache2 -v 2>/dev/null');
        if ($verOut && preg_match('/Apache\/([\d.]+)/', $verOut, $m)) {
            $version = $m[1];
        }

        // Moduly - zkusit apache_get_modules (funguje v mod_php), jinak shell
        if (function_exists('apache_get_modules')) {
            $apacheMods = apache_get_modules();
            $keyMods = ['ssl', 'rewrite', 'headers', 'php', 'deflate', 'expires', 'http2', 'proxy', 'proxy_fcgi'];
            foreach ($keyMods as $mod) {
                foreach ($apacheMods as $loaded) {
                    if (stripos($loaded, $mod) !== false) {
                        $modules[] = $mod;
                        break;
                    }
                }
            }
        } else {
            $modOut = @shell_exec('apache2ctl -M 2>/dev/null');
            if ($modOut) {
                $keyMods = ['ssl', 'rewrite', 'headers', 'php', 'deflate', 'expires', 'http2', 'proxy', 'proxy_fcgi'];
                foreach ($keyMods as $mod) {
                    if (preg_match('/\b' . preg_quote($mod) . '\b/i', $modOut)) {
                        $modules[] = $mod;
                    }
                }
            }
        }

        return [
            'name' => 'Apache',
            'running' => $running,
            'version' => $version,
            'details' => [
                'Status' => $running ? 'Aktivní' : 'Neaktivní',
            ],
            'modules' => $modules,
        ];
    }

    public function detectMariadb(): array
    {
        $running = false;
        $version = null;
        $details = [];

        // Zkusit systemctl
        $status = @shell_exec('systemctl is-active mariadb 2>/dev/null') ?? @shell_exec('systemctl is-active mysql 2>/dev/null');
        if ($status !== null) {
            $status = trim($status);
            $running = ($status === 'active');
        }

        // Verze přes PDO
        try {
            $dbConfig = require __DIR__ . '/../../config/database.php';
            $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['dbname']};charset={$dbConfig['charset']}";
            $pdo = new \PDO($dsn, $dbConfig['username'], $dbConfig['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $version = $pdo->query('SELECT VERSION()')->fetchColumn();
            $details['Databáze'] = $dbConfig['dbname'];
            $details['Host'] = $dbConfig['host'] . ':' . $dbConfig['port'];
            $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
            $details['Tabulek'] = count($tables);
        } catch (\Throwable $e) {
            // Logovat detail chyby, ale uživateli ukázat generickou zprávu
            error_log('SystemInfoService MariaDB detect: ' . $e->getMessage());
            $details['Chyba'] = 'Nelze se připojit k databázi.';
        }

        return [
            'name' => 'MariaDB/MySQL',
            'running' => $running,
            'version' => $version,
            'details' => $details,
        ];
    }

    public function detectNode(): array
    {
        // Node.js crashne z Apache (V8 W^X memory protection) - nelze spouštět.
        // Detekovat verzi z dpkg/apt bez spouštění.
        $nodeVer = null;
        $dpkgOut = @shell_exec('dpkg-query -W -f=\'${Version}\' nodejs 2>/dev/null');
        if ($dpkgOut) {
            // Verze: 22.22.1+dfsg+~cs22.19.15-1ubuntu1 → 22.22.1
            $v = trim($dpkgOut);
            if (preg_match('/^(\d+\.\d+\.\d+)/', $v, $m)) {
                $nodeVer = 'v' . $m[1];
            }
        }
        // Fallback: zkontrolovat existenci binárky
        $nodeBin = null;
        foreach (['/usr/bin/node', '/usr/local/bin/node'] as $bin) {
            if (is_executable($bin)) {
                $nodeBin = $bin;
                break;
            }
        }

        // npm verze z package.json (bez spouštění)
        $npmVer = null;
        $npmPkgPaths = [
            '/usr/share/nodejs/npm/package.json',
            '/usr/local/lib/node_modules/npm/package.json',
            '/home/ratesman/.local/lib/node_modules/npm/package.json',
        ];
        foreach ($npmPkgPaths as $pkg) {
            if (is_file($pkg)) {
                $json = @json_decode(file_get_contents($pkg), true);
                if (is_array($json) && !empty($json['version'])) {
                    $npmVer = $json['version'];
                    break;
                }
            }
        }

        return [
            'name' => 'Node.js',
            'running' => $nodeVer !== null && $nodeBin !== null,
            'version' => $nodeVer,
            'details' => [
                'npm' => $npmVer ?? 'nenalezen',
            ],
        ];
    }

    public function detectWpCli(): array
    {
        $candidates = ['wp', '/home/ratesman/.local/bin/wp', '/usr/local/bin/wp'];
        $ver = null;
        foreach ($candidates as $cmd) {
            $out = @shell_exec(escapeshellarg($cmd) . ' --version 2>/dev/null');
            if ($out) {
                $ver = trim($out);
                break;
            }
        }
        return [
            'name' => 'WP-CLI',
            'running' => $ver !== null,
            'version' => $ver,
            'details' => [],
        ];
    }

    public function detectMkcert(): array
    {
        $candidates = ['mkcert', '/home/ratesman/.local/bin/mkcert', '/usr/local/bin/mkcert'];
        $ver = null;
        foreach ($candidates as $cmd) {
            $out = @shell_exec(escapeshellarg($cmd) . ' -version 2>/dev/null');
            if ($out) {
                $ver = trim($out);
                break;
            }
        }
        $caRoot = @shell_exec(escapeshellarg($candidates[0]) . ' -CAROOT 2>/dev/null');
        $caRoot = $caRoot ? trim($caRoot) : null;
        return [
            'name' => 'mkcert',
            'running' => $ver !== null,
            'version' => $ver,
            'details' => $caRoot ? ['CA root' => $caRoot] : [],
        ];
    }

    public function detectComposer(): array
    {
        // Composer může být v ~/.local/bin, který není v Apache PATH
        $candidates = [
            'composer',
            '/home/ratesman/.local/bin/composer',
            '/usr/local/bin/composer',
            '/usr/bin/composer',
        ];
        $ver = null;
        foreach ($candidates as $cmd) {
            $out = @shell_exec(escapeshellarg($cmd) . ' --version 2>/dev/null');
            if ($out) {
                $ver = trim($out);
                break;
            }
        }
        return [
            'name' => 'Composer',
            'running' => $ver !== null,
            'version' => $ver,
            'details' => [],
        ];
    }

    public function detectSslCert(): array
    {
        $certPath = '/etc/apache2/ssl/localhost.crt';
        if (!file_exists($certPath)) {
            return [
                'name' => 'SSL Certifikát',
                'running' => false,
                'version' => null,
                'details' => ['Cesta' => $certPath, 'Stav' => 'Nenalezen'],
            ];
        }

        $cert = openssl_x509_parse(file_get_contents($certPath));
        if ($cert === false) {
            return [
                'name' => 'SSL Certifikát',
                'running' => false,
                'version' => null,
                'details' => ['Stav' => 'Neplatný'],
            ];
        }

        $validTo = $cert['validTo_time_t'] ?? 0;
        $daysLeft = (int) ceil(($validTo - time()) / 86400);
        $sans = [];
        if (!empty($cert['extensions']['subjectAltName'])) {
            $sanStr = $cert['extensions']['subjectAltName'];
            preg_match_all('/DNS:([^,]+)/', $sanStr, $m);
            $sans = $m[1] ?? [];
        }

        return [
            'name' => 'SSL Certifikát',
            'running' => $daysLeft > 0,
            'version' => null,
            'details' => [
                'Platnost do' => date('j.n.Y', $validTo),
                'Zbývá dní' => (string) $daysLeft,
                'SAN domény' => implode(', ', $sans),
                'Vydavatel' => $cert['issuer']['CN'] ?? $cert['issuer']['O'] ?? '—',
            ],
        ];
    }

    public function detectDisk(): array
    {
        $watchDir = PROJECTS_WATCH_DIR;
        if (!is_dir($watchDir)) {
            return [
                'name' => 'Disk (Projekty)',
                'running' => false,
                'version' => null,
                'details' => ['Cesta' => $watchDir, 'Stav' => 'Adresář neexistuje'],
            ];
        }

        $free = disk_free_space($watchDir);
        $total = disk_total_space($watchDir);
        $used = $total - $free;
        $usedPct = $total > 0 ? round(($used / $total) * 100, 1) : 0;

        return [
            'name' => 'Disk (Projekty)',
            'running' => true,
            'version' => null,
            'details' => [
                'Cesta' => $watchDir,
                'Celkem' => $this->formatBytes($total),
                'Obsazeno' => $this->formatBytes($used) . ' (' . $usedPct . '%)',
                'Volné' => $this->formatBytes($free),
            ],
        ];
    }

    public function detectCron(): array
    {
        $jobs = [];
        $cronDir = '/etc/cron.d';
        if (is_dir($cronDir)) {
            foreach (scandir($cronDir) as $f) {
                if (str_starts_with($f, 'devapppro')) {
                    $jobs[] = $f;
                }
            }
        }
        return [
            'name' => 'Cron (DevAppPro)',
            'running' => count($jobs) > 0,
            'version' => null,
            'details' => [
                'Naplánované joby' => implode(', ', $jobs) ?: 'Žádné',
            ],
        ];
    }

    public function formatBytes(float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    }
}
