/**
 * Centralizované konstanty frontendu.
 * Zabraňuje magickým hodnotám rozptýleným v kódu.
 */

/** Výchozí počet položek na stránku. */
export const DEFAULT_PER_PAGE = 15;

/** Maximální počet položek na stránku. */
export const MAX_PER_PAGE = 100;

/** Debounce zpoždění pro vyhledávání v ms. */
export const SEARCH_DEBOUNCE_MS = 300;

/** Stale time pro TanStack Query v ms (60 sekund). */
export const QUERY_STALE_TIME = 60 * 1000;

/** GC time pro TanStack Query v ms (5 minut). */
export const QUERY_GC_TIME = 5 * 60 * 1000;

/** Délka animace toastu v ms. */
export const TOAST_DURATION = 4000;

/** Klíče pro localStorage. */
export const STORAGE_KEYS = {
  THEME: 'devapppro-theme',
  SIDEBAR_COLLAPSED: 'devapppro-sidebar-collapsed',
} as const;

/** API base URL. */
export const API_BASE_URL = '/api';

/** Typy klientů. */
export const CLIENT_TYPES = ['individual', 'company', 'nonprofit', 'government'] as const;

/** Statusy projektů. */
export const PROJECT_STATUSES = ['active', 'on_hold', 'completed', 'cancelled', 'archived'] as const;

/** Statusy úkolů. */
export const TASK_STATUSES = ['todo', 'in_progress', 'done', 'cancelled'] as const;

/** Priority úkolů. */
export const TASK_PRIORITIES = ['low', 'medium', 'high', 'urgent'] as const;

/** Statusy faktur. */
export const INVOICE_STATUSES = ['draft', 'sent', 'paid', 'overdue', 'cancelled'] as const;

/** Metody plateb. */
export const PAYMENT_METHODS = ['cash', 'bank_transfer', 'card', 'other'] as const;

/** Typy transakcí. */
export const TRANSACTION_TYPES = ['income', 'expense'] as const;

/** Kategorie transakcí. */
export const TRANSACTION_CATEGORIES = [
  'office', 'software', 'travel', 'marketing', 'hardware',
  'services', 'income_project', 'income_consulting', 'other',
] as const;

/** Labely pro typy klientů. */
export const CLIENT_TYPE_LABELS: Record<string, string> = {
  individual: 'Osoba',
  company: 'Firma',
  nonprofit: 'Nezisková',
  government: 'Státní',
};

/** Labely pro statusy projektů. */
export const PROJECT_STATUS_LABELS: Record<string, string> = {
  active: 'Aktivní',
  on_hold: 'Pozastaveno',
  completed: 'Dokončeno',
  cancelled: 'Zrušeno',
  archived: 'Archivováno',
};

/** Labely pro statusy úkolů. */
export const TASK_STATUS_LABELS: Record<string, string> = {
  todo: 'K vyřešení',
  in_progress: 'Probíhá',
  done: 'Hotovo',
  cancelled: 'Zrušeno',
};

/** Labely pro priority úkolů. */
export const TASK_PRIORITY_LABELS: Record<string, string> = {
  low: 'Nízká',
  medium: 'Střední',
  high: 'Vysoká',
  urgent: 'Urgentní',
};

/** Labely pro statusy faktur. */
export const INVOICE_STATUS_LABELS: Record<string, string> = {
  draft: 'Koncept',
  sent: 'Odesláno',
  paid: 'Zaplaceno',
  overdue: 'Po splatnosti',
  cancelled: 'Zrušeno',
};

/** Labely pro metody plateb. */
export const PAYMENT_METHOD_LABELS: Record<string, string> = {
  cash: 'Hotovost',
  bank_transfer: 'Bankovní převod',
  card: 'Karta',
  other: 'Jiné',
};

/** Labely pro typy transakcí. */
export const TRANSACTION_TYPE_LABELS: Record<string, string> = {
  income: 'Příjem',
  expense: 'Výdaj',
};

/** První den týdne: 0 = neděle, 1 = pondělí (evropský standard). */
export const FIRST_DAY_OF_WEEK = 1;

/** Začátek fiskálního roku ve formátu MM-DD (kalendářní rok). */
export const FISCAL_YEAR_START = '01-01';

/**
 * Vrátí začátek aktuálního týdne (pondělí) podle FIRST_DAY_OF_WEEK.
 * Pokud je firstDayOfWeek=1 (pondělí), týden začíná pondělím.
 */
export function startOfWeek(date: Date = new Date(), firstDayOfWeek: number = FIRST_DAY_OF_WEEK): Date {
  const d = new Date(date);
  d.setHours(0, 0, 0, 0);
  const day = d.getDay(); // 0 = neděle, 1 = pondělí, ...
  const diff = (day - firstDayOfWeek + 7) % 7;
  d.setDate(d.getDate() - diff);
  return d;
}

/**
 * Vrátí začátek aktuálního fiskálního roku.
 * Pokud je fiscalYearStart='01-01', fiskální rok = kalendářní rok.
 * Pokud je '07-01', fiskální rok začíná 1. července.
 */
export function startOfFiscalYear(date: Date = new Date(), fiscalYearStart: string = FISCAL_YEAR_START): Date {
  const d = new Date(date);
  d.setHours(0, 0, 0, 0);
  const [month, day] = fiscalYearStart.split('-').map(Number);
  const fiscalStart = new Date(d.getFullYear(), month - 1, day);
  // Pokud je aktuální datum před fiskálním startem, fiskální rok začal v předchozím kalendářním roce
  if (d < fiscalStart) {
    return new Date(d.getFullYear() - 1, month - 1, day);
  }
  return fiscalStart;
}
