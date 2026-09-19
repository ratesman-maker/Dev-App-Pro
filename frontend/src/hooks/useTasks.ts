import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

export interface Task {
  id: number;
  project_id: number;
  project_name: string | null;
  title: string;
  description: string | null;
  status: 'todo' | 'in_progress' | 'done' | 'cancelled';
  priority: 'low' | 'medium' | 'high' | 'urgent';
  due_date: string | null;
  assigned_to: string | null;
  estimated_minutes: number;
  spent_minutes: number;
  created_at: string;
  updated_at: string;
}

export interface TaskInput {
  project_id: number;
  title: string;
  description?: string | null;
  status?: 'todo' | 'in_progress' | 'done' | 'cancelled';
  priority?: 'low' | 'medium' | 'high' | 'urgent';
  due_date?: string | null;
  assigned_to?: string | null;
  estimated_minutes?: number;
}

export interface TasksResponse {
  data: Task[];
  total: number;
  page: number;
  per_page: number;
}

export interface TasksParams {
  search?: string;
  page?: number;
  per_page?: number;
  project_id?: number;
  status?: string;
  priority?: string;
  overdue?: boolean;
  enabled?: boolean;
}

export function useTasks(params?: TasksParams) {
  const query = new URLSearchParams();
  if (params?.search) query.set('search', params.search);
  if (params?.page) query.set('page', String(params.page));
  if (params?.per_page) query.set('per_page', String(params.per_page));
  if (params?.project_id) query.set('project_id', String(params.project_id));
  if (params?.status) query.set('status', params.status);
  if (params?.priority) query.set('priority', params.priority);
  if (params?.overdue) query.set('overdue', '1');
  return useQuery<TasksResponse>({
    queryKey: ['tasks', params],
    queryFn: () => api.get<TasksResponse>(`/tasks?${query.toString()}`),
    enabled: params?.enabled ?? true,
  });
}

export function useCreateTask() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: TaskInput) => api.post<Task>('/tasks', data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['tasks'] }),
  });
}

export function useUpdateTask(id: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: Partial<TaskInput>) => api.put<Task>(`/tasks/${id}`, data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['tasks'] }),
  });
}

export function useDeleteTask() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => api.delete(`/tasks/${id}`),
    onMutate: async (id: number) => {
      await qc.cancelQueries({ queryKey: ['tasks'] });
      const previous = qc.getQueryData<TasksResponse>(['tasks']);
      if (previous) {
        qc.setQueryData<TasksResponse>(['tasks'], {
          ...previous,
          data: previous.data.filter((t) => t.id !== id),
        });
      }
      return { previous };
    },
    onError: (_err, _id, context) => {
      if (context?.previous) {
        qc.setQueryData(['tasks'], context.previous);
      }
    },
    onSettled: () => qc.invalidateQueries({ queryKey: ['tasks'] }),
  });
}
