import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

export type ClientType = 'individual' | 'company' | 'nonprofit' | 'government';

export interface Client {
  id: number;
  type: ClientType;
  first_name: string | null;
  last_name: string | null;
  company_name: string | null;
  full_name: string;
  ico: string | null;
  dic: string | null;
  bank_account: string | null;
  email: string | null;
  phone: string | null;
  address: string | null;
  note: string | null;
  created_at: string;
  updated_at: string;
}

export interface ClientInput {
  type: ClientType;
  first_name?: string | null;
  last_name?: string | null;
  company_name?: string | null;
  ico?: string | null;
  dic?: string | null;
  bank_account?: string | null;
  email?: string | null;
  phone?: string | null;
  address?: string | null;
  note?: string | null;
}

export interface ClientsResponse {
  data: Client[];
  total: number;
  page: number;
  per_page: number;
}

export interface ClientsParams {
  search?: string;
  page?: number;
  per_page?: number;
  sort?: string;
}

export function useClients(params?: ClientsParams) {
  const query = new URLSearchParams();
  if (params?.search) query.set('search', params.search);
  if (params?.page) query.set('page', String(params.page));
  if (params?.per_page) query.set('per_page', String(params.per_page));
  if (params?.sort) query.set('sort', params.sort);
  return useQuery<ClientsResponse>({
    queryKey: ['clients', params],
    queryFn: () => api.get<ClientsResponse>(`/clients?${query.toString()}`),
  });
}

export function useCreateClient() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: ClientInput) => api.post<Client>('/clients', data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['clients'] }),
  });
}

export function useUpdateClient(id: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: Partial<ClientInput>) => api.put<Client>(`/clients/${id}`, data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['clients'] }),
  });
}

export function useDeleteClient() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => api.delete(`/clients/${id}`),
    onMutate: async (id: number) => {
      await qc.cancelQueries({ queryKey: ['clients'] });
      const previous = qc.getQueryData<ClientsResponse>(['clients']);
      if (previous) {
        qc.setQueryData<ClientsResponse>(['clients'], {
          ...previous,
          data: previous.data.filter((c) => c.id !== id),
        });
      }
      return { previous };
    },
    onError: (_err, _id, context) => {
      if (context?.previous) {
        qc.setQueryData(['clients'], context.previous);
      }
    },
    onSettled: () => qc.invalidateQueries({ queryKey: ['clients'] }),
  });
}
