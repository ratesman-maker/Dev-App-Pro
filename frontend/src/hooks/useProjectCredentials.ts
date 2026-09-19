import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

export type CredentialType = 'sftp' | 'ftp' | 'ssh' | 'smtp' | 'database' | 'admin' | 'api' | 'other';

export interface ProjectCredential {
  id: number;
  project_id: number;
  type: CredentialType;
  name: string;
  host: string | null;
  port: number | null;
  username: string | null;
  password: string | null;
  database_name: string | null;
  extra: string | null;
  note: string | null;
  created_at: string;
  updated_at: string;
}

export interface CredentialInput {
  type: CredentialType;
  name: string;
  host?: string | null;
  port?: number | null;
  username?: string | null;
  password?: string | null;
  database_name?: string | null;
  extra?: string | null;
  note?: string | null;
}

export const CREDENTIAL_TYPE_LABELS: Record<CredentialType, string> = {
  sftp: 'SFTP',
  ftp: 'FTP',
  ssh: 'SSH',
  smtp: 'SMTP',
  database: 'Databáze',
  admin: 'Admin',
  api: 'API',
  other: 'Ostatní',
};

export function useProjectCredentials(projectId: number | null) {
  return useQuery({
    queryKey: ['project-credentials', projectId],
    queryFn: () => api.get<{ data: ProjectCredential[] }>(`/projects/${projectId}/credentials`).then((r) => r.data),
    enabled: !!projectId,
  });
}

export function useCreateCredential(projectId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: CredentialInput) =>
      api.post(`/projects/${projectId}/credentials`, input),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['project-credentials', projectId] });
    },
  });
}

export function useUpdateCredential(id: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: CredentialInput) =>
      api.put(`/project-credentials/${id}`, input),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['project-credentials'] });
    },
  });
}

export function useDeleteCredential() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => api.delete(`/project-credentials/${id}`),
    onMutate: async (id: number) => {
      await qc.cancelQueries({ queryKey: ['project-credentials'] });
      const previousEntries = qc.getQueriesData<ProjectCredential[]>({ queryKey: ['project-credentials'] });
      qc.setQueriesData<ProjectCredential[]>({ queryKey: ['project-credentials'] }, (old) => {
        if (!old) return old;
        return old.filter((c) => c.id !== id);
      });
      return { previousEntries };
    },
    onError: (_err, _id, context) => {
      if (context?.previousEntries) {
        for (const [queryKey, data] of context.previousEntries) {
          qc.setQueryData(queryKey, data);
        }
      }
    },
    onSettled: () => {
      qc.invalidateQueries({ queryKey: ['project-credentials'] });
    },
  });
}
