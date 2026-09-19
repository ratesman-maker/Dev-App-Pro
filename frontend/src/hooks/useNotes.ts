import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

export type EntityType = 'client' | 'project' | 'task' | 'invoice';

export interface NoteAttachment {
  entity_type: EntityType;
  entity_id: number;
  entity_name?: string;
}

export interface Note {
  id: number;
  user_id: number | null;
  user_name: string | null;
  title: string | null;
  content: string;
  attachments: NoteAttachment[];
  created_at: string;
  updated_at: string;
}

export interface NoteInput {
  title?: string | null;
  content: string;
  attachments: Array<{ entity_type: EntityType; entity_id: number }>;
}

export interface NotesResponse {
  data: Note[];
  total: number;
  page: number;
  per_page: number;
}

export interface NotesParams {
  search?: string;
  page?: number;
  per_page?: number;
  entity_type?: string;
  entity_id?: number;
  enabled?: boolean;
}

export function useNotes(params?: NotesParams) {
  const query = new URLSearchParams();
  if (params?.search) query.set('search', params.search);
  if (params?.page) query.set('page', String(params.page));
  if (params?.per_page) query.set('per_page', String(params.per_page));
  if (params?.entity_type) query.set('entity_type', params.entity_type);
  if (params?.entity_id) query.set('entity_id', String(params.entity_id));
  return useQuery<NotesResponse>({
    queryKey: ['notes', params],
    queryFn: () => api.get<NotesResponse>(`/notes?${query.toString()}`),
    enabled: params?.enabled ?? true,
  });
}

export function useCreateNote() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: NoteInput) => api.post<Note>('/notes', data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['notes'] }),
  });
}

export function useUpdateNote(id: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: NoteInput) => api.put<Note>(`/notes/${id}`, data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['notes'] });
      qc.invalidateQueries({ queryKey: ['note', id] });
    },
  });
}

export function useDeleteNote() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => api.delete(`/notes/${id}`),
    onMutate: async (id: number) => {
      await qc.cancelQueries({ queryKey: ['notes'] });
      const previous = qc.getQueryData<NotesResponse>(['notes']);
      if (previous) {
        qc.setQueryData<NotesResponse>(['notes'], {
          ...previous,
          data: previous.data.filter((n) => n.id !== id),
        });
      }
      return { previous };
    },
    onError: (_err, _id, context) => {
      if (context?.previous) {
        qc.setQueryData(['notes'], context.previous);
      }
    },
    onSettled: () => qc.invalidateQueries({ queryKey: ['notes'] }),
  });
}

export function useNote(id: number) {
  return useQuery<Note>({
    queryKey: ['note', id],
    queryFn: () => api.get<Note>(`/notes/${id}`),
    enabled: !!id,
  });
}
