#!/usr/bin/env bash
# Statický security scan Dev App Pro.
# Použití:
#   bin/security-scan.sh           - scan, výstup findings, exit 1 při CRITICAL
#   bin/security-scan.sh --strict  - exit 1 i při WARNING
#
# Kontroly: tracked secrets, hardcoded secrets, SQL interpolace, shell exec,
# unserialize, getMessage v response, eval/dangerous funkce, frontend XSS,
# debug pozůstatky, oprávnění configu, composer/npm audit.
# Detailní checklist: .devin/skills/security-review/SKILL.md
set -uo pipefail
cd "$(dirname "$0")/.."

STRICT=0
[ "${1:-}" = "--strict" ] && STRICT=1

CRITICAL=0
WARNING=0

crit() { CRITICAL=$((CRITICAL+1)); echo "  [CRITICAL] $1"; }
warn() { WARNING=$((WARNING+1)); echo "  [WARNING] $1"; }
info() { echo "  [INFO] $1"; }
section() { echo; echo "=== $1 ==="; }

# --- 1. Secrets v git tracked souborech ---
section "Secrets v tracked souborech"
for f in config/config.php config/database.php .env; do
    git ls-files --error-unmatch "$f" >/dev/null 2>&1 && crit "$f je trackován v gitu!"
done
TRACKED_LEAKS=$(git ls-files | grep -iE '(^|/)(id_rsa|id_ed25519|id_dsa|id_ecdsa)(\.pub)?$|\.(pem|p12|pfx|htpasswd|netrc)$|\.env(\.|$)|credentials\.(json|yml|yaml|xml|ini|txt)$|secrets\.(json|yml|yaml|php)$' || true)
if [ -n "$TRACKED_LEAKS" ]; then
    while IFS= read -r f; do crit "suspektní tracked soubor: $f"; done <<< "$TRACKED_LEAKS"
fi
[ "$CRITICAL" -eq 0 ] && info "žádné config/.env/pem v gitu"

# --- 2. Hardcoded secrets v kódu ---
section "Hardcoded secrets (string literály)"
# Přiřazení hesla/secretu/tokenu string literálem (ne getenv, ne konstanta)
HITS=$(grep -rnE "(password|passwd|secret|api_?key|token)[\"']?\s*(=>|=)\s*['\"][^'\"]{8,}['\"]" src/ api/ cli/ --include='*.php' \
    | grep -vE "getenv|password_hash|password_verify|csrf|token\"\s*=>\s*null|'password'\s*=>|\*+" || true)
if [ -n "$HITS" ]; then
    while IFS= read -r l; do warn "$l"; done <<< "$HITS"
else
    info "žádné obvious literály"
fi

# --- 3. SQL: query()/exec() s interpolací ---
section "SQL interpolace (query/exec)"
# CRITICAL: interpolace superglobálů nebo $data z inputu
HITS=$(grep -rnE '\->(query|exec)\([^)]*(\$_GET|\$_POST|\$_REQUEST|\$data\[)' src/ api/ cli/ --include='*.php' || true)
if [ -n "$HITS" ]; then
    while IFS= read -r l; do crit "input v SQL: $l"; done <<< "$HITS"
fi
# WARNING: jakákoliv jiná {$var} interpolace — ověřit whitelist/interní zdroj
HITS=$(grep -rnE '\->(query|exec)\([^)]*\{\$' src/ api/ cli/ --include='*.php' \
    | grep -vE '\$_GET|\$_POST|\$_REQUEST|\$data\[' || true)
if [ -n "$HITS" ]; then
    while IFS= read -r l; do warn "interpolace — ověřit whitelist: $l"; done <<< "$HITS"
fi

# --- 4. Shell exec ---
section "Shell exec (shell_exec/exec/system/passthru/proc_open)"
HITS=$(grep -rnE 'shell_exec|[^a-zA-Z_>-]exec\s*\(|[^a-zA-Z_]system\(|passthru\(|popen\(|proc_open\(' src/ api/ cli/ --include='*.php' || true)
if [ -n "$HITS" ]; then
    # CRITICAL: superglobály přímo v exec řádku
    BAD=$(echo "$HITS" | grep -E '\$_GET|\$_POST|\$_REQUEST' || true)
    if [ -n "$BAD" ]; then
        while IFS= read -r l; do crit "input v shellu: $l"; done <<< "$BAD"
    fi
    # WARNING: dynamický obsah bez escapeshellarg na stejném řádku (ověřit build příkazu)
    SUS=$(echo "$HITS" | grep -F '$' | grep -vE 'escapeshellarg|escapeshellcmd' || true)
    if [ -n "$SUS" ]; then
        while IFS= read -r l; do warn "exec — ověřit escapeshellarg v buildu příkazu: $l"; done <<< "$SUS"
    fi
else
    info "žádné exec volání"
fi

# --- 5. unserialize ---
section "unserialize (object injection)"
HITS=$(grep -rn 'unserialize(' src/ api/ cli/ --include='*.php' | grep -v "allowed_classes" || true)
if [ -n "$HITS" ]; then
    while IFS= read -r l; do crit "unserialize bez allowed_classes => false: $l"; done <<< "$HITS"
else
    info "všechny unserialize s allowed_classes"
fi

# --- 6. Citlivé údaje v odpovědích ---
section "Interní chyby v response"
HITS=$(grep -rnE "json_(response|error).*(getMessage|getTrace)|'error'\s*=>.*getMessage" src/ api/ --include='*.php' || true)
if [ -n "$HITS" ]; then
    while IFS= read -r l; do crit "interní chyba do response: $l"; done <<< "$HITS"
else
    info "žádné getMessage/getTrace v response"
fi

# --- 7. Nebezpečné funkce ---
section "Nebezpečné PHP funkce"
HITS=$(grep -rnE '\beval\(|\bassert\(.*\$|create_function|preg_replace.*/e['\''"]' src/ api/ cli/ --include='*.php' || true)
if [ -n "$HITS" ]; then
    while IFS= read -r l; do crit "$l"; done <<< "$HITS"
else
    info "žádné eval/assert/create_function/preg_replace-e"
fi

# --- 8. Frontend XSS povrch ---
section "Frontend (dangerouslySetInnerHTML / innerHTML / localStorage)"
HITS=$(grep -rn 'dangerouslySetInnerHTML' frontend/src/ | grep -vE '^\s*//|//.*dangerouslySetInnerHTML' || true)
if [ -n "$HITS" ]; then
    while IFS= read -r l; do warn "$l"; done <<< "$HITS"
fi
HITS=$(grep -rnE 'innerHTML\s*=[^=]' frontend/src/ || true)
if [ -n "$HITS" ]; then
    while IFS= read -r l; do crit "innerHTML přiřazení: $l"; done <<< "$HITS"
fi
HITS=$(grep -rnE 'localStorage\.(setItem|getItem).*(token|password|secret)' frontend/src/ || true)
if [ -n "$HITS" ]; then
    while IFS= read -r l; do warn "citlivá data v localStorage: $l"; done <<< "$HITS"
fi
info "frontend scan hotov"

# --- 9. Debug pozůstatky ---
section "Debug pozůstatky"
HITS=$(grep -rnE 'var_dump\(|print_r\(|phpinfo\(|die\(.*var_dump' src/ api/ --include='*.php' || true)
if [ -n "$HITS" ]; then
    while IFS= read -r l; do warn "$l"; done <<< "$HITS"
fi
HITS=$(grep -rn 'console\.log' frontend/src/ | grep -v '\.test\.\|\.spec\.' || true)
if [ -n "$HITS" ]; then
    CNT=$(echo "$HITS" | wc -l)
    warn "console.log ve frontend/src ($CNT výskytů)"
fi
info "debug scan hotov"

# --- 10. Oprávnění a .htaccess ---
section "Oprávnění a .htaccess"
for f in config/config.php config/database.php; do
    if [ -f "$f" ]; then
        PERM=$(stat -c %a "$f")
        if [ "$PERM" -gt 640 ] 2>/dev/null; then warn "$f má perm $PERM (doporučeno 640)"; fi
    fi
done
for d in config src vendor cli; do
    grep -q "^\s*RewriteRule \^$d/" .htaccess || warn ".htaccess neblokuje přístup k $d/"
done
if [ -f storage/.htaccess ]; then
    grep -qiE 'denied|deny' storage/.htaccess || warn "storage/.htaccess neobsahuje deny pravidlo"
elif grep -q 'RewriteRule \^storage/' .htaccess; then
    :
else
    warn "storage/ není blokováno (ani v root .htaccess, ani storage/.htaccess)"
fi
grep -qF '..' .htaccess 2>/dev/null || warn ".htaccess neblokuje '..' v URL"
info "perm/.htaccess hotov"

# --- 11. Controllery bez CSRF ---
section "Controllery bez require_csrf (ověřit: GET-only nebo auth)"
HITS=$(grep -rL 'require_csrf' src/Controllers/*ApiController.php 2>/dev/null || true)
if [ -n "$HITS" ]; then
    while IFS= read -r l; do info "$l"; done <<< "$HITS"
fi

# --- 12. Dependency audit ---
section "composer audit"
if command -v composer >/dev/null 2>&1 && [ -f composer.lock ]; then
    if ! composer audit --no-interaction 2>&1; then
        crit "composer audit nalezl zranitelnosti"
    fi
else
    info "composer/lock nedostupný — skip"
fi

section "npm audit (frontend)"
if [ -f frontend/package-lock.json ] && command -v npm >/dev/null 2>&1; then
    if ! (cd frontend && npm audit --audit-level=high 2>&1 | tail -15); then
        crit "npm audit nalezl high/critical zranitelnosti"
    fi
else
    info "npm/lockfile nedostupný — skip"
fi

# --- 13. Psalm taint analysis (volitelné) ---
section "Psalm taint-analysis"
if [ -f psalm.xml ]; then
    PSALM=$(command -v psalm || ls ~/.local/bin/psalm /usr/local/bin/psalm 2>/dev/null | head -1 || true)
    if [ -n "$PSALM" ]; then
        "$PSALM" --taint-analysis --no-cache || warn "psalm taint-analysis nalezl findings"
    else
        info "psalm.xml existuje, ale psalm není v PATH"
    fi
else
    info "psalm.xml chybí — skip (viz skill sekce Statická analýza)"
fi

# --- Souhrn ---
echo
echo "=============================="
echo "CRITICAL: $CRITICAL | WARNING: $WARNING"
if [ "$CRITICAL" -gt 0 ]; then
    echo "VÝSLEDEK: FAIL (critical findings)"
    exit 1
fi
if [ "$STRICT" -eq 1 ] && [ "$WARNING" -gt 0 ]; then
    echo "VÝSLEDEK: FAIL (--strict, warnings present)"
    exit 1
fi
echo "VÝSLEDEK: OK"
exit 0
