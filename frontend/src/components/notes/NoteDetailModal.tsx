import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Trash2 } from 'lucide-react';
import { api } from '@/lib/api';
import { fmtDateTime } from '@/lib/utils';
import { type Note } from '@/hooks/useNotes';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { DetailModal, type DetailModalTab } from '@/components/shared/DetailModal';
import { NoteFormDialog } from '@/components/notes/NoteFormDialog';
import { ConfirmDeleteDialog } from '@/components/shared/ConfirmDeleteDialog';
import { useState } from 'react';
import { Users, FolderKanban, ListTodo, FileText } from 'lucide-react';

const ENTITY_ICONS: Record<string, typeof Users> = {
  client: Users,
  project: FolderKanban,
  task: ListTodo,
  invoice: FileText,
};

const ENTITY_LABELS: Record<string, string> = {
  client: 'Klient',
  project: 'Projekt',
  task: 'Úkol',
  invoice: 'Faktura',
};

interface NoteDetailModalProps {
  open: boolean;
  onClose: () => void;
  noteId: number | null;
}

export function NoteDetailModal({ open, onClose, noteId }: NoteDetailModalProps) {
  const qc = useQueryClient();
  const [editOpen, setEditOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);

  const { data: note, isLoading } = useQuery<Note>({
    queryKey: ['note', noteId],
    queryFn: () => api.get<Note>(`/notes/${noteId}`),
    enabled: open && !!noteId,
  });

  const handleEditClose = () => {
    setEditOpen(false);
    qc.invalidateQueries({ queryKey: ['note', noteId] });
    qc.invalidateQueries({ queryKey: ['notes'] });
  };

  const handleDeleteClose = () => {
    setDeleteOpen(false);
    onClose();
  };

  if (!open || !noteId) return null;

  const tabs: DetailModalTab[] = [
    {
      id: 'content',
      label: 'Obsah',
      content: (
        <div className="space-y-4">
          {isLoading ? (
            <p className="text-muted-foreground">Načítání...</p>
          ) : !note ? (
            <p className="text-destructive">Poznámka nebyla nalezena.</p>
          ) : (
            <>
              <div className="text-sm text-muted-foreground">
                Vytvořeno {fmtDateTime(note.created_at)}
                {note.user_name && <> · {note.user_name}</>}
                {note.updated_at !== note.created_at && (
                  <> · Upraveno {fmtDateTime(note.updated_at)}</>
                )}
              </div>
              <div className="whitespace-pre-wrap text-sm leading-relaxed">
                {note.content}
              </div>
            </>
          )}
        </div>
      ),
    },
    {
      id: 'attachments',
      label: 'Vazby',
      content: (
        <div className="space-y-3">
          {isLoading ? (
            <p className="text-muted-foreground">Načítání...</p>
          ) : !note || !note.attachments || note.attachments.length === 0 ? (
            <p className="text-muted-foreground">Poznámka nemá žádné vazby.</p>
          ) : (
            <div className="flex flex-wrap gap-2">
              {note.attachments.map((a) => {
                const Icon = ENTITY_ICONS[a.entity_type] ?? FileText;
                return (
                  <Badge key={`${a.entity_type}-${a.entity_id}`} variant="secondary" className="gap-1">
                    <Icon className="h-3 w-3" />
                    {a.entity_name ?? `${ENTITY_LABELS[a.entity_type] ?? a.entity_type} #${a.entity_id}`}
                  </Badge>
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
        title={note?.title || 'Bez nadpisu'}
        subtitle={note ? `Vytvořeno ${fmtDateTime(note.created_at)}` : undefined}
        actions={
          <>
            <Button variant="outline" size="sm" onClick={() => setEditOpen(true)}>
              <Pencil className="h-3.5 w-3.5" />
              Upravit
            </Button>
            <Button variant="outline" size="sm" onClick={() => setDeleteOpen(true)}>
              <Trash2 className="h-3.5 w-3.5" />
              Smazat
            </Button>
          </>
        }
        tabs={tabs}
        size="md"
      />

      {note && (
        <NoteFormDialog
          open={editOpen}
          onClose={handleEditClose}
          note={note}
        />
      )}

      <ConfirmDeleteDialog
        open={deleteOpen}
        onClose={() => setDeleteOpen(false)}
        onConfirm={handleDeleteClose}
        title="Smazat poznámku"
        description={`Opravdu chcete smazat poznámku „${note?.title || note?.content?.slice(0, 50) || ''}"? Tuto akci NELZE vrátit zpět.`}
        entityName={note?.title || note?.content?.slice(0, 30) || ''}
      />
    </>
  );
}
