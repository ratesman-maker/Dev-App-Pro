import { useState, useCallback } from 'react';
import { useDebounce } from '@/hooks/useDebounce';
import { ColumnDef, SortingState } from '@tanstack/react-table';
import { Plus, Search, ChevronLeft, ChevronRight, Users, FolderKanban, ListTodo, FileText } from 'lucide-react';
import { useNotes, useDeleteNote, type Note } from '@/hooks/useNotes';
import { DataTable } from '@/components/shared/DataTable';
import { ActionButtons } from '@/components/shared/ActionButtons';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Select } from '@/components/ui/select';
import { Card, CardContent } from '@/components/ui/card';
import { NoteFormDialog } from '@/components/notes/NoteFormDialog';
import { NoteDetailModal } from '@/components/notes/NoteDetailModal';
import { ConfirmDeleteDialog } from '@/components/shared/ConfirmDeleteDialog';
import { fmtDateTime } from '@/lib/utils';
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

function truncate(text: string, max: number): string {
  if (text.length <= max) return text;
  return text.slice(0, max) + '…';
}

export default function NotesPage() {
  const [search, setSearch] = useState('');
  const debouncedSearch = useDebounce(search, 300);
  const [entityFilter, setEntityFilter] = useState('');
  const [page, setPage] = useState(1);
  const [sorting, setSorting] = useState<SortingState>([{ id: 'created_at', desc: true }]);
  const [formOpen, setFormOpen] = useState(false);
  const [editNote, setEditNote] = useState<Note | null>(null);
  const [deleteNote, setDeleteNote] = useState<Note | null>(null);
  const [viewNoteId, setViewNoteId] = useState<number | null>(null);

  const { data, isLoading, isError, error } = useNotes({
    search: debouncedSearch || undefined,
    page,
    per_page: DEFAULT_PER_PAGE,
    entity_type: entityFilter || undefined,
  });

  const deleteMutation = useDeleteNote();

  const handleDelete = useCallback(async () => {
    if (!deleteNote) return;
    try {
      await deleteMutation.mutateAsync(deleteNote.id);
      setDeleteNote(null);
    } catch {
      // chyba se zobrazí v stavu stránky
    }
  }, [deleteNote, deleteMutation]);

  const openEdit = (note: Note) => {
    setEditNote(note);
    setFormOpen(true);
  };

  const openCreate = () => {
    setEditNote(null);
    setFormOpen(true);
  };

  const closeForm = () => {
    setFormOpen(false);
    setEditNote(null);
  };

  const totalPages = data ? Math.max(1, Math.ceil(data.total / data.per_page)) : 1;

  const columns: ColumnDef<Note>[] = [
    {
      accessorKey: 'title',
      header: 'Nadpis',
      cell: ({ row }) => (
        <span className="font-medium">{row.original.title || <span className="text-muted-foreground">Bez nadpisu</span>}</span>
      ),
    },
    {
      accessorKey: 'content',
      header: 'Obsah',
      enableSorting: false,
      cell: ({ row }) => (
        <span className="text-muted-foreground">{truncate(row.original.content, 50)}</span>
      ),
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
      header: 'Vytvořeno',
      cell: ({ row }) => fmtDateTime(row.original.created_at),
    },
    {
      id: 'actions',
      header: 'Akce',
      enableSorting: false,
      cell: ({ row }) => (
        <ActionButtons
          actions={[
            { icon: 'view', label: 'Zobrazit', onClick: () => setViewNoteId(row.original.id) },
            { icon: 'edit', label: 'Upravit', onClick: () => openEdit(row.original) },
            { icon: 'delete', label: 'Smazat', onClick: () => setDeleteNote(row.original), destructive: true },
          ]}
        />
      ),
    },
  ];

  return (
    <div className="space-y-6 p-6">
      {/* Header */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <h1 className="text-2xl font-semibold">Poznámky</h1>
        <Button onClick={openCreate}>
          <Plus className="h-4 w-4" />
          Nová poznámka
        </Button>
      </div>

      {/* Filtry */}
      <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:flex-wrap">
        <div className="relative max-w-sm flex-1">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            placeholder="Hledat v poznámkách..."
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
      </div>

      {/* Loading stav */}
      {isLoading && (
        <Card>
          <CardContent className="py-10 text-center text-muted-foreground">
            Načítání poznámek...
          </CardContent>
        </Card>
      )}

      {/* Error stav */}
      {isError && (
        <Card>
          <CardContent className="py-10 text-center text-destructive">
            Chyba při načítání poznámek:{' '}
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
                {search || entityFilter
                  ? 'Žádné poznámky neodpovídají filtrům.'
                  : 'Zatím nebyly vytvořeny žádné poznámky.'}
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
      <NoteFormDialog open={formOpen} onClose={closeForm} note={editNote} />

      <NoteDetailModal
        open={!!viewNoteId}
        onClose={() => setViewNoteId(null)}
        noteId={viewNoteId}
      />

      <ConfirmDeleteDialog
        open={!!deleteNote}
        onClose={() => setDeleteNote(null)}
        onConfirm={handleDelete}
        title="Smazat poznámku"
        description={`Opravdu chcete smazat poznámku „${deleteNote?.title || deleteNote?.content?.slice(0, 50) || ''}"? Tuto akci NELZE vrátit zpět.`}
        entityName={deleteNote?.title || deleteNote?.content?.slice(0, 30) || ''}
      />
    </div>
  );
}


