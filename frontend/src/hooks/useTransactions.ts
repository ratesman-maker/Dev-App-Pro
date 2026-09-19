import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

export interface Transaction {
  id: number;
  project_id: number | null;
  project_name: string | null;
  client_id: number | null;
  client_name: string | null;
  invoice_id: number | null;
  invoice_number: string | null;
  type: 'income' | 'expense';
  amount_cents: number;
  category:
    | 'office'
    | 'software'
    | 'travel'
    | 'marketing'
    | 'hardware'
    | 'services'
    | 'income_project'
    | 'income_consulting'
    | 'other';
  description: string | null;
  transaction_date: string;
  created_at: string;
  updated_at: string;
}

export interface TransactionInput {
  project_id?: number | null;
  client_id?: number | null;
  invoice_id?: number | null;
  type: 'income' | 'expense';
  amount_cents: number;
  category?: Transaction['category'];
  description?: string | null;
  transaction_date: string;
}

export interface TransactionsResponse {
  data: Transaction[];
  total: number;
  page: number;
  per_page: number;
}

export interface TransactionsParams {
  search?: string;
  page?: number;
  per_page?: number;
  project_id?: number;
  client_id?: number;
  type?: string;
  category?: string;
  from?: string;
  to?: string;
  enabled?: boolean;
}

export function useTransactions(params?: TransactionsParams) {
  const query = new URLSearchParams();
  if (params?.search) query.set('search', params.search);
  if (params?.page) query.set('page', String(params.page));
  if (params?.per_page) query.set('per_page', String(params.per_page));
  if (params?.project_id) query.set('project_id', String(params.project_id));
  if (params?.client_id) query.set('client_id', String(params.client_id));
  if (params?.type) query.set('type', params.type);
  if (params?.category) query.set('category', params.category);
  if (params?.from) query.set('from', params.from);
  if (params?.to) query.set('to', params.to);
  return useQuery<TransactionsResponse>({
    queryKey: ['transactions', params],
    queryFn: () => api.get<TransactionsResponse>(`/transactions?${query.toString()}`),
    enabled: params?.enabled ?? true,
  });
}

export function useCreateTransaction() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: TransactionInput) => api.post<Transaction>('/transactions', data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['transactions'] });
      qc.invalidateQueries({ queryKey: ['finance-overview'] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
    },
  });
}

export function useUpdateTransaction(id: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: Partial<TransactionInput>) => api.put<Transaction>(`/transactions/${id}`, data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['transactions'] });
      qc.invalidateQueries({ queryKey: ['transaction', id] });
      qc.invalidateQueries({ queryKey: ['finance-overview'] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
    },
  });
}

export function useDeleteTransaction() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => api.delete(`/transactions/${id}`),
    onMutate: async (id: number) => {
      await qc.cancelQueries({ queryKey: ['transactions'] });
      const previous = qc.getQueryData<TransactionsResponse>(['transactions']);
      if (previous) {
        qc.setQueryData<TransactionsResponse>(['transactions'], {
          ...previous,
          data: previous.data.filter((t) => t.id !== id),
        });
      }
      return { previous };
    },
    onError: (_err, _id, context) => {
      if (context?.previous) {
        qc.setQueryData(['transactions'], context.previous);
      }
    },
    onSettled: () => {
      qc.invalidateQueries({ queryKey: ['transactions'] });
      qc.invalidateQueries({ queryKey: ['finance-overview'] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
    },
  });
}
