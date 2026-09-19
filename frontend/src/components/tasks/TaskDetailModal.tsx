import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Trash2 } from 'lucide-react';
import { api, ApiError } from '@/lib/api';
import { fmtDate, fmtDateTime, fmtMinutes, fmtBytes } from '@/lib/utils';
import { type Task } from '@/hooks/useTasks';
import { useNotes } from '@/hooks/useNotes';
import { useFiles } from '@/hooks/useFiles';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { DetailModal, type DetailModalTab } from '@/components/shared/DetailModal';
import { TaskFormDialog } from '@/components/tasks/TaskFormDialog';
import { ConfirmDeleteDialog } from '@/components/shared/ConfirmDeleteDialog';

type BadgeVariant = 'default' | 'secondary' | 'destructive' | 'outline';

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

interface TaskDetailModalProps {
  open: boolean;
  onClose: () => void;
  taskId: number | null;
}

export function TaskDetailModal({ open, onClose, taskId }: TaskDetailModalProps) {
  const qc = useQueryClient();
  const [formOpen, setFormOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);

  const { data: task, isLoading, isError, error } = useQuery<Task>({
    queryKey: ['task', taskId],
    queryFn: () => api.get<Task>(`/tasks/${taskId}`),
    enabled: open && !!taskId,
  });

  const isNotFound = isError && error instanceof ApiError && error.status === 404;

  const { data: notesData, isLoading: notesLoading, isError: notesError } = useNotes({
    entity_type: 'task',
    entity_id: taskId ?? undefined,
    per_page: 200,
  });
  const { data: filesData, isLoading: filesLoading, isError: filesError } = useFiles({
    entity_type: 'task',
    entity_id: taskId ?? undefined,
    per_page: 200,
  });

  const handleDelete = async () => {
    if (!taskId) return;
    try {
      await api.delete(`/tasks/${taskId}`);
      qc.invalidateQueries({ queryKey: ['tasks'] });
      onClose();
    } catch {
      // chyba se zobrazí v dialogu
    }
  };

  // Loading stav
  if (open && isLoading) {
    return (
      <DetailModal
        open={open}
        onClose={onClose}
        title="Načítání..."
        tabs={[{ id: 'detail', label: 'Detail', content: <LoadingState text="Načítání úkolu..." /> }]}
      />
    );
  }

  // 404 stav
  if (open && isNotFound) {
    return (
      <DetailModal
        open={open}
        onClose={onClose}
        title="Úkol nenalezen"
        tabs={[{ id: 'detail', label: 'Detail', content: <EmptyState text="Úkol neexistuje nebo byl smazán." /> }]}
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

  if (!open || !task) return null;

  const sbadge = taskStatusBadge(task.status);
  const pbadge = priorityBadge(task.priority);
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
              <DetailRow label="Projekt" value={task.project_name ?? '—'} />
              <DetailRow label="Status" value={sbadge.label} />
              <DetailRow label="Priorita" value={pbadge.label} />
              <DetailRow label="Popis" value={task.description} />
              <DetailRow label="Termín" value={task.due_date ? fmtDate(task.due_date) : '—'} />
              <DetailRow label="Přiřazeno" value={task.assigned_to} />
              <DetailRow label="Odhad" value={fmtMinutes(task.estimated_minutes)} />
              <DetailRow label="Stráveno" value={fmtMinutes(task.spent_minutes)} />
              <DetailRow label="Vytvořeno" value={fmtDateTime(task.created_at)} />
            </dl>
          </CardContent>
        </Card>
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
  ];

  return (
    <>
      <DetailModal
        open={open}
        onClose={onClose}
        title={task.title}
        badges={
          <>
            <Badge variant={sbadge.variant}>{sbadge.label}</Badge>
            <Badge variant={pbadge.variant}>{pbadge.label}</Badge>
          </>
        }
        actions={
          <>
            <Button variant="outline" size="sm" onClick={() => setFormOpen(true)}>
              <Pencil className="h-4 w-4" />
              Upravit
            </Button>
            <Button variant="destructive" size="sm" onClick={() => setDeleteOpen(true)}>
              <Trash2 className="h-4 w-4" />
              Smazat
            </Button>
          </>
        }
        tabs={tabs}
        size="md"
      />
      <TaskFormDialog open={formOpen} onClose={() => setFormOpen(false)} task={task} />
      <ConfirmDeleteDialog
        open={deleteOpen}
        onClose={() => setDeleteOpen(false)}
        onConfirm={handleDelete}
        title="Smazat úkol"
        description={`Opravdu chcete smazat úkol „${task.title}"? Tuto akci NELZE vrátit zpět.`}
        entityName={task.title}
      />
    </>
  );
}
