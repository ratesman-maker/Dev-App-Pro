import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Trash2, Download, FileText, Users, FolderKanban, ListTodo } from 'lucide-react';
import { api } from '@/lib/api';
import { fmtDateTime, fmtBytes } from '@/lib/utils';
import { type FileItem, type FileAttachment } from '@/hooks/useFiles';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { DetailModal, type DetailModalTab } from '@/components/shared/DetailModal';
import { FileFormDialog } from '@/components/files/FileFormDialog';
import { ConfirmDeleteDialog } from '@/components/shared/ConfirmDeleteDialog';

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

interface FileDetailModalProps {
  open: boolean;
  onClose: () => void;
  fileId: number | null;
  onDelete?: (file: FileItem) => void;
}

export function FileDetailModal({ open, onClose, fileId, onDelete }: FileDetailModalProps) {
  const qc = useQueryClient();
  const [editOpen, setEditOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);

  const { data: file, isLoading, isError, error } = useQuery<FileItem>({
    queryKey: ['file', fileId],
    queryFn: () => api.get<FileItem>(`/files/${fileId}`),
    enabled: !!fileId,
  });

  const handleEditClose = () => {
    setEditOpen(false);
    qc.invalidateQueries({ queryKey: ['file', fileId] });
    qc.invalidateQueries({ queryKey: ['files'] });
  };

  const handleDeleteClose = () => {
    setDeleteOpen(false);
    if (file && onDelete) onDelete(file);
    onClose();
  };

  if (!open || !fileId) return null;

  if (isLoading) {
    return (
      <DetailModal
        open={open}
        onClose={onClose}
        title="Načítání..."
        tabs={[{ id: 'detail', label: 'Detail', content: <div className="py-8 text-center text-muted-foreground">Načítání souboru...</div> }]}
      />
    );
  }

  if (isError || !file) {
    return (
      <DetailModal
        open={open}
        onClose={onClose}
        title="Chyba"
        tabs={[{ id: 'detail', label: 'Detail', content: <div className="py-8 text-center text-destructive">Chyba při načítání: {error instanceof Error ? error.message : 'Neznámá chyba'}</div> }]}
      />
    );
  }

  const isImage = file.is_image || file.mime_type.startsWith('image/');

  const tabs: DetailModalTab[] = [
    {
      id: 'detail',
      label: 'Detail',
      content: (
        <div className="space-y-4">
          {/* Náhled obrázku */}
          {isImage && (
            <div className="overflow-hidden rounded-md border bg-muted">
              <img
                src={`/api/files/${file.id}/download`}
                alt={file.original_name}
                className="max-h-96 w-full object-contain"
                loading="lazy"
              />
            </div>
          )}

          {/* Vazby */}
          {file.attachments && file.attachments.length > 0 && (
            <div className="space-y-2">
              <h4 className="text-sm font-medium">Vazby</h4>
              <div className="flex flex-wrap gap-2">
                {file.attachments.map((a: FileAttachment) => {
                  const EntityIcon = ENTITY_ICONS[a.entity_type] ?? FileText;
                  return (
                    <Badge key={`${a.entity_type}-${a.entity_id}`} variant="secondary" className="gap-1">
                      <EntityIcon className="h-3 w-3" />
                      {a.entity_name ?? `${ENTITY_LABELS[a.entity_type] ?? a.entity_type} #${a.entity_id}`}
                    </Badge>
                  );
                })}
              </div>
            </div>
          )}

          {/* Metadata */}
          <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
            <dt className="text-muted-foreground">Typ souboru</dt>
            <dd className="font-medium">{file.mime_type}</dd>
            <dt className="text-muted-foreground">Velikost</dt>
            <dd className="font-medium">{fmtBytes(file.size_bytes)}</dd>
            <dt className="text-muted-foreground">Nahrál</dt>
            <dd className="font-medium">{file.user_name ?? '—'}</dd>
            <dt className="text-muted-foreground">Vytvořeno</dt>
            <dd className="font-medium">{fmtDateTime(file.created_at)}</dd>
          </dl>
        </div>
      ),
    },
  ];

  return (
    <>
      <DetailModal
        open={open}
        onClose={onClose}
        title={file.original_name}
        subtitle={fmtBytes(file.size_bytes)}
        actions={
          <>
            <Button
              variant="outline"
              size="sm"
              onClick={async () => {
                const blob = await api.getBlob(`/files/${file.id}/download`);
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = file.original_name;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                setTimeout(() => URL.revokeObjectURL(url), 60000);
              }}
            >
              <Download className="h-4 w-4" />
              Stáhnout
            </Button>
            <Button variant="outline" size="sm" onClick={() => setEditOpen(true)}>
              <Pencil className="h-4 w-4" />
              Upravit vazby
            </Button>
            <Button variant="outline" size="sm" onClick={() => setDeleteOpen(true)} className="text-destructive">
              <Trash2 className="h-4 w-4" />
              Smazat
            </Button>
          </>
        }
        tabs={tabs}
      />

      <FileFormDialog open={editOpen} onClose={handleEditClose} file={file} />

      <ConfirmDeleteDialog
        open={deleteOpen}
        onClose={() => setDeleteOpen(false)}
        onConfirm={async () => {
          await api.delete(`/files/${file.id}`);
          handleDeleteClose();
        }}
        title="Smazat soubor"
        description={`Opravdu chcete smazat soubor „${file.original_name}"? Tuto akci NELZE vrátit zpět.`}
        entityName={file.original_name}
      />
    </>
  );
}
