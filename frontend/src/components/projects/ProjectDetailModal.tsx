import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Trash2, Archive, RotateCcw, Folder, DollarSign, ExternalLink, Key, Plus, Eye, EyeOff, Copy } from 'lucide-react';
import { api, ApiError } from '@/lib/api';
import { fmtDate, fmtDateTime, fmtMoney, fmtMinutes, fmtBytes } from '@/lib/utils';
import { type Project } from '@/hooks/useProjects';
import { useTasks, type Task } from '@/hooks/useTasks';
import { useInvoices, type Invoice } from '@/hooks/useInvoices';
import { useTransactions, type Transaction } from '@/hooks/useTransactions';
import { useNotes } from '@/hooks/useNotes';
import { useFiles } from '@/hooks/useFiles';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { DetailModal, type DetailModalTab } from '@/components/shared/DetailModal';
import { ProjectFormDialog } from '@/components/projects/ProjectFormDialog';
import { CredentialFormDialog } from '@/components/projects/CredentialFormDialog';
import { useProjectCredentials, useDeleteCredential, CREDENTIAL_TYPE_LABELS, type ProjectCredential } from '@/hooks/useProjectCredentials';
import { TransactionFormDialog } from '@/components/finance/TransactionFormDialog';
import { ConfirmDeleteDialog } from '@/components/shared/ConfirmDeleteDialog';
import { ConfirmDialog } from '@/components/shared/ConfirmDialog';

type BadgeVariant = 'default' | 'secondary' | 'destructive' | 'outline';

function projectStatusBadge(status: Project['status']): { variant: BadgeVariant; label: string } {
  switch (status) {
    case 'active':
      return { variant: 'default', label: 'Aktivní' };
    case 'on_hold':
      return { variant: 'secondary', label: 'Pozastaveno' };
    case 'completed':
      return { variant: 'secondary', label: 'Dokončeno' };
    case 'cancelled':
      return { variant: 'destructive', label: 'Zrušeno' };
    case 'archived':
      return { variant: 'outline', label: 'Archivováno' };
    default:
      return { variant: 'outline', label: status };
  }
}

function taskStatusBadge(status: Task['status']): { variant: BadgeVariant; label: string } {
  switch (status) {
    case 'todo':
      return { variant: 'secondary', label: 'K vyřešení' };
    case 'in_progress':
      return { variant: 'default', label: 'V řešení' };
    case 'done':
      return { variant: 'secondary', label: 'Hotové' };
    case 'cancelled':
      return { variant: 'destructive', label: 'Zrušeno' };
    default:
      return { variant: 'outline', label: status };
  }
}

function priorityBadge(priority: Task['priority']): { variant: BadgeVariant; label: string } {
  switch (priority) {
    case 'low':
      return { variant: 'secondary', label: 'Nízká' };
    case 'medium':
      return { variant: 'default', label: 'Střední' };
    case 'high':
      return { variant: 'secondary', label: 'Vysoká' };
    case 'urgent':
      return { variant: 'destructive', label: 'Urgentní' };
    default:
      return { variant: 'outline', label: priority };
  }
}

function invoiceStatusBadge(status: Invoice['status']): { variant: BadgeVariant; label: string } {
  switch (status) {
    case 'draft':
      return { variant: 'secondary', label: 'Koncept' };
    case 'sent':
      return { variant: 'default', label: 'Odesláno' };
    case 'paid':
      return { variant: 'default', label: 'Zaplaceno' };
    case 'overdue':
      return { variant: 'destructive', label: 'Po splatnosti' };
    case 'cancelled':
      return { variant: 'outline', label: 'Zrušeno' };
    default:
      return { variant: 'outline', label: status };
  }
}

function transactionTypeBadge(type: Transaction['type']): { variant: BadgeVariant; label: string } {
  switch (type) {
    case 'income':
      return { variant: 'default', label: 'Příjem' };
    case 'expense':
      return { variant: 'destructive', label: 'Výdaj' };
    default:
      return { variant: 'outline', label: type };
  }
}

function DetailRow({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5 py-2 border-b border-border last:border-0">
      <dt className="text-xs font-medium text-muted-foreground">{label}</dt>
      <dd className="text-sm whitespace-pre-wrap">{value || '—'}</dd>
    </div>
  );
}

function LoadingState({ text }: { text: string }) {
  return <div className="py-10 text-center text-sm text-muted-foreground">{text}</div>;
}

function ErrorState({ message }: { message: string }) {
  return <div className="py-10 text-center text-sm text-destructive">{message}</div>;
}

function EmptyState({ text }: { text: string }) {
  return <div className="py-10 text-center text-sm text-muted-foreground">{text}</div>;
}

interface ProjectDetailModalProps {
  open: boolean;
  onClose: () => void;
  projectId: number | null;
}

export function ProjectDetailModal({ open, onClose, projectId }: ProjectDetailModalProps) {
  const qc = useQueryClient();
  const [formOpen, setFormOpen] = useState(false);
  const [paymentOpen, setPaymentOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [archiveOpen, setArchiveOpen] = useState(false);
  const [restoreOpen, setRestoreOpen] = useState(false);
  const [credFormOpen, setCredFormOpen] = useState(false);
  const [editCredential, setEditCredential] = useState<ProjectCredential | null>(null);
  const [deleteCredential, setDeleteCredential] = useState<ProjectCredential | null>(null);
  const [revealedPasswords, setRevealedPasswords] = useState<Record<number, boolean>>({});

  const { data: project, isLoading, isError, error } = useQuery<Project>({
    queryKey: ['project', projectId],
    queryFn: () => api.get<Project>(`/projects/${projectId}`),
    enabled: open && !!projectId,
  });

  const isNotFound = isError && error instanceof ApiError && error.status === 404;

  const { data: tasksData, isLoading: tasksLoading, isError: tasksError } = useTasks({
    project_id: projectId ?? undefined,
    per_page: 200,
    enabled: open && !!projectId,
  });
  const { data: invoicesData, isLoading: invoicesLoading, isError: invoicesError } = useInvoices({
    project_id: projectId ?? undefined,
    per_page: 200,
    enabled: open && !!projectId,
  });
  const { data: transactionsData, isLoading: transactionsLoading, isError: transactionsError } = useTransactions({
    project_id: projectId ?? undefined,
    per_page: 200,
    enabled: open && !!projectId,
  });
  const { data: notesData, isLoading: notesLoading, isError: notesError } = useNotes({
    entity_type: 'project',
    entity_id: projectId ?? undefined,
    per_page: 200,
    enabled: open && !!projectId,
  });
  const { data: filesData, isLoading: filesLoading, isError: filesError } = useFiles({
    entity_type: 'project',
    entity_id: projectId ?? undefined,
    per_page: 200,
    enabled: open && !!projectId,
  });

  const { data: credentialsData } = useProjectCredentials(project?.id ?? null);
  const deleteCredentialMutation = useDeleteCredential();
  const credentials: ProjectCredential[] = credentialsData ?? [];

  const handleDelete = async () => {
    if (!projectId) return;
    try {
      await api.delete(`/projects/${projectId}`);
      qc.invalidateQueries({ queryKey: ['projects'] });
      onClose();
    } catch {
      // chyba se zobrazí v dialogu
    }
  };

  const handleArchive = async () => {
    if (!projectId) return;
    try {
      await api.post(`/projects/${projectId}/archive`);
      qc.invalidateQueries({ queryKey: ['projects'] });
      qc.invalidateQueries({ queryKey: ['project', projectId] });
      setArchiveOpen(false);
    } catch {
      // chyba
    }
  };

  const handleRestore = async () => {
    if (!projectId) return;
    try {
      await api.post(`/projects/${projectId}/restore`);
      qc.invalidateQueries({ queryKey: ['projects'] });
      qc.invalidateQueries({ queryKey: ['project', projectId] });
      setRestoreOpen(false);
    } catch {
      // chyba
    }
  };

  // Loading stav
  if (open && isLoading) {
    return (
      <DetailModal
        open={open}
        onClose={onClose}
        title="Načítání..."
        tabs={[{ id: 'detail', label: 'Detail', content: <LoadingState text="Načítání projektu..." /> }]}
      />
    );
  }

  // 404 stav
  if (open && isNotFound) {
    return (
      <DetailModal
        open={open}
        onClose={onClose}
        title="Projekt nenalezen"
        tabs={[{ id: 'detail', label: 'Detail', content: <EmptyState text="Projekt neexistuje nebo byl smazán." /> }]}
      />
    );
  }

  // Error stav
  if (open && isError) {
    return (
      <DetailModal
        open={open}
        onClose={onClose}
        title="Chyba"
        tabs={[{ id: 'detail', label: 'Detail', content: <ErrorState message={error instanceof Error ? error.message : 'Neznámá chyba'} /> }]}
      />
    );
  }

  if (!open || !project) return null;

  const statusBadge = projectStatusBadge(project.status);
  const isArchived = project.status === 'archived';
  const tasks = tasksData?.data ?? [];
  const invoices = invoicesData?.data ?? [];
  const transactions = transactionsData?.data ?? [];
  const notes = notesData?.data ?? [];
  const files = filesData?.data ?? [];

  const tabs: DetailModalTab[] = [
    {
      id: 'detail',
      label: 'Detail',
      content: (
        <Card>
          <CardContent>
            <dl>
              <DetailRow
                label="Klient"
                value={project.client_name ?? '—'}
              />
              <DetailRow label="Status" value={statusBadge.label} />
              <DetailRow label="Popis" value={project.description} />
              <DetailRow
                label="Rozpočet"
                value={project.budget_cents ? fmtMoney(project.budget_cents) : '—'}
              />
              {(() => {
                const income = (transactionsData?.data ?? [])
                  .filter((t) => t.type === 'income')
                  .reduce((sum, t) => sum + t.amount_cents, 0);
                const budget = project.budget_cents ?? 0;
                const remaining = budget - income;
                return (
                  <>
                    <DetailRow
                      label="Zaplaceno"
                      value={income > 0 ? fmtMoney(income) : '—'}
                    />
                    {budget > 0 && (
                      <DetailRow
                        label="Zbývá zaplatit"
                        value={
                          remaining > 0 ? (
                            <span className="text-destructive">{fmtMoney(remaining)}</span>
                          ) : remaining === 0 ? (
                            <span className="text-green-600">Zaplaceno</span>
                          ) : (
                            <span className="text-green-600">+{fmtMoney(Math.abs(remaining))}</span>
                          )
                        }
                      />
                    )}
                  </>
                );
              })()}
              <DetailRow label="Začátek" value={project.started_at ? fmtDate(project.started_at) : '—'} />
              <DetailRow label="Termín" value={project.deadline ? fmtDate(project.deadline) : '—'} />
              <DetailRow label="Zdroj" value={project.folder_path ? 'Složka' : 'Ruční'} />
              <DetailRow label="Vytvořeno" value={fmtDateTime(project.created_at)} />
            </dl>
          </CardContent>
        </Card>
      ),
    },
    {
      id: 'tasks',
      label: `Úkoly (${tasks.length})`,
      content: tasksLoading ? (
        <LoadingState text="Načítání úkolů..." />
      ) : tasksError ? (
        <ErrorState message="Chyba při načítání úkolů." />
      ) : tasks.length === 0 ? (
        <EmptyState text="Žádné úkoly." />
      ) : (
        <ul className="divide-y divide-border">
          {tasks.map((t) => {
            const sbadge = taskStatusBadge(t.status);
            const pbadge = priorityBadge(t.priority);
            return (
              <li key={t.id} className="py-3">
                <div className="flex items-center justify-between gap-2">
                  <span className="font-medium">{t.title}</span>
                  <div className="flex items-center gap-1">
                    <Badge variant={sbadge.variant}>{sbadge.label}</Badge>
                    <Badge variant={pbadge.variant}>{pbadge.label}</Badge>
                  </div>
                </div>
                <div className="mt-1 flex items-center gap-4 text-xs text-muted-foreground">
                  <span>Termín: {t.due_date ? fmtDate(t.due_date) : '—'}</span>
                  <span>Odhad: {fmtMinutes(t.estimated_minutes)}</span>
                  <span>Stráveno: {fmtMinutes(t.spent_minutes)}</span>
                </div>
              </li>
            );
          })}
        </ul>
      ),
    },
    {
      id: 'invoices',
      label: `Faktury (${invoices.length})`,
      content: invoicesLoading ? (
        <LoadingState text="Načítání faktur..." />
      ) : invoicesError ? (
        <ErrorState message="Chyba při načítání faktur." />
      ) : invoices.length === 0 ? (
        <EmptyState text="Žádné faktury." />
      ) : (
        <ul className="divide-y divide-border">
          {invoices.map((inv) => {
            const badge = invoiceStatusBadge(inv.status);
            return (
              <li key={inv.id} className="py-3">
                <div className="flex items-center justify-between gap-2">
                  <span className="font-medium">{inv.invoice_number}</span>
                  <Badge variant={badge.variant}>{badge.label}</Badge>
                </div>
                <div className="mt-1 flex items-center gap-4 text-xs text-muted-foreground">
                  <span>Částka: {fmtMoney(inv.amount_cents)}</span>
                  <span>Splatnost: {fmtDate(inv.due_date)}</span>
                </div>
              </li>
            );
          })}
        </ul>
      ),
    },
    {
      id: 'transactions',
      label: `Transakce (${transactions.length})`,
      content: transactionsLoading ? (
        <LoadingState text="Načítání transakcí..." />
      ) : transactionsError ? (
        <ErrorState message="Chyba při načítání transakcí." />
      ) : transactions.length === 0 ? (
        <EmptyState text="Žádné transakce." />
      ) : (
        <ul className="divide-y divide-border">
          {transactions.map((t) => {
            const badge = transactionTypeBadge(t.type);
            return (
              <li key={t.id} className="py-3">
                <div className="flex items-center justify-between gap-2">
                  <div className="flex items-center gap-2">
                    <Badge variant={badge.variant}>{badge.label}</Badge>
                    <span className="text-sm">{t.description || '—'}</span>
                  </div>
                  <span className={t.type === 'income' ? 'text-sm font-medium text-emerald-600' : 'text-sm font-medium text-destructive'}>
                    {t.type === 'income' ? '+' : '−'}{fmtMoney(t.amount_cents)}
                  </span>
                </div>
                <div className="mt-1 text-xs text-muted-foreground">
                  {fmtDate(t.transaction_date)}
                </div>
              </li>
            );
          })}
        </ul>
      ),
    },
    {
      id: 'notes',
      label: `Poznámky (${notes.length})`,
      content: notesLoading ? (
        <LoadingState text="Načítání poznámek..." />
      ) : notesError ? (
        <ErrorState message="Chyba při načítání poznámek." />
      ) : notes.length === 0 ? (
        <EmptyState text="Žádné poznámky." />
      ) : (
        <ul className="divide-y divide-border">
          {notes.map((n) => (
            <li key={n.id} className="py-3">
              {n.title && <div className="font-medium">{n.title}</div>}
              <div className="text-sm text-muted-foreground whitespace-pre-wrap">{n.content}</div>
              <div className="mt-1 text-xs text-muted-foreground">
                {fmtDateTime(n.created_at)}
                {n.user_name && ` · ${n.user_name}`}
              </div>
            </li>
          ))}
        </ul>
      ),
    },
    {
      id: 'files',
      label: `Soubory (${files.length})`,
      content: filesLoading ? (
        <LoadingState text="Načítání souborů..." />
      ) : filesError ? (
        <ErrorState message="Chyba při načítání souborů." />
      ) : files.length === 0 ? (
        <EmptyState text="Žádné soubory." />
      ) : (
        <ul className="divide-y divide-border">
          {files.map((f) => (
            <li key={f.id} className="flex items-center gap-3 py-3">
              {f.is_image && f.thumbnail_path ? (
                <img
                  src={`/api/files/${f.id}/thumbnail`}
                  alt={f.original_name}
                  className="h-12 w-12 rounded object-cover"
                  loading="lazy"
                  width={48}
                  height={48}
                />
              ) : (
                <div className="flex h-12 w-12 items-center justify-center rounded bg-muted text-xs text-muted-foreground">
                  {f.mime_type.split('/')[0] === 'image' ? 'IMG' : f.original_name.split('.').pop()?.toUpperCase().slice(0, 4) || 'FILE'}
                </div>
              )}
              <div className="flex-1 min-w-0">
                <div className="truncate text-sm font-medium">{f.original_name}</div>
                <div className="text-xs text-muted-foreground">
                  {f.mime_type} · {fmtBytes(f.size_bytes)}
                </div>
              </div>
            </li>
          ))}
        </ul>
      ),
    },
    {
      id: 'credentials',
      label: 'Přístupy',
      content: (
        <div className="space-y-3">
          <div className="flex items-center justify-between">
            <h4 className="text-sm font-medium">Přístupy k projektu ({credentials.length})</h4>
            <Button
              variant="outline"
              size="sm"
              onClick={() => { setEditCredential(null); setCredFormOpen(true); }}
            >
              <Plus className="h-4 w-4" />
              Přidat přístup
            </Button>
          </div>
          {credentials.length === 0 ? (
            <p className="py-6 text-center text-sm text-muted-foreground">
              Žádné přístupy k projektu. Klikněte „Přidat přístup" pro vytvoření.
            </p>
          ) : (
            <div className="space-y-2">
              {credentials.map((cred) => {
                const revealed = revealedPasswords[cred.id] ?? false;
                const typeLabel = CREDENTIAL_TYPE_LABELS[cred.type] ?? cred.type;
                return (
                  <Card key={cred.id}>
                    <CardContent className="space-y-2">
                      <div className="flex items-start justify-between gap-2">
                        <div className="flex items-center gap-2">
                          <Key className="h-4 w-4 text-muted-foreground" />
                          <span className="font-medium">{cred.name}</span>
                          <Badge variant="secondary">{typeLabel}</Badge>
                        </div>
                        <div className="flex gap-1">
                          <Button
                            variant="ghost"
                            size="icon"
                            className="h-7 w-7"
                            onClick={() => { setEditCredential(cred); setCredFormOpen(true); }}
                            title="Upravit"
                          >
                            <Pencil className="h-3.5 w-3.5" />
                          </Button>
                          <Button
                            variant="ghost"
                            size="icon"
                            className="h-7 w-7 text-destructive"
                            onClick={() => setDeleteCredential(cred)}
                            title="Smazat"
                          >
                            <Trash2 className="h-3.5 w-3.5" />
                          </Button>
                        </div>
                      </div>
                      <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                        {cred.host && (
                          <>
                            <dt className="text-muted-foreground">Host</dt>
                            <dd className="min-w-0 break-all font-mono">{cred.host}{cred.port ? `:${cred.port}` : ''}</dd>
                          </>
                        )}
                        {cred.username && (
                          <>
                            <dt className="text-muted-foreground">Uživatel</dt>
                            <dd className="min-w-0 break-all font-mono">{cred.username}</dd>
                          </>
                        )}
                        {cred.password && (
                          <>
                            <dt className="text-muted-foreground">Heslo</dt>
                            <dd className="flex min-w-0 items-center gap-1 font-mono">
                              <span className="truncate">{revealed ? cred.password : '••••••••'}</span>
                              <Button
                                variant="ghost"
                                size="icon"
                                className="h-6 w-6 shrink-0"
                                onClick={() => setRevealedPasswords((prev) => ({ ...prev, [cred.id]: !prev[cred.id] }))}
                                title={revealed ? 'Skrýt' : 'Zobrazit'}
                              >
                                {revealed ? <EyeOff className="h-3 w-3" /> : <Eye className="h-3 w-3" />}
                              </Button>
                              <Button
                                variant="ghost"
                                size="icon"
                                className="h-6 w-6 shrink-0"
                                onClick={() => navigator.clipboard.writeText(cred.password ?? '')}
                                title="Kopírovat"
                              >
                                <Copy className="h-3 w-3" />
                              </Button>
                            </dd>
                          </>
                        )}
                        {cred.database_name && (
                          <>
                            <dt className="text-muted-foreground">Databáze</dt>
                            <dd className="min-w-0 break-all font-mono">{cred.database_name}</dd>
                          </>
                        )}
                        {cred.note && (
                          <>
                            <dt className="text-muted-foreground">Poznámka</dt>
                            <dd className="min-w-0 break-words">{cred.note}</dd>
                          </>
                        )}
                      </dl>
                    </CardContent>
                  </Card>
                );
              })}
            </div>
          )}
        </div>
      ),
    },
  ];

  return (
    <>
      <DetailModal
        open={open}
        onClose={onClose}
        title={project.name}
        badges={
          <>
            <Badge variant={statusBadge.variant}>{statusBadge.label}</Badge>
            {project.folder_path && (
              <Badge variant="outline" className="gap-1">
                <Folder className="h-3 w-3" />
                Ze složky
              </Badge>
            )}
          </>
        }
        actions={
          <>
            {project.folder_path && (
              <Button
                variant="outline"
                size="sm"
                onClick={() => window.open(`https://${project.folder_path}.localhost/`, '_blank')}
              >
                <ExternalLink className="h-4 w-4" />
                Náhled
              </Button>
            )}
            <Button variant="outline" size="sm" onClick={() => setPaymentOpen(true)}>
              <DollarSign className="h-4 w-4" />
              Zaznamenat platbu
            </Button>
            <Button variant="outline" size="sm" onClick={() => setFormOpen(true)}>
              <Pencil className="h-4 w-4" />
              Upravit
            </Button>
            {isArchived ? (
              <Button variant="outline" size="sm" onClick={() => setRestoreOpen(true)}>
                <RotateCcw className="h-4 w-4" />
                Obnovit
              </Button>
            ) : (
              <Button variant="outline" size="sm" onClick={() => setArchiveOpen(true)}>
                <Archive className="h-4 w-4" />
                Archivovat
              </Button>
            )}
            <Button variant="destructive" size="sm" onClick={() => setDeleteOpen(true)}>
              <Trash2 className="h-4 w-4" />
              Smazat
            </Button>
          </>
        }
        tabs={tabs}
        size="lg"
      />
      <ProjectFormDialog open={formOpen} onClose={() => setFormOpen(false)} project={project} />
      <TransactionFormDialog
        open={paymentOpen}
        onClose={() => setPaymentOpen(false)}
        presetProjectId={project.id}
        presetClientId={project.client_id ?? undefined}
      />
      <ConfirmDeleteDialog
        open={deleteOpen}
        onClose={() => setDeleteOpen(false)}
        onConfirm={handleDelete}
        title="Smazat projekt"
        description={`Opravdu chcete smazat projekt „${project.name}"? Všechny jeho úkoly, faktury, transakce, poznámky a soubory budou trvale odstraněny. Tuto akci NELZE vrátit zpět.`}
        entityName={project.name}
      />
      <ConfirmDialog
        open={archiveOpen}
        onClose={() => setArchiveOpen(false)}
        onConfirm={handleArchive}
        title="Archivovat projekt"
        description={`Opravdu chcete archivovat projekt „${project.name}"? Archivovaný projekt bude skryt, ale lze jej obnovit.`}
        confirmLabel="Archivovat"
      />
      <ConfirmDialog
        open={restoreOpen}
        onClose={() => setRestoreOpen(false)}
        onConfirm={handleRestore}
        title="Obnovit projekt"
        description={`Opravdu chcete obnovit projekt „${project.name}" z archivu?`}
        confirmLabel="Obnovit"
      />
      <CredentialFormDialog
        open={credFormOpen}
        onClose={() => setCredFormOpen(false)}
        projectId={project.id}
        credential={editCredential}
      />
      <ConfirmDeleteDialog
        open={!!deleteCredential}
        onClose={() => setDeleteCredential(null)}
        onConfirm={async () => {
          if (deleteCredential) {
            await deleteCredentialMutation.mutateAsync(deleteCredential.id);
            setDeleteCredential(null);
          }
        }}
        title="Smazat přístup"
        description={`Opravdu chcete smazat přístup „${deleteCredential?.name ?? ''}"?`}
        entityName={deleteCredential?.name ?? ''}
      />
    </>
  );
}
