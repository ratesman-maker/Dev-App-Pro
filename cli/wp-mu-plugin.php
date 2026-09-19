<?php
/**
 * Dev App Pro - Auto Login mu-plugin
 *
 * Umožňuje automatické přihlášení do WP administrace přes token generovaný Dev App Pro.
 * Token: base64(username|timestamp|HMAC-SHA256(username|timestamp, secret))
 * Token je platný 5 minut.
 */
if (!defined('ABSPATH')) {
    return;
}

// Secret pro validaci tokenů - nastaví se při instalaci WordPressu
if (!defined('DEVAPPPRO_SECRET')) {
    return;
}

add_action('init', function () {
    if (!isset($_GET['devapppro_login'])) {
        return;
    }

    $token = $_GET['devapppro_login'];
    if (!is_string($token) || $token === '') {
        return;
    }

    // Dekódovat token
    $decoded = base64_decode($token, true);
    if ($decoded === false) {
        wp_die('Neplatný token pro přihlášení.');
    }

    $parts = explode('|', $decoded);
    if (count($parts) !== 3) {
        wp_die('Neplatný token pro přihlášení.');
    }

    [$username, $timestamp, $signature] = $parts;

    // Validovat timestamp (5 minut)
    $now = time();
    $ts = (int) $timestamp;
    if ($ts <= 0 || abs($now - $ts) > 300) {
        wp_die('Token vypršel. Vygenerujte nový z Dev App Pro.');
    }

    // Validovat podpis
    $expected = hash_hmac('sha256', $username . '|' . $timestamp, DEVAPPPRO_SECRET);
    if (!hash_equals($expected, $signature)) {
        wp_die('Neplatný podpis tokenu.');
    }

    // Najít uživatele
    $user = get_user_by('login', $username);
    if (!$user) {
        wp_die('Uživatel neexistuje.');
    }

    // Přihlásit
    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, true);

    // Redirect na wp-admin
    wp_safe_redirect(admin_url());
    exit;
});

/**
 * Localhost kompatibilita: Block Bad Queries (BBQ Firewall) blokuje query
 * stringy obsahující "localhost" a "127.0.0.1" (anti-SSRF pattern).
 * Na *.localhost doméně tím rozbíjí legitimní WP flow
 * (wp-admin → wp-login.php?redirect_to=https://xxx.localhost/...).
 * Filtr tyto dva patterny z BBQ pravidel odstraní.
 */
add_filter('query_string_items', function (array $items): array {
    return array_values(array_diff($items, ['localhost', '127\\.0\\.0\\.1']));
});
