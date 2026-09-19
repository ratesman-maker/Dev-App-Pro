import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

export type WorklogCategory = 'project' | 'security' | 'maintenance' | 'meeting' | 'other';
export type WorklogSeverity = 'info' | 'warning' | 'critical';

export interface WorklogAttachment {
  id: number;
  entry_id: number;
  original_name: string;
  stored_name: string;
  mime_type: string;
  size_bytes: number;
  storage_path: string;
  is_image: number;
  created_at: string;
}

export interface WorklogEntry {
  id: number;
  user_id: number | null;
  user_name: string | null;
  project_id: number | null;
  project_name: string | null;
  client_id: number | null;
  client_name: string | null;
  category: WorklogCategory;
  severity: WorklogSeverity;
  title: string;
  description: string | null;
  hours: number | null;
  is_done: number;
  done_at: string | null;
  created_at: string;
  updated_at: string;
  attachments: WorklogAttachment[];
}

export interface WorklogInput {
  title: string;
  description?: string | null;
  category?: WorklogCategory;
  severity?: WorklogSeverity;
  project_id?: number | null;
  client_id?: number | null;
  hours?: number | null;
  is_done?: boolean;
}

export interface WorklogResponse {
  data: WorklogEntry[];
  total: number;
  page: number;
  per_page: number;
}

export interface WorklogParams {
  search?: string;
  page?: number;
  per_page?: number;
  category?: string;
  severity?: string;
  project_id?: number;
  client_id?: number;
}

export function useWorklog(params?: WorklogParams) {
  const query = new URLSearchParams();
  if (params?.search) query.set('search', params.search);
  if (params?.page) query.set('page', String(params.page));
  if (params?.per_page) query.set('per_page', String(params.per_page));
  if (params?.category) query.set('category', params.category);
  if (params?.severity) query.set('severity', params.severity);
  if (params?.project_id) query.set('project_id', String(params.project_id));
  if (params?.client_id) query.set('client_id', String(params.client_id));
  return useQuery<WorklogResponse>({
    queryKey: ['worklog', params],
    queryFn: () => api.get<WorklogResponse>(`/worklog?${query.toString()}`),
  });
}

export function useCreateWorklog() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: WorklogInput) => api.post<WorklogEntry>('/worklog', data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['worklog'] }),
  });
}

export function useUpdateWorklog(id: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: WorklogInput) => api.put<WorklogEntry>(`/worklog/${id}`, data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['worklog'] });
      qc.invalidateQueries({ queryKey: ['worklog-entry', id] });
    },
  });
}

export function useDeleteWorklog() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => api.delete(`/worklog/${id}`),
    onMutate: async (id: number) => {
      await qc.cancelQueries({ queryKey: ['worklog'] });
      const previous = qc.getQueryData<WorklogResponse>(['worklog']);
      if (previous) {
        qc.setQueryData<WorklogResponse>(['worklog'], {
          ...previous,
          data: previous.data.filter((w) => w.id !== id),
        });
      }
      return { previous };
    },
    onError: (_err, _id, context) => {
      if (context?.previous) {
        qc.setQueryData(['worklog'], context.previous);
      }
    },
    onSettled: () => qc.invalidateQueries({ queryKey: ['worklog'] }),
  });
}

export function useWorklogEntry(id: number) {
  return useQuery<WorklogEntry>({
    queryKey: ['worklog-entry', id],
    queryFn: () => api.get<WorklogEntry>(`/worklog/${id}`),
    enabled: !!id,
  });
}
