import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect, useRef } from 'react';
import { api } from '@/lib/api';

export interface Project {
  id: number;
  client_id: number | null;
  client_name: string | null;
  name: string;
  description: string | null;
  status: 'active' | 'on_hold' | 'completed' | 'cancelled' | 'archived';
  folder_path: string | null;
  type: 'static' | 'wordpress' | null;
  php_version: string | null;
  budget_cents: number;
  started_at: string | null;
  deadline: string | null;
  created_at: string;
  updated_at: string;
}

export interface ProjectInput {
  client_id?: number | null;
  name: string;
  description?: string | null;
  status?: 'active' | 'on_hold' | 'completed' | 'cancelled';
  budget_cents?: number;
  started_at?: string | null;
  deadline?: string | null;
  type?: 'static' | 'wordpress' | null;
  folder_path?: string | null;
}

export interface ProjectsResponse {
  data: Project[];
  total: number;
  page: number;
  per_page: number;
}

export interface ProjectsParams {
  search?: string;
  page?: number;
  per_page?: number;
  client_id?: number;
  status?: string;
  enabled?: boolean;
}

export function useProjects(params?: ProjectsParams) {
  const query = new URLSearchParams();
  if (params?.search) query.set('search', params.search);
  if (params?.page) query.set('page', String(params.page));
  if (params?.per_page) query.set('per_page', String(params.per_page));
  if (params?.client_id) query.set('client_id', String(params.client_id));
  if (params?.status) query.set('status', params.status);
  return useQuery<ProjectsResponse>({
    queryKey: ['projects', params],
    queryFn: () => api.get<ProjectsResponse>(`/projects?${query.toString()}`),
    enabled: params?.enabled ?? true,
  });
}

export function useCreateProject() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: ProjectInput) => api.post<Project>('/projects', data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['projects'] });
      qc.invalidateQueries({ queryKey: ['hosting-jobs'] });
    },
  });
}

export function useUpdateProject(id: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: Partial<ProjectInput>) => api.put<Project>(`/projects/${id}`, data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['projects'] });
      qc.invalidateQueries({ queryKey: ['hosting-jobs'] });
    },
  });
}

export function useDeleteProject() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => api.delete(`/projects/${id}`),
    onMutate: async (id: number) => {
      await qc.cancelQueries({ queryKey: ['projects'] });
      const previous = qc.getQueryData<ProjectsResponse>(['projects']);
      if (previous) {
        qc.setQueryData<ProjectsResponse>(['projects'], {
          ...previous,
          data: previous.data.filter((p) => p.id !== id),
        });
      }
      return { previous };
    },
    onError: (_err, _id, context) => {
      if (context?.previous) {
        qc.setQueryData(['projects'], context.previous);
      }
    },
    onSettled: () => qc.invalidateQueries({ queryKey: ['projects'] }),
  });
}

export function useArchiveProject() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => api.post(`/projects/${id}/archive`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['projects'] }),
  });
}

export function useRestoreProject() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => api.post(`/projects/${id}/restore`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['projects'] }),
  });
}

export function useWpLoginUrl() {
  return useMutation({
    mutationFn: async (id: number) => {
      const res = await api.get<{ url: string; admin_user: string }>(`/projects/${id}/wp-login-url`);
      return res;
    },
  });
}

export interface PhpVersion {
  version: string;
  installed: boolean;
  running: boolean;
  full_version: string | null;
}

export function usePhpVersions() {
  return useQuery<{ versions: PhpVersion[] }>({
    queryKey: ['php-versions'],
    queryFn: () => api.get<{ versions: PhpVersion[] }>('/tools/php-versions'),
    staleTime: 60000,
  });
}

export interface PhpVersionJob {
  id: number;
  project_id: number;
  php_version: string;
  old_php_version: string | null;
  status: 'pending' | 'starting_fpm' | 'regenerating' | 'reloading' | 'completed' | 'failed';
  error_message: string | null;
  created_at: string;
  updated_at: string;
}

export function usePhpVersionJobs() {
  const qc = useQueryClient();
  const prevActiveRef = useRef<number>(0);

  const query = useQuery<{ active: PhpVersionJob[]; recent: PhpVersionJob[] }>({
    queryKey: ['php-version-jobs'],
    queryFn: () => api.get<{ active: PhpVersionJob[]; recent: PhpVersionJob[] }>('/tools/php-version-jobs'),
    refetchInterval: 2000,
  });

  // Když aktivní joby přejdou z neprázdných na prázdné (vše dokončeno),
  // invalidovat projects query aby se aktualizovala php_version v UI.
  const activeCount = query.data?.active?.length ?? 0;
  useEffect(() => {
    if (prevActiveRef.current > 0 && activeCount === 0) {
      qc.invalidateQueries({ queryKey: ['projects'] });
    }
    prevActiveRef.current = activeCount;
  }, [activeCount, qc]);

  return query;
}

export function useSetPhpVersion() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, php_version }: { id: number; php_version: string }) =>
      api.post<{ job_id: number; status: string; php_version: string }>(`/projects/${id}/php-version`, { php_version }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['php-version-jobs'] });
      qc.invalidateQueries({ queryKey: ['php-versions'] });
    },
  });
}

export interface HostingJob {
  id: number;
  project_id: number;
  project_name: string | null;
  action: 'create' | 'update' | 'remove';
  folder_path: string;
  status: 'pending' | 'regenerating_ssl' | 'generating_vhosts' | 'reloading' | 'completed' | 'failed';
  error_message: string | null;
  created_at: string;
  updated_at: string;
}

export function useHostingJobs() {
  const qc = useQueryClient();
  const prevActiveRef = useRef<number>(0);

  const query = useQuery<{ data: HostingJob[] }>({
    queryKey: ['hosting-jobs'],
    queryFn: () => api.get<{ data: HostingJob[] }>('/projects/hosting-jobs'),
    refetchInterval: (query) => {
      const hasActive = query.state.data?.data?.some(
        (j) => j.status === 'pending' || j.status === 'regenerating_ssl' || j.status === 'generating_vhosts' || j.status === 'reloading'
      );
      return hasActive ? 2000 : false;
    },
  });

  const activeCount = query.data?.data?.filter(
    (j) => j.status === 'pending' || j.status === 'regenerating_ssl' || j.status === 'generating_vhosts' || j.status === 'reloading'
  ).length ?? 0;

  useEffect(() => {
    if (prevActiveRef.current > 0 && activeCount === 0) {
      qc.invalidateQueries({ queryKey: ['projects'] });
    }
    prevActiveRef.current = activeCount;
  }, [activeCount, qc]);

  return query;
}
