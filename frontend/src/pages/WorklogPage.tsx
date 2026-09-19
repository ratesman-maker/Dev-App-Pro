import { useState, useCallback } from 'react';
import { useDebounce } from '@/hooks/useDebounce';
import { ColumnDef, SortingState } from '@tanstack/react-table';
import { Plus, Search, ChevronLeft, ChevronRight, FolderKanban, Users, Clock, CheckCircle2, Circle, Shield, Wrench, Coffee, FileText, Paperclip } from 'lucide-react';
import { useWorklog, useDeleteWorklog, type WorklogEntry, type WorklogCategory, type WorklogSeverity } from '@/hooks/useWorklog';
import { DataTable } from '@/components/shared/DataTable';
import { ActionButtons } from '@/components/shared/ActionButtons';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Select } from '@/components/ui/select';
import { Card, CardContent } from '@/components/ui/card';
import { WorklogFormDialog } from '@/components/worklog/WorklogFormDialog';
import { WorklogDetailModal } from '@/components/worklog/WorklogDetailModal';
import { ConfirmDeleteDialog } from '@/components/shared/ConfirmDeleteDialog';
import { fmtDateTime } from '@/lib/utils';
import { DEFAULT_PER_PAGE } from '@/lib/constants';

const CATEGORY_ICONS: Record<WorklogCategory, typeof FolderKanban> = {
  project: FolderKanban,
  security: Shield,
  maintenance: Wrench,
  meeting: Coffee,
  other: FileText,
};

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

const CATEGORY_FILTERS: { value: string; label: string }[] = [
  { value: '', label: 'Všechny kategorie' },
  { value: 'project', label: 'Projekty' },
  { value: 'security', label: 'Bezpečnost' },
  { value: 'maintenance', label: 'Údržba' },
  { value: 'meeting', label: 'Schůzky' },
  { value: 'other', label: 'Jiné' },
];

const SEVERITY_FILTERS: { value: string; label: string }[] = [
  { value: '', label: 'Všechny závažnosti' },
  { value: 'info', label: 'Info' },
  { value: 'warning', label: 'Varování' },
  { value: 'critical', label: 'Kritické' },
];

function truncate(text: string, max: number): string {
  if (text.length <= max) return text;
  return text.slice(0, max) + '…';
}

export default function WorklogPage() {
  const [search, setSearch] = useState('');
  const debouncedSearch = useDebounce(search, 300);
  const [categoryFilter, setCategoryFilter] = useState('');
  const [severityFilter, setSeverityFilter] = useState('');
  const [page, setPage] = useState(1);
  const [sorting, setSorting] = useState<SortingState>([{ id: 'created_at', desc: true }]);
  const [formOpen, setFormOpen] = useState(false);
  const [editEntry, setEditEntry] = useState<WorklogEntry | null>(null);
  const [deleteEntry, setDeleteEntry] = useState<WorklogEntry | null>(null);
  const [viewEntryId, setViewEntryId] = useState<number | null>(null);

  const { data, isLoading, isError, error } = useWorklog({
    search: debouncedSearch || undefined,
    page,
    per_page: DEFAULT_PER_PAGE,
    category: categoryFilter || undefined,
    severity: severityFilter || undefined,
  });

  const deleteMutation = useDeleteWorklog();

  const handleDelete = useCallback(async () => {
    if (!deleteEntry) return;
    try {
      await deleteMutation.mutateAsync(deleteEntry.id);
      setDeleteEntry(null);
    } catch {
      // chyba se zobrazí v stavu stránky
    }
  }, [deleteEntry, deleteMutation]);

  const openEdit = (entry: WorklogEntry) => {
    setEditEntry(entry);
    setFormOpen(true);
  };

  const openCreate = () => {
    setEditEntry(null);
    setFormOpen(true);
  };

  const closeForm = () => {
    setFormOpen(false);
    setEditEntry(null);
  };

  const totalPages = data ? Math.max(1, Math.ceil(data.total / data.per_page)) : 1;

  const columns: ColumnDef<WorklogEntry>[] = [
    {
      accessorKey: 'title',
      header: 'Titulek',
      cell: ({ row }) => {
        const e = row.original;
        const CatIcon = CATEGORY_ICONS[e.category] ?? FileText;
        return (
          <div className="flex items-start gap-2">
            <CatIcon className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
            <div className="min-w-0">
              <span className="font-medium">{e.title}</span>
              {e.description && (
                <div className="text-xs text-muted-foreground truncate max-w-md">
                  {truncate(e.description, 80)}
                </div>
              )}
            </div>
          </div>
        );
      },
    },
    {
      accessorKey: 'category',
      header: 'Kategorie',
      cell: ({ row }) => (
        <Badge variant="outline">{CATEGORY_LABELS[row.original.category] ?? row.original.category}</Badge>
      ),
    },
    {
      accessorKey: 'severity',
      header: 'Závažnost',
      cell: ({ row }) => (
        <Badge variant={SEVERITY_VARIANTS[row.original.severity] ?? 'secondary'}>
          {SEVERITY_LABELS[row.original.severity] ?? row.original.severity}
        </Badge>
      ),
    },
    {
      id: 'bindings',
      header: 'Vazby',
      enableSorting: false,
      cell: ({ row }) => {
        const e = row.original;
        const hasBindings = e.project_name || e.client_name;
        const attCount = e.attachments?.length ?? 0;
        if (!hasBindings && attCount === 0) return <span className="text-muted-foreground">—</span>;
        return (
          <div className="flex flex-wrap gap-1">
            {e.project_name && (
              <Badge variant="secondary" className="gap-1">
                <FolderKanban className="h-3 w-3" />
                {e.project_name}
              </Badge>
            )}
            {e.client_name && (
              <Badge variant="secondary" className="gap-1">
                <Users className="h-3 w-3" />
                {e.client_name}
              </Badge>
            )}
            {attCount > 0 && (
              <Badge variant="outline" className="gap-1">
                <Paperclip className="h-3 w-3" />
                {attCount}
              </Badge>
            )}
          </div>
        );
      },
    },
    {
      id: 'status',
      header: 'Stav',
      enableSorting: false,
      cell: ({ row }) => {
        const e = row.original;
        return (
          <div className="flex items-center gap-2">
            {e.is_done ? (
              <span className="flex items-center gap-1 text-sm text-green-600">
                <CheckCircle2 className="h-4 w-4" /> Hotovo
              </span>
            ) : (
              <span className="flex items-center gap-1 text-sm text-muted-foreground">
                <Circle className="h-4 w-4" /> Otevřené
              </span>
            )}
            {e.hours != null && (
              <span className="flex items-center gap-1 text-xs text-muted-foreground">
                <Clock className="h-3 w-3" /> {e.hours} h
              </span>
            )}
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
            { icon: 'view', label: 'Zobrazit', onClick: () => setViewEntryId(row.original.id) },
            { icon: 'edit', label: 'Upravit', onClick: () => openEdit(row.original) },
            { icon: 'delete', label: 'Smazat', onClick: () => setDeleteEntry(row.original), destructive: true },
          ]}
        />
      ),
    },
  ];

  return (
    <div className="space-y-6 p-6">
      {/* Header */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <h1 className="text-2xl font-semibold">Pracovní deník</h1>
        <Button onClick={openCreate}>
          <Plus className="h-4 w-4" />
          Nový záznam
        </Button>
      </div>

      {/* Filtry */}
      <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:flex-wrap">
        <div className="relative max-w-sm flex-1">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            placeholder="Hledat v deníku..."
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
            className="pl-9"
          />
        </div>
        <Select
          value={categoryFilter}
          onChange={(e) => {
            setCategoryFilter(e.target.value);
            setPage(1);
          }}
          className="lg:w-48"
        >
          {CATEGORY_FILTERS.map((opt) => (
            <option key={opt.value} value={opt.value}>
              {opt.label}
            </option>
          ))}
        </Select>
        <Select
          value={severityFilter}
          onChange={(e) => {
            setSeverityFilter(e.target.value);
            setPage(1);
          }}
          className="lg:w-48"
        >
          {SEVERITY_FILTERS.map((opt) => (
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
            Načítání záznamů...
          </CardContent>
        </Card>
      )}

      {/* Error stav */}
      {isError && (
        <Card>
          <CardContent className="py-10 text-center text-destructive">
            Chyba při načítání záznamů:{' '}
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
                {search || categoryFilter || severityFilter
                  ? 'Žádné záznamy neodpovídají filtrům.'
                  : 'Zatím nebyly vytvořeny žádné záznamy.'}
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
      <WorklogFormDialog open={formOpen} onClose={closeForm} entry={editEntry} />

      <WorklogDetailModal
        open={!!viewEntryId}
        onClose={() => setViewEntryId(null)}
        entryId={viewEntryId}
      />

      <ConfirmDeleteDialog
        open={!!deleteEntry}
        onClose={() => setDeleteEntry(null)}
        onConfirm={handleDelete}
        title="Smazat záznam"
        description={`Opravdu chcete smazat záznam „${deleteEntry?.title ?? ''}"? Tuto akci NELZE vrátit zpět.`}
        entityName={deleteEntry?.title ?? ''}
      />
    </div>
  );
}
