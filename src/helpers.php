<?php
declare(strict_types=1);

/**
 * Pomocné funkce pro Dev App Pro.
 * Načítáno automaticky přes composer.json autoload "files".
 */

if (!function_exists('json_response')) {
    /**
     * Odešle JSON response s HTTP kódem.
     * Pro chybové odpovědi (4xx, 5xx) loguje do PHP error_log se sanitizací citlivých údajů.
     */
    function json_response($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        // Logovat chybové odpovědi (4xx, 5xx) se sanitizací
        if ($status >= 400 && isset($data['error'])) {
            $logMsg = sprintf(
                '[%s] HTTP %d — %s — IP=%s — URI=%s',
                date('Y-m-d H:i:s'),
                $status,
                is_string($data['error']) ? sanitize_for_log($data['error']) : 'unknown error',
                $_SERVER['REMOTE_ADDR'] ?? 'cli',
                $_SERVER['REQUEST_URI'] ?? 'cli'
            );
            error_log($logMsg);
        }

        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('json_input')) {
    /**
     * Načte JSON body z php://input.
     * Vrátí prázdné pole pokud nic nebo neplatný JSON.
     * Limituje velikost na 1 MB.
     * Aplikuje délkové limity na stringová pole a omezuje počet prvků v polích.
     */
    function json_input(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) {
            return [];
        }
        // Limit JSON body na 1 MB (ochrana proti oversized payloads)
        if (strlen($raw) > 1048576) {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        // Aplikovat délkové limity na stringová pole
        return enforce_input_limits($data);
    }
}

if (!function_exists('enforce_input_limits')) {
    /**
     * Aplikuje délkové limity na vstupní data.
     * - Stringová pole: max 65535 znaků (TEXT)
     * - Pole: max 100 prvků
     * - Rekurzivní pro vnořená pole
     */
    function enforce_input_limits(array $data): array
    {
        $result = [];
        $count = 0;
        foreach ($data as $key => $value) {
            if ($count >= 100) {
                break; // Max 100 položek na úrovni
            }
            if (is_string($value)) {
                // Max 65535 znaků pro stringy (TEXT limit)
                $result[$key] = mb_strlen($value) > 65535 ? mb_substr($value, 0, 65535) : $value;
            } elseif (is_array($value)) {
                // Rekurzivně pro vnořená pole, max 100 prvků
                $result[$key] = enforce_input_limits(array_slice($value, 0, 100));
            } else {
                $result[$key] = $value;
            }
            $count++;
        }
        return $result;
    }
}

if (!function_exists('csrf_token')) {
    /**
     * Vygeneruje nebo vrátí CSRF token z session.
     * Uloží do $_SESSION['csrf_token'] a nastaví cookie csrf-token.
     */
    function csrf_token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        // Nastaví cookie pro double-submit (httponly=false, React musí číst)
        setcookie('csrf-token', $_SESSION['csrf_token'], [
            'httponly' => false,
            'samesite' => 'Lax',
            'path' => '/',
        ]);
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_verify')) {
    /**
     * Ověří CSRF token z X-CSRF-Token hlavičky proti $_SESSION['csrf_token'].
     * Double-submit: hlavička == cookie == session token.
     */
    function csrf_verify(): bool
    {
        $sessionToken = $_SESSION['csrf_token'] ?? null;
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        $cookieToken = $_COOKIE['csrf-token'] ?? null;

        if (!$sessionToken || !$headerToken) {
            return false;
        }
        // Hlavička musí odpovídat session tokenu
        if (!hash_equals($sessionToken, $headerToken)) {
            return false;
        }
        // Pokud existuje cookie, musí také odpovídat (double-submit)
        if ($cookieToken !== null && !hash_equals($sessionToken, $cookieToken)) {
            return false;
        }
        return true;
    }
}

if (!function_exists('require_csrf')) {
    /**
     * Volá csrf_verify(), pokud selže → 403 json_response a die.
     */
    function require_csrf(): void
    {
        if (!csrf_verify()) {
            json_response(['error' => 'Neplatný CSRF token.'], 403);
            die;
        }
    }
}

if (!function_exists('client_ip')) {
    /**
     * Vrátí IP klienta (REMOTE_ADDR).
     */
    function client_ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}

if (!function_exists('sanitize_for_log')) {
    /**
     * Odstraní citlivé údaje z log zpráv.
     * Odstraňuje hesla, hashe, emaily, telefony.
     */
    function sanitize_for_log(string $msg): string
    {
        // Odstranění hesel a hashů
        $msg = preg_replace('/password["\']?\s*[:=]\s*["\']?[^"\',}\s]+/i', 'password=***', $msg);
        $msg = preg_replace('/password_hash["\']?\s*[:=]\s*["\']?[^"\',}\s]+/i', 'password_hash=***', $msg);
        // Odstranění emailů
        $msg = preg_replace('/[\w.+-]+@[\w.-]+\.\w+/', '***@***.***', $msg);
        // Odstranění telefonů
        $msg = preg_replace('/\+?\d[\d\s]{8,}\d/', '***', $msg);
        return $msg;
    }
}

if (!function_exists('is_safe_path')) {
    /**
     * Ověří, že cesta je bezpečná (uvnitř baseDir, bez .., bez symlinky).
     * Používá realpath() pro resoluci symlinek a .. v cestě.
     *
     * @param string $path Cesta k ověření (relativní nebo absolutní)
     * @param string $baseDir Base adresář, ve kterém musí cesta zůstat
     * @return bool True pokud je cesta bezpečná
     */
    function is_safe_path(string $path, string $baseDir): bool
    {
        // Explicitní kontrola .. v cestě
        if (strpos($path, '..') !== false) {
            return false;
        }

        // Resolvovat realpath pro detekci symlinek a .. 
        $realBase = realpath($baseDir);
        $realPath = realpath($path);

        if ($realPath === false || $realBase === false) {
            return false;
        }

        // Cesta musí být uvnitř baseDir
        return strpos($realPath, $realBase) === 0;
    }
}

if (!function_exists('is_symlink_safe')) {
    /**
     * Ověří, že cesta není symlink.
     *
     * @param string $path Cesta k ověření
     * @return bool True pokud cesta není symlink
     */
    function is_symlink_safe(string $path): bool
    {
        return !is_link($path);
    }
}

if (!function_exists('start_of_week')) {
    /**
     * Vrátí začátek aktuálního týdne (pondělí) podle FIRST_DAY_OF_WEEK.
     * Pokud je firstDayOfWeek=1 (pondělí), týden začíná pondělím.
     *
     * @param \DateTime|null $date Datum (default = now)
     * @param int $firstDayOfWeek 0 = neděle, 1 = pondělí
     * @return \DateTime Začátek týdne (00:00:00)
     */
    function start_of_week(?\DateTime $date = null, int $firstDayOfWeek = 1): \DateTime
    {
        $date = $date ?? new \DateTime();
        $day = (int) $date->format('w'); // 0 = neděle, 1 = pondělí, ...
        $diff = ($day - $firstDayOfWeek + 7) % 7;
        return (clone $date)->modify("-{$diff} days")->setTime(0, 0, 0);
    }
}

if (!function_exists('start_of_fiscal_year')) {
    /**
     * Vrátí začátek aktuálního fiskálního roku.
     * Pokud je fiscalYearStart='01-01', fiskální rok = kalendářní rok.
     * Pokud je '07-01', fiskální rok začíná 1. července.
     *
     * @param \DateTime|null $date Datum (default = now)
     * @param string $fiscalYearStart MM-DD formát
     * @return \DateTime Začátek fiskálního roku (00:00:00)
     */
    function start_of_fiscal_year(?\DateTime $date = null, string $fiscalYearStart = '01-01'): \DateTime
    {
        $date = $date ?? new \DateTime();
        [$month, $day] = array_map('intval', explode('-', $fiscalYearStart));
        $fiscalStart = new \DateTime($date->format('Y') . '-' . sprintf('%02d-%02d', $month, $day));
        if ($date < $fiscalStart) {
            $fiscalStart = new \DateTime(($date->format('Y') - 1) . '-' . sprintf('%02d-%02d', $month, $day));
        }
        return $fiscalStart->setTime(0, 0, 0);
    }
}
