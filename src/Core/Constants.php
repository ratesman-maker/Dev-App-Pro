<?php
declare(strict_types=1);

namespace DevAppPro\Core;

/**
 * Centralizované konstanty aplikace.
 * Zabraňuje magickým hodnotám rozptýleným v kódu.
 */
final class Constants
{
    /** Výchozí počet položek na stránku pro API seznamy. */
    public const DEFAULT_PER_PAGE = 15;

    /** Maximální počet položek na stránku pro API seznamy. */
    public const MAX_PER_PAGE = 100;

    /** Maximální velikost nahrávaného souboru v bajtech (10 MB). */
    public const MAX_UPLOAD_SIZE = 10 * 1024 * 1024;

    /** Maximální velikost JSON body v bajtech (1 MB). */
    public const MAX_JSON_BODY_SIZE = 1024 * 1024;

    /** Délka CSRF tokenu v bajtech. */
    public const CSRF_TOKEN_LENGTH = 32;

    /** Délka UUID v4 v hexadecimálním formátu. */
    public const UUID_LENGTH = 36;

    /** Výchozí hashovací cost pro bcrypt. */
    public const BCRYPT_COST = 12;

    /** Maximální počet neúspěšných login pokusů za hodinu. */
    public const LOGIN_RATE_LIMIT = 5;

    /** Okno pro rate limiting v sekundách (1 hodina). */
    public const RATE_LIMIT_WINDOW = 3600;

    /** Doba, po kterou se uchovávají login_attempts (24 hodin). */
    public const LOGIN_ATTEMPTS_RETENTION = 86400;

    /** Thumbnail velikost v pixelech. */
    public const THUMBNAIL_SIZE = 200;

    /** Medium náhled velikost v pixelech. */
    public const MEDIUM_SIZE = 800;

    /** Kvalita WebP komprese (0-100). */
    public const WEBP_QUALITY = 82;

    /** Povolené přípony souborů. */
    public const ALLOWED_FILE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg', 'pdf', 'txt', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'zip'];

    /** Zakázané přípony souborů. */
    public const FORBIDDEN_FILE_EXTENSIONS = ['exe', 'sh', 'bat', 'php', 'phtml', 'js', 'html', 'htm', 'htaccess'];

    /** Typy klientů. */
    public const CLIENT_TYPES = ['individual', 'company', 'nonprofit', 'government'];

    /** Statusy projektů. */
    public const PROJECT_STATUSES = ['active', 'on_hold', 'completed', 'cancelled', 'archived'];

    /** Statusy úkolů. */
    public const TASK_STATUSES = ['todo', 'in_progress', 'done', 'cancelled'];

    /** Priority úkolů. */
    public const TASK_PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    /** Statusy faktur. */
    public const INVOICE_STATUSES = ['draft', 'sent', 'paid', 'overdue', 'cancelled'];

    /** Metody plateb. */
    public const PAYMENT_METHODS = ['cash', 'bank_transfer', 'card', 'other'];

    /** Typy transakcí. */
    public const TRANSACTION_TYPES = ['income', 'expense'];

    /** Kategorie transakcí. */
    public const TRANSACTION_CATEGORIES = ['office', 'software', 'travel', 'marketing', 'hardware', 'services', 'income_project', 'income_consulting', 'other'];

    /** Typy přihlašovacích údajů projektu. */
    public const CREDENTIAL_TYPES = ['sftp', 'ftp', 'ssh', 'smtp', 'database', 'admin', 'api', 'other'];

    /** První den týdne: 0 = neděle, 1 = pondělí (evropský standard). */
    public const FIRST_DAY_OF_WEEK = 1;

    /** Začátek fiskálního roku ve formátu MM-DD (kalendářní rok). */
    public const FISCAL_YEAR_START = '01-01';
}
