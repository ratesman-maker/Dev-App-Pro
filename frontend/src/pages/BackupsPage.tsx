import { useState, useEffect, useRef } from 'react';
import { HardDriveDownload, Archive, FolderOpen, Loader2, Plus, Package, Trash2 } from 'lucide-react';
import {
  useBackups,
  useRestoreJobs,
  useCreateRestore,
  useDeleteBackup,
  isRestoreInProgress,
  getRestoreProgress,
  getRestoreStepDescription,
  getRestoreStepLabel,
  type Backup,
} from '@/hooks/useBackups';
import { useToast } from '@/components/ui/toast';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog } from '@/components/ui/dialog';
import { ConfirmDeleteDialog } from '@/components/shared/ConfirmDeleteDialog';

function formatBytes(bytes: number): string {
  if (!bytes) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  let i = 0;
  let val = bytes;
  while (val >= 1024 && i < units.length - 1) {
    val /= 1024;
    i++;
  }
  return Math.round(val * 10) / 10 + ' ' + units[i];
}


export default function BackupsPage() {
  const { data } = useBackups();
  const { data: restoreData } = useRestoreJobs();
  const createRestore = useCreateRestore();
  const deleteBackupMutation = useDeleteBackup();
  const toast = useToast();

  const [restoreBackup, setRestoreBackup] = useState<Backup | null>(null);
  const [projectName, setProjectName] = useState('');
  const [deleteBackup, setDeleteBackup] = useState<Backup | null>(null);

  // Sledování dokončených/failed jobů pro toast notifikace
  const seenJobsRef = useRef<Set<number>>(new Set());
  const previousActiveRef = useRef<Set<number>>(new Set());

  const backups = data?.backups ?? [];
  const dir = data?.dir ?? '';
  const exists = data?.exists ?? false;
  const restoreJobs = restoreData?.data ?? [];
  const activeRestores = restoreJobs.filter((r) => isRestoreInProgress(r.status));

  // Toast notifikace při dokončení/selhání restore jobu
  useEffect(() => {
    const currentActive = new Set(activeRestores.map((j) => j.id));

    // Najít joby které byly aktivní a teď už nejsou (dokončily se)
    for (const jobId of previousActiveRef.current) {
      if (!currentActive.has(jobId)) {
        // Job skončil - najít ho v restoreJobs (už není v active, ale může být v seznamu)
        const job = restoreJobs.find((j) => j.id === jobId);
        if (job && !seenJobsRef.current.has(jobId)) {
          seenJobsRef.current.add(jobId);
          if (job.status === 'completed') {
            toast.success(
              'Obnova dokončena',
              `Projekt ${job.project_name}.localhost byl úspěšně obnoven ze zálohy.`
            );
          } else if (job.status === 'failed') {
            toast.error(
              'Obnova selhala',
              job.error_message || `Projekt ${job.project_name}.localhost se nepodařilo obnovit.`
            );
          }
        }
      }
    }

    previousActiveRef.current = currentActive;
  }, [activeRestores, restoreJobs, toast]);

  const handleRestore = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!restoreBackup) return;
    try {
      await createRestore.mutateAsync({
        backup_path: restoreBackup.path,
        project_name: projectName,
      });
      toast.success('Restore spuštěn', `Projekt ${projectName}.localhost se obnovuje ze zálohy.`);
      setRestoreBackup(null);
      setProjectName('');
    } catch (err) {
      toast.error('Chyba', err instanceof Error ? err.message : 'Vytvoření restore jobu selhalo.');
    }
  };

  // Název projektu z názvu zálohy (odstranit příponu a datum)
  const suggestProjectName = (backup: Backup): string => {
    let name = backup.name.replace(/\.zip$/i, '');
    // Odstranit datum na konci (např. _20260101)
    name = name.replace(/[_-]\d{8}.*$/, '');
    // Odstranit _archive
    name = name.replace(/_archive$/i, '');
    // Malá písmena, pomlčky
    name = name.toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/^-+|-+$/g, '');
    return name || 'novy-projekt';
  };

  return (
    <div className="space-y-6 p-6">
      <h1 className="text-2xl font-semibold">Zálohy</h1>

      {/* Info karta */}
      <Card>
        <CardContent className="flex items-center justify-between p-4">
          <div className="flex items-center gap-3">
            <HardDriveDownload className="h-5 w-5 text-muted-foreground" />
            <div>
              <div className="font-medium">{dir}</div>
              <div className="text-sm text-muted-foreground">
                {exists
                  ? `${backups.length} záloh · ${formatBytes(data?.total_size ?? 0)} celkem`
                  : 'Složka neexistuje'}
              </div>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Probíhající restore - kompaktní progress */}
      {activeRestores.length > 0 && (
        <Card>
          <CardContent className="space-y-3 p-4">
            <div className="flex items-center gap-2 font-medium">
              <Loader2 className="h-4 w-4 animate-spin" />
              Probíhající obnova
            </div>
            {activeRestores.map((job) => {
              const progress = getRestoreProgress(job.status);
              const stepLabel = getRestoreStepLabel(job.status);
              const stepDesc = getRestoreStepDescription(job.status);

              return (
                <div key={job.id} className="rounded border p-4 space-y-2">
                  <div className="flex items-center justify-between">
                    <span className="font-medium">{job.project_name}.localhost</span>
                    <span className="text-sm text-muted-foreground">{progress}%</span>
                  </div>
                  <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                    <div
                      className="h-full rounded-full bg-primary progress-bar transition-all duration-500 ease-out"
                      style={{ '--progress': `${progress}%` } as React.CSSProperties}
                    />
                  </div>
                  <div className="flex items-start gap-2">
                    <Loader2 className="mt-0.5 h-4 w-4 shrink-0 animate-spin text-primary" />
                    <div>
                      <div className="text-sm font-medium">{stepLabel}</div>
                      <div className="text-xs text-muted-foreground">{stepDesc}</div>
                    </div>
                  </div>
                </div>
              );
            })}
          </CardContent>
        </Card>
      )}

      {/* Složka neexistuje */}
      {!exists && (
        <Card>
          <CardContent className="flex flex-col items-center gap-3 py-16 text-center">
            <FolderOpen className="h-10 w-10 text-muted-foreground" />
            <div>
              <div className="font-medium">Složka záloh neexistuje</div>
              <div className="mt-1 text-sm text-muted-foreground">
                Vytvořte složku a přidejte .zip soubory záloh webů a projektů.
              </div>
            </div>
          </CardContent>
        </Card>
      )}

      {/* Prázdná složka */}
      {exists && backups.length === 0 && (
        <Card>
          <CardContent className="flex flex-col items-center gap-3 py-16 text-center">
            <Archive className="h-10 w-10 text-muted-foreground" />
            <div>
              <div className="font-medium">Žádné zálohy</div>
              <div className="mt-1 text-sm text-muted-foreground">
                Ve složce nebyly nalezeny žádné .zip soubory.
              </div>
            </div>
          </CardContent>
        </Card>
      )}

      {/* Tabulka záloh */}
      {exists && backups.length > 0 && (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b text-left text-muted-foreground">
                  <th className="px-4 py-3 font-medium">Název</th>
                  <th className="px-4 py-3 font-medium">Složka</th>
                  <th className="px-4 py-3 font-medium">Velikost</th>
                  <th className="px-4 py-3 font-medium">Datum</th>
                  <th className="px-4 py-3 font-medium text-right">Akce</th>
                </tr>
              </thead>
              <tbody>
                {backups.map((b) => {
                  const folder = b.relative_path.includes('/')
                    ? b.relative_path.split('/')[0]
                    : '—';
                  const isRestoring = activeRestores.some(
                    (r) => r.backup_path === b.path && isRestoreInProgress(r.status)
                  );
                  return (
                    <tr key={b.path} className="border-b last:border-0">
                      <td className="px-4 py-3 font-medium">
                        <div className="flex items-center gap-2 min-w-0">
                          {b.type === 'duplicator' ? (
                            <Package className="h-4 w-4 text-muted-foreground shrink-0" />
                          ) : (
                            <Archive className="h-4 w-4 text-muted-foreground shrink-0" />
                          )}
                          <span className="break-all">{b.name}</span>
                          {b.type === 'duplicator' && (
                            <span className="rounded border bg-muted px-1.5 py-0.5 text-xs text-muted-foreground shrink-0">
                              DAF
                            </span>
                          )}
                        </div>
                      </td>
                      <td className="px-4 py-3 text-muted-foreground">{folder}</td>
                      <td className="px-4 py-3">{b.size_formatted}</td>
                      <td className="px-4 py-3 text-muted-foreground">{b.modified}</td>
                      <td className="px-4 py-3 text-right">
                        <div className="flex items-center justify-end gap-2">
                          <Button
                            variant="outline"
                            size="sm"
                            disabled={isRestoring}
                            onClick={() => {
                              setRestoreBackup(b);
                              setProjectName(suggestProjectName(b));
                            }}
                          >
                            {isRestoring ? (
                              <Loader2 className="h-3.5 w-3.5 animate-spin" />
                            ) : (
                              <Plus className="h-3.5 w-3.5" />
                            )}
                            Vytvořit projekt
                          </Button>
                          <Button
                            variant="outline"
                            size="sm"
                            className="text-destructive"
                            disabled={isRestoring}
                            onClick={() => setDeleteBackup(b)}
                          >
                            <Trash2 className="h-3.5 w-3.5" />
                            Smazat
                          </Button>
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      {/* Dialog pro vytvoření projektu */}
      <Dialog
        open={!!restoreBackup}
        onClose={() => {
          setRestoreBackup(null);
          setProjectName('');
        }}
        title="Vytvořit projekt ze zálohy"
        className="max-w-xl"
      >
        {restoreBackup && (
          <form onSubmit={handleRestore} className="space-y-4">
            <p className="text-sm text-muted-foreground">
              Obnoví zálohu jako nový WordPress projekt. Projekt bude plně nainstalován
              (soubory, databáze, URL) a dostupný na adrese <code className="rounded bg-muted px-1.5 py-0.5 text-xs">{projectName || 'název'}.localhost</code>.
            </p>

            <div className="space-y-2">
              <Label htmlFor="project_name">Název projektu</Label>
              <div className="flex items-center">
                <Input
                  id="project_name"
                  placeholder="napr-muj-web"
                  value={projectName}
                  onChange={(e) => setProjectName(e.target.value)}
                  pattern="[a-z0-9-]+"
                  minLength={3}
                  maxLength={50}
                  required
                  autoFocus
                />
                <span className="ml-2 shrink-0 text-sm text-muted-foreground">.localhost</span>
              </div>
            </div>

            <div className="space-y-1.5 rounded-md border bg-muted/50 p-3 text-sm">
              <div className="flex items-start gap-2">
                <span className="shrink-0 font-medium text-muted-foreground">Záloha:</span>
                <span className="break-all text-foreground">{restoreBackup.name}</span>
              </div>
              <div className="flex items-center gap-2">
                <span className="shrink-0 font-medium text-muted-foreground">Velikost:</span>
                <span className="text-foreground">{restoreBackup.size_formatted}</span>
              </div>
            </div>

            {createRestore.isError && (
              <p className="text-sm text-destructive">
                {createRestore.error instanceof Error
                  ? createRestore.error.message
                  : 'Vytvoření restore jobu selhalo.'}
              </p>
            )}

            <div className="flex justify-end gap-2 pt-2">
              <Button
                type="button"
                variant="outline"
                onClick={() => {
                  setRestoreBackup(null);
                  setProjectName('');
                }}
              >
                Zrušit
              </Button>
              <Button type="submit" disabled={createRestore.isPending}>
                {createRestore.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
                Obnovit jako projekt
              </Button>
            </div>
          </form>
        )}
      </Dialog>

      <ConfirmDeleteDialog
        open={!!deleteBackup}
        onClose={() => setDeleteBackup(null)}
        onConfirm={async () => {
          if (!deleteBackup) return;
          try {
            await deleteBackupMutation.mutateAsync(deleteBackup.path);
            toast.success('Záloha smazána', `Soubor ${deleteBackup.name} byl trvale smazán.`);
            setDeleteBackup(null);
          } catch (err) {
            toast.error('Chyba', err instanceof Error ? err.message : 'Smazání zálohy selhalo.');
          }
        }}
        title="Smazat zálohu"
        description={`Opravdu chcete trvale smazat zálohu „${deleteBackup?.name ?? ''}"? Tento soubor bude nenávratně odstraněn z disku. Tuto akci NELZE vrátit zpět.`}
        entityName={deleteBackup?.name ?? ''}
      />
    </div>
  );
}
