import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

export interface FinanceOverviewItem {
  uid: string;
  source_id: number;
  source: 'transaction' | 'invoice_payment';
  type: 'income' | 'expense';
  amount_cents: number;
  category: string | null;
  description: string | null;
  date: string;
  project_id: number | null;
  project_name: string | null;
  client_id: number | null;
  client_name: string | null;
  invoice_id: number | null;
  invoice_number: string | null;
  payment_method: string | null;
  created_at: string;
  updated_at: string | null;
}

export interface FinanceOverviewResponse {
  data: FinanceOverviewItem[];
  total: number;
  page: number;
  per_page: number;
}

export interface FinanceOverviewParams {
  type?: string;
  from?: string;
  to?: string;
  search?: string;
  page?: number;
  per_page?: number;
}

export function useFinanceOverview(params?: FinanceOverviewParams) {
  const query = new URLSearchParams();
  if (params?.type) query.set('type', params.type);
  if (params?.from) query.set('from', params.from);
  if (params?.to) query.set('to', params.to);
  if (params?.search) query.set('search', params.search);
  if (params?.page) query.set('page', String(params.page));
  if (params?.per_page) query.set('per_page', String(params.per_page));
  return useQuery<FinanceOverviewResponse>({
    queryKey: ['finance-overview', params],
    queryFn: () => api.get<FinanceOverviewResponse>(`/finance-overview?${query.toString()}`),
  });
}
