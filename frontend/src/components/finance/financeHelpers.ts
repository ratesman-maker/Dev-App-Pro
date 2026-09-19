import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import {
  type Invoice,
} from '@/hooks/useInvoices';
import {
  type InvoicePayment,
} from '@/hooks/useInvoicePayments';
import {
  type Transaction,
} from '@/hooks/useTransactions';

export const PER_PAGE = 50;

export type BadgeVariant = 'default' | 'secondary' | 'destructive' | 'outline';

// === Dashboard finance data ===
export interface DashboardFinance {
  total_paid_cents: number;
  total_income_cents: number;
  total_expense_cents: number;
  total_open_cents: number;
  overdue_count: number;
  overdue_cents: number;
}

export interface DashboardData {
  finance: DashboardFinance;
}

export function useDashboardFinance() {
  return useQuery<DashboardData>({
    queryKey: ['dashboard'],
    queryFn: () => api.get<DashboardData>('/dashboard'),
  });
}

// === Status badge helpers ===
export function invoiceStatusBadge(status: Invoice['status']): { variant: BadgeVariant; label: string } {
  switch (status) {
    case 'draft':
      return { variant: 'secondary', label: 'Koncept' };
    case 'sent':
      return { variant: 'default', label: 'Odesláno' };
    case 'paid':
      return { variant: 'default', label: 'Zaplaceno' };
    case 'overdue':
      return { variant: 'destructive', label: 'Po splatnosti' };
    case 'cancelled':
      return { variant: 'outline', label: 'Zrušeno' };
    default:
      return { variant: 'outline', label: status };
  }
}

export function paymentMethodBadge(method: InvoicePayment['method']): { variant: BadgeVariant; label: string } {
  switch (method) {
    case 'cash':
      return { variant: 'secondary', label: 'Hotovost' };
    case 'bank_transfer':
      return { variant: 'default', label: 'Bankovní převod' };
    case 'card':
      return { variant: 'default', label: 'Karta' };
    case 'other':
      return { variant: 'outline', label: 'Jiné' };
    default:
      return { variant: 'outline', label: method };
  }
}

export function transactionTypeBadge(type: Transaction['type']): { variant: BadgeVariant; label: string } {
  switch (type) {
    case 'income':
      return { variant: 'default', label: 'Příjem' };
    case 'expense':
      return { variant: 'destructive', label: 'Výdaj' };
    default:
      return { variant: 'outline', label: type };
  }
}

export function transactionCategoryBadge(category: Transaction['category']): { variant: BadgeVariant; label: string } {
  const map: Record<Transaction['category'], string> = {
    office: 'Kancelář',
    software: 'Software',
    travel: 'Cestovné',
    marketing: 'Marketing',
    hardware: 'Hardware',
    services: 'Služby',
    income_project: 'Příjem z projektu',
    income_consulting: 'Konzultace',
    other: 'Jiné',
  };
  return { variant: 'secondary', label: map[category] ?? category };
}

// === Filter option lists ===
export const INVOICE_STATUS_FILTERS: { value: string; label: string }[] = [
  { value: '', label: 'Všechny statusy' },
  { value: 'draft', label: 'Koncept' },
  { value: 'sent', label: 'Odesláno' },
  { value: 'paid', label: 'Zaplaceno' },
  { value: 'overdue', label: 'Po splatnosti' },
  { value: 'cancelled', label: 'Zrušeno' },
];

export const TRANSACTION_TYPE_FILTERS: { value: string; label: string }[] = [
  { value: '', label: 'Všechny typy' },
  { value: 'income', label: 'Příjmy' },
  { value: 'expense', label: 'Výdaje' },
];

export const TRANSACTION_CATEGORY_FILTERS: { value: string; label: string }[] = [
  { value: '', label: 'Všechny kategorie' },
  { value: 'office', label: 'Kancelář' },
  { value: 'software', label: 'Software' },
  { value: 'travel', label: 'Cestovné' },
  { value: 'marketing', label: 'Marketing' },
  { value: 'hardware', label: 'Hardware' },
  { value: 'services', label: 'Služby' },
  { value: 'income_project', label: 'Příjem z projektu' },
  { value: 'income_consulting', label: 'Konzultace' },
  { value: 'other', label: 'Jiné' },
];

export type TabKey = 'overview' | 'invoices' | 'payments' | 'transactions';

export const TABS: { key: TabKey; label: string }[] = [
  { key: 'overview', label: 'Přehled' },
  { key: 'invoices', label: 'Faktury' },
  { key: 'payments', label: 'Platby' },
  { key: 'transactions', label: 'Transakce' },
];
