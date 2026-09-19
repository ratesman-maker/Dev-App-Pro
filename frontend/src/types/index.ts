/**
 * Sdílené typy pro celý frontend.
 */

/** Paginovaná odpověď z API. */
export interface PaginatedResponse<T> {
  data: T[];
  total: number;
  page: number;
  per_page: number;
}

/** Parametry pro paginaci. */
export interface PaginationParams {
  page?: number;
  per_page?: number;
}

/** Parametry pro vyhledávání a řazení. */
export interface QueryParams extends PaginationParams {
  search?: string;
  sort?: string;
}

/** Typ klienta. */
export type ClientType = 'individual' | 'company' | 'nonprofit' | 'government';

/** Status projektu. */
export type ProjectStatus = 'active' | 'on_hold' | 'completed' | 'cancelled' | 'archived';

/** Status úkolu. */
export type TaskStatus = 'todo' | 'in_progress' | 'done' | 'cancelled';

/** Priorita úkolu. */
export type TaskPriority = 'low' | 'medium' | 'high' | 'urgent';

/** Status faktury. */
export type InvoiceStatus = 'draft' | 'sent' | 'paid' | 'overdue' | 'cancelled';

/** Metoda platby. */
export type PaymentMethod = 'cash' | 'bank_transfer' | 'card' | 'other';

/** Typ transakce. */
export type TransactionType = 'income' | 'expense';

/** Typ přihlašovacích údajů projektu. */
export type CredentialType = 'sftp' | 'ftp' | 'ssh' | 'smtp' | 'database' | 'admin' | 'api' | 'other';

/** Soubor s metadaty. */
export interface FileMeta {
  id: number;
  original_name: string;
  stored_name: string;
  mime_type: string;
  size_bytes: number;
  storage_path: string;
  is_image: number;
  thumbnail_path: string | null;
  medium_path: string | null;
  created_at: string;
  user_name?: string;
  attachments?: Array<{
    entity_type: string;
    entity_id: number;
    label?: string;
  }>;
}

/** Poznámka. */
export interface Note {
  id: number;
  title: string | null;
  content: string;
  created_at: string;
  user_name?: string;
  attachments?: Array<{
    entity_type: string;
    entity_id: number;
    label?: string;
  }>;
}

/** Badge varianty. */
export type BadgeVariant = 'default' | 'secondary' | 'destructive' | 'outline';

/** Stav načítání. */
export type LoadingState = 'idle' | 'loading' | 'success' | 'error';

/** API chyba. */
export interface ApiErrorResponse {
  error: string;
  fields?: Record<string, string>;
}
