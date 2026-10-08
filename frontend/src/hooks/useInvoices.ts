import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

export interface InvoiceItem {
  id: number;
  description: string;
  quantity: number | string;
  unit: string | null;
  unit_price_cents: number;
}

export interface InvoiceItemInput {
  description: string;
  quantity: number;
  unit?: string | null;
  unit_price_cents: number;
}

export interface Invoice {
  id: number;
  client_id: number | null;
  client_name: string | null;
  project_id: number | null;
  project_name: string | null;
  invoice_number: string;
  status: 'draft' | 'sent' | 'paid' | 'overdue' | 'cancelled';
  subtotal_cents: number;
  vat_rate_percent: string;
  vat_amount_cents: number;
  amount_cents: number;
  paid_cents: number;
  currency: string;
  variable_symbol: string | null;
  constant_symbol: string | null;
  iban: string | null;
  issue_date: string;
  due_date: string;
  taxable_date: string | null;
  note: string | null;
  frozen_pdf: string | null;
  items: InvoiceItem[];
  created_at: string;
  updated_at: string;
}

export interface InvoiceInput {
  client_id?: number | null;
  project_id?: number | null;
  invoice_number: string;
  subtotal_cents?: number;
  vat_rate_percent: number | string;
  issue_date: string;
  due_date: string;
  taxable_date?: string | null;
  variable_symbol?: string | null;
  constant_symbol?: string | null;
  iban?: string | null;
  note?: string | null;
  status?: 'draft' | 'sent' | 'paid' | 'overdue' | 'cancelled';
  items?: InvoiceItemInput[];
}

export interface InvoicesResponse {
  data: Invoice[];
  total: number;
  page: number;
  per_page: number;
}

export interface InvoicesParams {
  search?: string;
  page?: number;
  per_page?: number;
  client_id?: number;
  project_id?: number;
  status?: string;
  overdue?: boolean;
  enabled?: boolean;
}

export function useInvoices(params?: InvoicesParams) {
  const query = new URLSearchParams();
  if (params?.search) query.set('search', params.search);
  if (params?.page) query.set('page', String(params.page));
  if (params?.per_page) query.set('per_page', String(params.per_page));
  if (params?.client_id) query.set('client_id', String(params.client_id));
  if (params?.project_id) query.set('project_id', String(params.project_id));
  if (params?.status) query.set('status', params.status);
  if (params?.overdue) query.set('overdue', '1');
  return useQuery<InvoicesResponse>({
    queryKey: ['invoices', params],
    queryFn: () => api.get<InvoicesResponse>(`/invoices?${query.toString()}`),
    enabled: params?.enabled ?? true,
  });
}

export function useCreateInvoice() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: InvoiceInput) => api.post<Invoice>('/invoices', data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['invoices'] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
    },
  });
}

export function useUpdateInvoice(id: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: Partial<InvoiceInput>) => api.put<Invoice>(`/invoices/${id}`, data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['invoices'] });
      qc.invalidateQueries({ queryKey: ['invoice', id] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
    },
  });
}

export function useDeleteInvoice() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => api.delete(`/invoices/${id}`),
    onMutate: async (id: number) => {
      await qc.cancelQueries({ queryKey: ['invoices'] });
      const previous = qc.getQueryData<InvoicesResponse>(['invoices']);
      if (previous) {
        qc.setQueryData<InvoicesResponse>(['invoices'], {
          ...previous,
          data: previous.data.filter((i) => i.id !== id),
        });
      }
      return { previous };
    },
    onError: (_err, _id, context) => {
      if (context?.previous) {
        qc.setQueryData(['invoices'], context.previous);
      }
    },
    onSettled: () => {
      qc.invalidateQueries({ queryKey: ['invoices'] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
    },
  });
}

function buildInvoiceFilename(invoice: Invoice): string {
  const base = `faktura-${invoice.invoice_number}`;
  const client = (invoice.client_name ?? '').trim();
  if (!client) return `${base}.pdf`;
  // Zachová české znaky, ostatní nepovolené → pomlčka
  const safe = client.replace(/[^\p{L}\p{N}]+/gu, '-').replace(/^-+|-+$/g, '');
  return safe ? `${base}-${safe}.pdf` : `${base}.pdf`;
}

export function useDownloadInvoicePdf() {
  return useMutation({
    mutationFn: async (invoice: Invoice) => {
      const blob = await api.getBlob(`/invoices/${invoice.id}/pdf`);
      const url = URL.createObjectURL(blob);
      // Stažení s názvem souboru (ne blob UUID) — window.open by použil UUID bez přípony
      const a = document.createElement('a');
      a.href = url;
      a.download = buildInvoiceFilename(invoice);
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      setTimeout(() => URL.revokeObjectURL(url), 60000);
    },
  });
}
