import { useState, useCallback } from 'react';
import { useDebounce } from '@/hooks/useDebounce';
import { ColumnDef, SortingState } from '@tanstack/react-table';
import { Plus, Search, ChevronLeft, ChevronRight } from 'lucide-react';
import { useTasks, useDeleteTask, useUpdateTask, type Task } from '@/hooks/useTasks';
import { useProjects } from '@/hooks/useProjects';
import { DataTable } from '@/components/shared/DataTable';
import { ActionButtons } from '@/components/shared/ActionButtons';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Select } from '@/components/ui/select';
import { Card, CardContent } from '@/components/ui/card';
import { TaskFormDialog } from '@/components/tasks/TaskFormDialog';
import { TaskDetailModal } from '@/components/tasks/TaskDetailModal';
import { ConfirmDeleteDialog } from '@/components/shared/ConfirmDeleteDialog';
import { fmtDate, fmtMinutes } from '@/lib/utils';
import { DEFAULT_PER_PAGE } from '@/lib/constants';

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

const STATUS_FILTERS: { value: string; label: string }[] = [
  { value: '', label: 'Všechny statusy' },
  { value: 'todo', label: 'K vyřešení' },
  { value: 'in_progress', label: 'V řešení' },
  { value: 'done', label: 'Hotové' },
  { value: 'cancelled', label: 'Zrušeno' },
];

const PRIORITY_FILTERS: { value: string; label: string }[] = [
  { value: '', label: 'Všechny priority' },
  { value: 'low', label: 'Nízká' },
  { value: 'medium', label: 'Střední' },
  { value: 'high', label: 'Vysoká' },
  { value: 'urgent', label: 'Urgentní' },
];

const STATUS_OPTIONS: { value: Task['status']; label: string }[] = [
  { value: 'todo', label: 'K vyřešení' },
  { value: 'in_progress', label: 'V řešení' },
  { value: 'done', label: 'Hotové' },
  { value: 'cancelled', label: 'Zrušeno' },
];

function TaskStatusSelect({ task }: { task: Task }) {
  const updateTask = useUpdateTask(task.id);
  const badge = taskStatusBadge(task.status);
  return (
    <div className="relative inline-flex">
      <Badge variant={badge.variant} className={updateTask.isPending ? 'opacity-50' : ''}>
        {updateTask.isPending ? 'Ukládání...' : badge.label}
      </Badge>
      <select
        value={task.status}
        onChange={(e) => {
          updateTask.mutate({ status: e.target.value as Task['status'] });
        }}
        disabled={updateTask.isPending}
        className="absolute inset-0 cursor-pointer opacity-0"
        aria-label="Změnit status úkolu"
      >
        {STATUS_OPTIONS.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
    </div>
  );
}

export default function TasksPage() {
  const [search, setSearch] = useState('');
  const debouncedSearch = useDebounce(search, 300);
  const [projectFilter, setProjectFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [priorityFilter, setPriorityFilter] = useState('');
  const [overdueFilter, setOverdueFilter] = useState(false);
  const [page, setPage] = useState(1);
  const [sorting, setSorting] = useState<SortingState>([{ id: 'title', desc: false }]);
  const [formOpen, setFormOpen] = useState(false);
  const [editTask, setEditTask] = useState<Task | null>(null);
  const [deleteTask, setDeleteTask] = useState<Task | null>(null);
  const [viewTask, setViewTask] = useState<Task | null>(null);

  const { data, isLoading, isError, error } = useTasks({
    search: debouncedSearch || undefined,
    page,
    per_page: DEFAULT_PER_PAGE,
    project_id: projectFilter ? Number(projectFilter) : undefined,
    status: statusFilter || undefined,
    priority: priorityFilter || undefined,
    overdue: overdueFilter || undefined,
  });

  // Načteme projekty pro filtr
  const { data: projectsData } = useProjects({ per_page: 200 });

  const deleteMutation = useDeleteTask();

  const handleDelete = useCallback(async () => {
    if (!deleteTask) return;
    try {
      await deleteMutation.mutateAsync(deleteTask.id);
      setDeleteTask(null);
    } catch {
      // chyba se zobrazí v stavu stránky
    }
  }, [deleteTask, deleteMutation]);

  const openEdit = (task: Task) => {
    setEditTask(task);
    setFormOpen(true);
  };

  const openCreate = () => {
    setEditTask(null);
    setFormOpen(true);
  };

  const closeForm = () => {
    setFormOpen(false);
    setEditTask(null);
  };

  const totalPages = data ? Math.max(1, Math.ceil(data.total / data.per_page)) : 1;

  const columns: ColumnDef<Task>[] = [
    {
      accessorKey: 'title',
      header: 'Název',
      cell: ({ row }) => <span className="font-medium">{row.original.title}</span>,
    },
    {
      accessorKey: 'project_name',
      header: 'Projekt',
      enableSorting: false,
      cell: ({ row }) => row.original.project_name ?? '—',
    },
    {
      accessorKey: 'status',
      header: 'Status',
      cell: ({ row }) => {
        return <TaskStatusSelect task={row.original} />;
      },
    },
    {
      accessorKey: 'priority',
      header: 'Priorita',
      cell: ({ row }) => {
        const badge = priorityBadge(row.original.priority);
        return <Badge variant={badge.variant}>{badge.label}</Badge>;
      },
    },
    {
      accessorKey: 'due_date',
      header: 'Termín',
      cell: ({ row }) => (row.original.due_date ? fmtDate(row.original.due_date) : '—'),
    },
    {
      accessorKey: 'estimated_minutes',
      header: 'Odhad',
      cell: ({ row }) => fmtMinutes(row.original.estimated_minutes),
    },
    {
      accessorKey: 'spent_minutes',
      header: 'Stráveno',
      cell: ({ row }) => fmtMinutes(row.original.spent_minutes),
    },
    {
      id: 'actions',
      header: 'Akce',
      enableSorting: false,
      cell: ({ row }) => (
        <ActionButtons
          actions={[
            { icon: 'view', label: 'Zobrazit', onClick: () => setViewTask(row.original) },
            { icon: 'edit', label: 'Upravit', onClick: () => openEdit(row.original) },
            { icon: 'delete', label: 'Smazat', onClick: () => setDeleteTask(row.original), destructive: true },
          ]}
        />
      ),
    },
  ];

  return (
    <div className="space-y-6 p-6">
      {/* Header */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <h1 className="text-2xl font-semibold">Úkoly</h1>
        <Button onClick={openCreate}>
          <Plus className="h-4 w-4" />
          Nový úkol
        </Button>
      </div>

      {/* Filtry */}
      <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:flex-wrap">
        <div className="relative max-w-sm flex-1">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            placeholder="Hledat podle názvu..."
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
            className="pl-9"
          />
        </div>
        <Select
          value={projectFilter}
          onChange={(e) => {
            setProjectFilter(e.target.value);
            setPage(1);
          }}
          className="lg:w-48"
        >
          <option value="">Všechny projekty</option>
          {projectsData?.data.map((p) => (
            <option key={p.id} value={p.id}>
              {p.name}
            </option>
          ))}
        </Select>
        <Select
          value={statusFilter}
          onChange={(e) => {
            setStatusFilter(e.target.value);
            setPage(1);
          }}
          className="lg:w-40"
        >
          {STATUS_FILTERS.map((opt) => (
            <option key={opt.value} value={opt.value}>
              {opt.label}
            </option>
          ))}
        </Select>
        <Select
          value={priorityFilter}
          onChange={(e) => {
            setPriorityFilter(e.target.value);
            setPage(1);
          }}
          className="lg:w-40"
        >
          {PRIORITY_FILTERS.map((opt) => (
            <option key={opt.value} value={opt.value}>
              {opt.label}
            </option>
          ))}
        </Select>
        <label className="flex items-center gap-2 text-sm text-muted-foreground">
          <input
            type="checkbox"
            checked={overdueFilter}
            onChange={(e) => {
              setOverdueFilter(e.target.checked);
              setPage(1);
            }}
            className="h-4 w-4 rounded border-input"
          />
          Po termínu
        </label>
      </div>

      {/* Loading stav */}
      {isLoading && (
        <Card>
          <CardContent className="py-10 text-center text-muted-foreground">
            Načítání úkolů...
          </CardContent>
        </Card>
      )}

      {/* Error stav */}
      {isError && (
        <Card>
          <CardContent className="py-10 text-center text-destructive">
            Chyba při načítání úkolů:{' '}
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
                {search || projectFilter || statusFilter || priorityFilter || overdueFilter
                  ? 'Žádné úkoly neodpovídají filtrům.'
                  : 'Zatím nebyly vytvořeny žádné úkoly.'}
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
      <TaskFormDialog open={formOpen} onClose={closeForm} task={editTask} />
      <ConfirmDeleteDialog
        open={!!deleteTask}
        onClose={() => setDeleteTask(null)}
        onConfirm={handleDelete}
        title="Smazat úkol"
        description={`Opravdu chcete smazat úkol „${deleteTask?.title ?? ''}"? Tuto akci NELZE vrátit zpět.`}
        entityName={deleteTask?.title ?? ''}
      />
      <TaskDetailModal
        open={!!viewTask}
        onClose={() => setViewTask(null)}
        taskId={viewTask?.id ?? null}
      />
    </div>
  );
}
