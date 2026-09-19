import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

export type WpInstallStatus =
  | 'pending'
  | 'downloading'
  | 'extracting'
  | 'creating_db'
  | 'configuring'
  | 'completed'
  | 'failed'
  | 'pending_uninstall';

export interface WpInstall {
  id: number;
  site_name: string;
  site_url: string;
  document_root: string;
  db_name: string;
  db_user: string;
  db_password: string;
  status: WpInstallStatus;
  wp_version: string | null;
  admin_user: string | null;
  admin_password: string | null;
  admin_email: string | null;
  error_message: string | null;
  created_at: string;
  updated_at: string;
}

export interface WpInstallsResponse {
  data: WpInstall[];
  total: number;
}

export interface CreateWpInstallInput {
  site_name: string;
  admin_email: string;
  admin_user?: string;
  admin_password?: string;
}

/** Stavy, ve kterých je instalace v průběhu (pro polling). */
const IN_PROGRESS_STATUSES: WpInstallStatus[] = [
  'pending',
  'downloading',
  'extracting',
  'creating_db',
  'configuring',
  'pending_uninstall',
];

export function isInProgress(status: WpInstallStatus): boolean {
  return IN_PROGRESS_STATUSES.includes(status);
}

/** GET /api/tools/wp-installs - seznam všech instalací. */
export function useWpInstalls() {
  return useQuery<WpInstallsResponse>({
    queryKey: ['wp-installs'],
    queryFn: () => api.get<WpInstallsResponse>('/tools/wp-installs'),
    // Auto-poll pokud je nějaká instalace v průběhu
    refetchInterval: (query) => {
      const data = query.state.data;
      if (data && data.data.some((i) => isInProgress(i.status))) {
        return 2000;
      }
      return false;
    },
  });
}

/** GET /api/tools/wp-installs/{id} - detail jedné instalace s pollingem. */
export function useWpInstall(id: number | null) {
  return useQuery<WpInstall>({
    queryKey: ['wp-installs', id],
    queryFn: () => api.get<WpInstall>(`/tools/wp-installs/${id}`),
    enabled: id !== null,
    refetchInterval: (query) => {
      const data = query.state.data;
      if (data && isInProgress(data.status)) {
        return 2000;
      }
      return false;
    },
  });
}

/** POST /api/tools/wp-installs - vytvoření nové instalace. */
export function useCreateWpInstall() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: CreateWpInstallInput) =>
      api.post<WpInstall>('/tools/wp-installs', data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['wp-installs'] }),
  });
}

/** DELETE /api/tools/wp-installs/{id} - smazání instalace. */
export function useDeleteWpInstall() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => api.delete(`/tools/wp-installs/${id}`),
    onMutate: async (id: number) => {
      await qc.cancelQueries({ queryKey: ['wp-installs'] });
      const previous = qc.getQueryData<WpInstallsResponse>(['wp-installs']);
      if (previous) {
        qc.setQueryData<WpInstallsResponse>(['wp-installs'], {
          ...previous,
          data: previous.data.filter((w) => w.id !== id),
        });
      }
      return { previous };
    },
    onError: (_err, _id, context) => {
      if (context?.previous) {
        qc.setQueryData(['wp-installs'], context.previous);
      }
    },
    onSettled: () => qc.invalidateQueries({ queryKey: ['wp-installs'] }),
  });
}
