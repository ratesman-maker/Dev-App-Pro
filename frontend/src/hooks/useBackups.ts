import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

export interface Backup {
  name: string;
  path: string;
  relative_path: string;
  type: 'zip' | 'duplicator';
  size: number;
  size_formatted: string;
  modified: string;
  modified_ts: number;
}

export interface BackupsResponse {
  backups: Backup[];
  dir: string;
  exists: boolean;
  total_size: number;
  count: number;
}

export interface RestoreJob {
  id: number;
  backup_path: string;
  project_name: string;
  site_url: string;
  document_root: string;
  db_name: string;
  db_user: string;
  db_password: string;
  old_url: string | null;
  status: 'pending' | 'extracting' | 'creating_db' | 'importing_sql' | 'configuring' | 'replacing_urls' | 'regenerating_ssl' | 'completed' | 'failed';
  error_message: string | null;
  created_at: string;
  updated_at: string;
}

export interface RestoreInput {
  backup_path: string;
  project_name: string;
}

export function useBackups() {
  return useQuery<BackupsResponse>({
    queryKey: ['backups'],
    queryFn: () => api.get<BackupsResponse>('/backups'),
    refetchInterval: 30000,
  });
}

export function useRestoreJobs() {
  const query = useQuery<{ data: RestoreJob[] }>({
    queryKey: ['restore-jobs'],
    queryFn: () => api.get<{ data: RestoreJob[] }>('/backups/restores'),
    refetchInterval: 1000,
  });

  return query;
}

export function useDeleteRestore() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => api.delete(`/backups/restores/${id}`),
    onMutate: async (id: number) => {
      await qc.cancelQueries({ queryKey: ['restore-jobs'] });
      const previous = qc.getQueryData<{ data: RestoreJob[] }>(['restore-jobs']);
      if (previous) {
        qc.setQueryData<{ data: RestoreJob[] }>(['restore-jobs'], {
          ...previous,
          data: previous.data.filter((r) => r.id !== id),
        });
      }
      return { previous };
    },
    onError: (_err, _id, context) => {
      if (context?.previous) {
        qc.setQueryData(['restore-jobs'], context.previous);
      }
    },
    onSettled: () => qc.invalidateQueries({ queryKey: ['restore-jobs'] }),
  });
}

export function useDeleteBackup() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (path: string) => api.delete('/backups/delete', { path }),
    onMutate: async (path: string) => {
      await qc.cancelQueries({ queryKey: ['backups'] });
      const previous = qc.getQueryData<BackupsResponse>(['backups']);
      if (previous) {
        qc.setQueryData<BackupsResponse>(['backups'], {
          ...previous,
          backups: previous.backups.filter((b) => b.path !== path),
          count: Math.max(0, previous.count - 1),
        });
      }
      return { previous };
    },
    onError: (_err, _path, context) => {
      if (context?.previous) {
        qc.setQueryData(['backups'], context.previous);
      }
    },
    onSettled: () => qc.invalidateQueries({ queryKey: ['backups'] }),
  });
}

export function useCreateRestore() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: RestoreInput) => api.post<RestoreJob>('/backups/restore', data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['restore-jobs'] });
      qc.invalidateQueries({ queryKey: ['backups'] });
    },
  });
}

const IN_PROGRESS_STATUSES = ['pending', 'extracting', 'creating_db', 'importing_sql', 'configuring', 'replacing_urls', 'regenerating_ssl'];

export function isRestoreInProgress(status: string): boolean {
  return IN_PROGRESS_STATUSES.includes(status);
}

/**
 * Kroky restore procesu v pořadí provedení.
 * Slouží k zobrazení progress baru s popisem aktuálního úkonů.
 */
export const RESTORE_STEPS = [
  { status: 'pending', label: 'Čeká ve frontě', description: 'Restore job čeká na zpracování' },
  { status: 'extracting', label: 'Rozbalování zálohy', description: 'Extrakce ZIP/DAF archivu do složky projektu' },
  { status: 'creating_db', label: 'Vytváření databáze', description: 'Vytvoření MySQL databáze a uživatele' },
  { status: 'importing_sql', label: 'Import SQL', description: 'Import SQL dumpu do databáze' },
  { status: 'configuring', label: 'Konfigurace WordPress', description: 'Generování wp-config.php a mu-plugin' },
  { status: 'replacing_urls', label: 'Nahrazování URL a cest', description: 'Serialization-aware search/replace URL a cest v DB' },
  { status: 'regenerating_ssl', label: 'Regenerace SSL', description: 'Vytvoření SSL certifikátu pro novou doménu' },
  { status: 'completed', label: 'Dokončeno', description: 'Restore úspěšně dokončen' },
  { status: 'failed', label: 'Selhalo', description: 'Restore selhal' },
] as const;

/**
 * Vypočítá procento progress (0-100) na základě aktuálního statusu.
 */
export function getRestoreProgress(status: string): number {
  const stepIndex = RESTORE_STEPS.findIndex(s => s.status === status);
  if (stepIndex === -1) return 0;
  if (status === 'completed') return 100;
  if (status === 'failed') return 100;
  // 7 kroků (pending=0, extracting=1, ..., regenerating_ssl=6, completed=7)
  // Pro každý krok ukážeme procento jako (stepIndex / 7) * 100
  // Ale pro aktuální krok ukážeme částečný progress (např. 50% kroku)
  const totalSteps = 7;
  return Math.round((stepIndex / totalSteps) * 100);
}

/**
 * Vrátí popis aktuálního kroku.
 */
export function getRestoreStepDescription(status: string): string {
  const step = RESTORE_STEPS.find(s => s.status === status);
  return step?.description ?? status;
}

/**
 * Vrátí label aktuálního kroku.
 */
export function getRestoreStepLabel(status: string): string {
  const step = RESTORE_STEPS.find(s => s.status === status);
  return step?.label ?? status;
}

