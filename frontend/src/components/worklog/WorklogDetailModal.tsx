import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Trash2, FolderKanban, Users, Clock, CheckCircle2, Circle } from 'lucide-react';
import { api } from '@/lib/api';
import { fmtDateTime } from '@/lib/utils';
import { type WorklogEntry, type WorklogCategory, type WorklogSeverity } from '@/hooks/useWorklog';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { DetailModal, type DetailModalTab } from '@/components/shared/DetailModal';
import { WorklogFormDialog } from '@/components/worklog/WorklogFormDialog';
import { ConfirmDeleteDialog } from '@/components/shared/ConfirmDeleteDialog';
import { Lightbox } from '@/components/worklog/Lightbox';

const CATEGORY_LABELS: Record<WorklogCategory, string> = {
  project: 'Projekt',
  security: 'Bezpečnost',
  maintenance: 'Údržba',
  meeting: 'Schůzka',
  other: 'Jiné',
};

const SEVERITY_VARIANTS: Record<WorklogSeverity, 'default' | 'secondary' | 'destructive'> = {
  info: 'secondary',
  warning: 'default',
  critical: 'destructive',
};

const SEVERITY_LABELS: Record<WorklogSeverity, string> = {
  info: 'Info',
  warning: 'Varování',
  critical: 'Kritické',
};

interface WorklogDetailModalProps {
  open: boolean;
  onClose: () => void;
  entryId: number | null;
}

export function WorklogDetailModal({ open, onClose, entryId }: WorklogDetailModalProps) {
  const qc = useQueryClient();
  const [editOpen, setEditOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [lightboxIndex, setLightboxIndex] = useState<number | null>(null);

  const { data: entry, isLoading, isError, error } = useQuery<WorklogEntry>({
    queryKey: ['worklog-entry', entryId],
    queryFn: () => api.get<WorklogEntry>(`/worklog/${entryId}`),
    enabled: !!entryId,
  });

  const handleEditClose = () => {
    setEditOpen(false);
    qc.invalidateQueries({ queryKey: ['worklog-entry', entryId] });
    qc.invalidateQueries({ queryKey: ['worklog'] });
  };

  const handleDeleteClose = () => {
    setDeleteOpen(false);
    onClose();
  };

  if (!open || !entryId) return null;

  if (isLoading) {
    return (
      <DetailModal
        open={open}
        onClose={onClose}
        title="Načítání..."
        tabs={[{ id: 'detail', label: 'Detail', content: <div className="py-8 text-center text-muted-foreground">Načítání záznamu...</div> }]}
      />
    );
  }

  if (isError || !entry) {
    return (
      <DetailModal
        open={open}
        onClose={onClose}
        title="Chyba"
        tabs={[{ id: 'detail', label: 'Detail', content: <div className="py-8 text-center text-destructive">Chyba při načítání: {error instanceof Error ? error.message : 'Neznámá chyba'}</div> }]}
      />
    );
  }

  const tabs: DetailModalTab[] = [
    {
      id: 'detail',
      label: 'Detail',
      content: (
        <div className="space-y-4">
          {/* Meta */}
          <div className="flex flex-wrap items-center gap-2">
            <Badge variant={SEVERITY_VARIANTS[entry.severity]}>
              {SEVERITY_LABELS[entry.severity]}
            </Badge>
            <Badge variant="outline">{CATEGORY_LABELS[entry.category]}</Badge>
            {entry.is_done ? (
              <Badge variant="secondary" className="gap-1">
                <CheckCircle2 className="h-3 w-3" /> Hotovo
              </Badge>
            ) : (
              <Badge variant="outline" className="gap-1">
                <Circle className="h-3 w-3" /> Otevřené
              </Badge>
            )}
            {entry.hours != null && (
              <Badge variant="outline" className="gap-1">
                <Clock className="h-3 w-3" /> {entry.hours} h
              </Badge>
            )}
          </div>

          {/* Vazby */}
          {(entry.project_name || entry.client_name) && (
            <div className="flex flex-wrap gap-3 text-sm">
              {entry.project_name && (
                <div className="flex items-center gap-1.5">
                  <FolderKanban className="h-4 w-4 text-muted-foreground" />
                  <span>{entry.project_name}</span>
                </div>
              )}
              {entry.client_name && (
                <div className="flex items-center gap-1.5">
                  <Users className="h-4 w-4 text-muted-foreground" />
                  <span>{entry.client_name}</span>
                </div>
              )}
            </div>
          )}

          {/* Popis */}
          {entry.description ? (
            <div className="whitespace-pre-wrap rounded-md border bg-muted/30 p-4 text-sm">
              {entry.description}
            </div>
          ) : (
            <p className="text-sm text-muted-foreground">Bez popisu.</p>
          )}

          {/* Přílohy */}
          {entry.attachments && entry.attachments.length > 0 && (
            <div className="space-y-3">
              <h4 className="text-sm font-medium">Přílohy ({entry.attachments.length})</h4>
              <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
                {entry.attachments.map((att, idx) => {
                  const url = `/api/worklog/attachments/${att.id}/file`;
                  if (att.is_image) {
                    return (
                      <button
                        key={att.id}
                        type="button"
                        onClick={() => setLightboxIndex(idx)}
                        className="group relative aspect-square overflow-hidden rounded-md border bg-muted hover:ring-2 hover:ring-primary"
                        title={att.original_name}
                      >
                        <img
                          src={url}
                          alt={att.original_name}
                          className="h-full w-full object-cover transition-transform group-hover:scale-105"
                          loading="lazy"
                        />
                        <div className="absolute inset-x-0 bottom-0 bg-black/60 px-2 py-1 text-left text-xs text-white truncate">
                          {att.original_name}
                        </div>
                      </button>
                    );
                  }
                  return (
                    <a
                      key={att.id}
                      href={url}
                      download={att.original_name}
                      className="flex aspect-square flex-col items-center justify-center gap-2 rounded-md border bg-muted p-3 text-center hover:ring-2 hover:ring-primary"
                      title={att.original_name}
                    >
                      <div className="text-2xl font-bold text-muted-foreground">
                        {att.original_name.split('.').pop()?.toUpperCase().slice(0, 3) ?? '???'}
                      </div>
                      <div className="text-xs text-muted-foreground truncate w-full">{att.original_name}</div>
                      <div className="text-xs text-muted-foreground">{(att.size_bytes / 1024).toFixed(0)} KB</div>
                    </a>
                  );
                })}
              </div>
            </div>
          )}

          {/* Časové údaje */}
          <div className="flex flex-wrap gap-4 text-xs text-muted-foreground border-t pt-3">
            <span>Vytvořeno: {fmtDateTime(entry.created_at)}</span>
            {entry.done_at && <span>Dokončeno: {fmtDateTime(entry.done_at)}</span>}
            <span>Aktualizováno: {fmtDateTime(entry.updated_at)}</span>
          </div>
        </div>
      ),
    },
  ];

  return (
    <>
      <DetailModal
        open={open}
        onClose={onClose}
        title={entry.title}
        subtitle={entry.user_name ? `Autor: ${entry.user_name}` : undefined}
        badges={
          <Badge variant={SEVERITY_VARIANTS[entry.severity]}>
            {SEVERITY_LABELS[entry.severity]}
          </Badge>
        }
        actions={
          <>
            <Button variant="outline" size="sm" onClick={() => setEditOpen(true)}>
              <Pencil className="h-4 w-4" />
              Upravit
            </Button>
            <Button variant="outline" size="sm" onClick={() => setDeleteOpen(true)} className="text-destructive">
              <Trash2 className="h-4 w-4" />
              Smazat
            </Button>
          </>
        }
        tabs={tabs}
      />

      <WorklogFormDialog open={editOpen} onClose={handleEditClose} entry={entry} />

      <ConfirmDeleteDialog
        open={deleteOpen}
        onClose={() => setDeleteOpen(false)}
        onConfirm={async () => {
          await api.delete(`/worklog/${entryId}`);
          handleDeleteClose();
        }}
        title="Smazat záznam"
        description={`Opravdu chcete smazat záznam „${entry.title}"? Tuto akci NELZE vrátit zpět.`}
        entityName={entry.title}
      />

      {lightboxIndex !== null && entry.attachments && (
        <Lightbox
          images={entry.attachments
            .filter((a) => a.is_image)
            .map((a) => ({
              src: `/api/worklog/attachments/${a.id}/file`,
              alt: a.original_name,
            }))}
          index={Math.max(0, lightboxIndex)}
          onClose={() => setLightboxIndex(null)}
          onIndexChange={setLightboxIndex}
        />
      )}
    </>
  );
}
