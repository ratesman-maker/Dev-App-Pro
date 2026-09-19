import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

export interface FileAttachment {
  entity_type: string;
  entity_id: number;
  entity_name?: string;
}

export interface FileItem {
  id: number;
  user_id: number | null;
  user_name: string | null;
  original_name: string;
  stored_name: string;
  mime_type: string;
  size_bytes: number;
  storage_path: string;
  is_image: boolean;
  thumbnail_path: string | null;
  medium_path: string | null;
  attachments: FileAttachment[];
  created_at: string;
}

export interface FileUpdateInput {
  attachments: Array<{ entity_type: string; entity_id: number }>;
}

export interface FilesResponse {
  data: FileItem[];
  total: number;
  page: number;
  per_page: number;
}

export interface FilesParams {
  search?: string;
  page?: number;
  per_page?: number;
  entity_type?: string;
  entity_id?: number;
  is_image?: boolean;
  enabled?: boolean;
}

export function useFiles(params?: FilesParams) {
  const query = new URLSearchParams();
  if (params?.search) query.set('search', params.search);
  if (params?.page) query.set('page', String(params.page));
  if (params?.per_page) query.set('per_page', String(params.per_page));
  if (params?.entity_type) query.set('entity_type', params.entity_type);
  if (params?.entity_id) query.set('entity_id', String(params.entity_id));
  if (params?.is_image) query.set('is_image', '1');
  return useQuery<FilesResponse>({
    queryKey: ['files', params],
    queryFn: () => api.get<FilesResponse>(`/files?${query.toString()}`),
    enabled: params?.enabled ?? true,
  });
}

export function useCreateFile() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (vars: { file: File; attachments: Array<{ entity_type: string; entity_id: number }> }) => {
      const formData = new FormData();
      formData.append('file', vars.file);
      formData.append('attachments', JSON.stringify(vars.attachments));
      return api.upload('/files', formData);
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['files'] }),
  });
}

export function useUpdateFile(id: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: FileUpdateInput) => api.put<FileItem>(`/files/${id}`, data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['files'] });
      qc.invalidateQueries({ queryKey: ['file', id] });
    },
  });
}

export function useDeleteFile() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => api.delete(`/files/${id}`),
    onMutate: async (id: number) => {
      await qc.cancelQueries({ queryKey: ['files'] });
      const previous = qc.getQueryData<FilesResponse>(['files']);
      if (previous) {
        qc.setQueryData<FilesResponse>(['files'], {
          ...previous,
          data: previous.data.filter((f) => f.id !== id),
        });
      }
      return { previous };
    },
    onError: (_err, _id, context) => {
      if (context?.previous) {
        qc.setQueryData(['files'], context.previous);
      }
    },
    onSettled: () => qc.invalidateQueries({ queryKey: ['files'] }),
  });
}

export function useDownloadFile() {
  return useMutation({
    mutationFn: async (id: number) => {
      const blob = await api.getBlob(`/files/${id}/download`);
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = '';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      setTimeout(() => URL.revokeObjectURL(url), 60000);
    },
  });
}
