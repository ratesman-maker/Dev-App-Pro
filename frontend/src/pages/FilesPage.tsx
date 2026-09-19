import { useState, useCallback } from 'react';
import { useDebounce } from '@/hooks/useDebounce';
import { ColumnDef, SortingState } from '@tanstack/react-table';
import { Plus, Search, ChevronLeft, ChevronRight, FileText, Image as ImageIcon, File, Users, FolderKanban, ListTodo } from 'lucide-react';
import { useFiles, useDeleteFile, useDownloadFile, type FileItem } from '@/hooks/useFiles';
import { DataTable } from '@/components/shared/DataTable';
import { ActionButtons } from '@/components/shared/ActionButtons';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Select } from '@/components/ui/select';
import { Card, CardContent } from '@/components/ui/card';
import { FileFormDialog } from '@/components/files/FileFormDialog';
import { FileUploadDialog } from '@/components/files/FileUploadDialog';
import { FileDetailModal } from '@/components/files/FileDetailModal';
import { ConfirmDeleteDialog } from '@/components/shared/ConfirmDeleteDialog';
import { fmtDateTime, fmtBytes } from '@/lib/utils';
import { DEFAULT_PER_PAGE } from '@/lib/constants';

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

const ENTITY_FILTERS: { value: string; label: string }[] = [
  { value: '', label: 'Všechny vazby' },
  { value: 'client', label: 'Klienti' },
  { value: 'project', label: 'Projekty' },
  { value: 'task', label: 'Úkoly' },
  { value: 'invoice', label: 'Faktury' },
];

function fileIcon(mime_type: string, is_image: boolean): typeof File {
  if (is_image || mime_type.startsWith('image/')) return ImageIcon;
  if (mime_type === 'application/pdf') return FileText;
  return File;
}

export default function FilesPage() {
  const [search, setSearch] = useState('');
  const debouncedSearch = useDebounce(search, 300);
  const [entityFilter, setEntityFilter] = useState('');
  const [imagesOnly, setImagesOnly] = useState(false);
  const [page, setPage] = useState(1);
  const [sorting, setSorting] = useState<SortingState>([{ id: 'created_at', desc: true }]);
  const [uploadOpen, setUploadOpen] = useState(false);
  const [editFile, setEditFile] = useState<FileItem | null>(null);
  const [deleteFile, setDeleteFile] = useState<FileItem | null>(null);
  const [viewFileId, setViewFileId] = useState<number | null>(null);

  const { data, isLoading, isError, error } = useFiles({
    search: debouncedSearch || undefined,
    page,
    per_page: DEFAULT_PER_PAGE,
    entity_type: entityFilter || undefined,
    is_image: imagesOnly || undefined,
  });

  const deleteMutation = useDeleteFile();
  const downloadMutation = useDownloadFile();

  const handleDelete = useCallback(async () => {
    if (!deleteFile) return;
    try {
      await deleteMutation.mutateAsync(deleteFile.id);
      setDeleteFile(null);
    } catch {
      // chyba se zobrazí v stavu stránky
    }
  }, [deleteFile, deleteMutation]);

  const handleDownload = useCallback((file: FileItem) => {
    downloadMutation.mutate(file.id);
  }, [downloadMutation]);

  const totalPages = data ? Math.max(1, Math.ceil(data.total / data.per_page)) : 1;

  const columns: ColumnDef<FileItem>[] = [
    {
      accessorKey: 'original_name',
      header: 'Název',
      cell: ({ row }) => {
        const file = row.original;
        const isImg = file.is_image || file.mime_type.startsWith('image/');
        return (
          <div className="flex items-center gap-2">
            {isImg ? (
              <img
                src={`/api/files/${file.id}/thumbnail`}
                alt={file.original_name}
                loading="lazy"
                className="h-10 w-10 shrink-0 rounded object-cover border border-border"
              />
            ) : (
              (() => {
                const Icon = fileIcon(file.mime_type, file.is_image);
                return <Icon className="h-4 w-4 shrink-0 text-muted-foreground" />;
              })()
            )}
            <span className="font-medium truncate">{file.original_name}</span>
          </div>
        );
      },
    },
    {
      accessorKey: 'mime_type',
      header: 'Typ',
      enableSorting: false,
      cell: ({ row }) => <span className="text-muted-foreground">{row.original.mime_type}</span>,
    },
    {
      accessorKey: 'size_bytes',
      header: 'Velikost',
      cell: ({ row }) => fmtBytes(row.original.size_bytes),
    },
    {
      id: 'attachments',
      header: 'Vazby',
      enableSorting: false,
      cell: ({ row }) => {
        const atts = row.original.attachments;
        if (!atts || atts.length === 0) return <span className="text-muted-foreground">—</span>;
        return (
          <div className="flex flex-wrap gap-1">
            {atts.map((a) => {
              const Icon = ENTITY_ICONS[a.entity_type] ?? FileText;
              return (
                <Badge key={`${a.entity_type}-${a.entity_id}`} variant="secondary" className="gap-1">
                  <Icon className="h-3 w-3" />
                  {a.entity_name ?? `${ENTITY_LABELS[a.entity_type] ?? a.entity_type} #${a.entity_id}`}
                </Badge>
              );
            })}
          </div>
        );
      },
    },
    {
      accessorKey: 'created_at',
      header: 'Nahráno',
      cell: ({ row }) => fmtDateTime(row.original.created_at),
    },
    {
      id: 'actions',
      header: 'Akce',
      enableSorting: false,
      cell: ({ row }) => (
        <ActionButtons
          actions={[
            { icon: 'view', label: 'Zobrazit', onClick: () => setViewFileId(row.original.id) },
            { icon: 'download', label: 'Stáhnout', onClick: () => handleDownload(row.original) },
            { icon: 'edit', label: 'Upravit vazby', onClick: () => setEditFile(row.original) },
            { icon: 'delete', label: 'Smazat', onClick: () => setDeleteFile(row.original), destructive: true },
          ]}
        />
      ),
    },
  ];

  return (
    <div className="space-y-6 p-6">
      {/* Header */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <h1 className="text-2xl font-semibold">Soubory</h1>
        <Button onClick={() => setUploadOpen(true)}>
          <Plus className="h-4 w-4" />
          Nahrát soubor
        </Button>
      </div>

      {/* Filtry */}
      <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:flex-wrap">
        <div className="relative max-w-sm flex-1">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            placeholder="Hledat soubory..."
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
            className="pl-9"
          />
        </div>
        <Select
          value={entityFilter}
          onChange={(e) => {
            setEntityFilter(e.target.value);
            setPage(1);
          }}
          className="lg:w-48"
        >
          {ENTITY_FILTERS.map((opt) => (
            <option key={opt.value} value={opt.value}>
              {opt.label}
            </option>
          ))}
        </Select>
        <label className="flex items-center gap-2 text-sm text-muted-foreground">
          <input
            type="checkbox"
            checked={imagesOnly}
            onChange={(e) => {
              setImagesOnly(e.target.checked);
              setPage(1);
            }}
            className="h-4 w-4 rounded border-input"
          />
          Pouze obrázky
        </label>
      </div>

      {/* Loading stav */}
      {isLoading && (
        <Card>
          <CardContent className="py-10 text-center text-muted-foreground">
            Načítání souborů...
          </CardContent>
        </Card>
      )}

      {/* Error stav */}
      {isError && (
        <Card>
          <CardContent className="py-10 text-center text-destructive">
            Chyba při načítání souborů:{' '}
            {error instanceof Error ? error.message : 'Neznámá chyba'}
          </CardContent>
        </Card>
      )}

      {/* Tabulka */}
      {data && (
        <>
          <DataTable
            columns={columns}
            data={data.data}
            sorting={sorting}
            onSortingChange={setSorting}
          />

          {/* Empty stav */}
          {data.data.length === 0 && (
            <Card>
              <CardContent className="py-10 text-center text-muted-foreground">
                {search || entityFilter || imagesOnly
                  ? 'Žádné soubory neodpovídají filtrům.'
                  : 'Zatím nebyly nahrány žádné soubory.'}
              </CardContent>
            </Card>
          )}

          {/* Paginace */}
          {data.data.length > 0 && (
            <div className="flex items-center justify-between text-sm text-muted-foreground">
              <span>
                Celkem {data.total} {data.total === 1 ? 'záznam' : (data.total < 5 ? 'záznamy' : 'záznamů')}
              </span>
              <div className="flex items-center gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  disabled={page <= 1}
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                >
                  <ChevronLeft className="h-4 w-4" />
                  Předchozí
                </Button>
                <span className="px-2">
                  {page} / {totalPages}
                </span>
                <Button
                  variant="outline"
                  size="sm"
                  disabled={page >= totalPages}
                  onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                >
                  Další
                  <ChevronRight className="h-4 w-4" />
                </Button>
              </div>
            </div>
          )}
        </>
      )}

      {/* Dialogy */}
      <FileUploadDialog open={uploadOpen} onClose={() => setUploadOpen(false)} />
      <FileFormDialog open={!!editFile} onClose={() => setEditFile(null)} file={editFile} />
      <FileDetailModal
        open={!!viewFileId}
        onClose={() => setViewFileId(null)}
        fileId={viewFileId}
        onDelete={(f) => setDeleteFile(f)}
      />
      <ConfirmDeleteDialog
        open={!!deleteFile}
        onClose={() => setDeleteFile(null)}
        onConfirm={handleDelete}
        title="Smazat soubor"
        description={`Opravdu chcete smazat soubor „${deleteFile?.original_name ?? ''}"? Soubor bude trvale odstraněn z databáze i z úložiště. Tuto akci NELZE vrátit zpět.`}
        entityName={deleteFile?.original_name ?? ''}
      />
    </div>
  );
}


