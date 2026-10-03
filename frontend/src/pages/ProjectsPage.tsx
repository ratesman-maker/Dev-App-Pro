import { useState, useCallback } from 'react';
import { ColumnDef, SortingState } from '@tanstack/react-table';
import { Plus, Search, ChevronLeft, ChevronRight } from 'lucide-react';
import { useProjects, useDeleteProject, useArchiveProject, useRestoreProject, useWpLoginUrl, usePhpVersions, useSetPhpVersion, usePhpVersionJobs, useHostingJobs, type Project } from '@/hooks/useProjects';
import { useDebounce } from '@/hooks/useDebounce';
import { DataTable } from '@/components/shared/DataTable';
import { ActionButtons } from '@/components/shared/ActionButtons';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Select } from '@/components/ui/select';
import { Card, CardContent } from '@/components/ui/card';
import { ProjectFormDialog } from '@/components/projects/ProjectFormDialog';
import { ProjectDetailModal } from '@/components/projects/ProjectDetailModal';
import { ConfirmDialog } from '@/components/shared/ConfirmDialog';
import { ConfirmDeleteDialog } from '@/components/shared/ConfirmDeleteDialog';
import { fmtDate, fmtMoney } from '@/lib/utils';
import { DEFAULT_PER_PAGE } from '@/lib/constants';

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

const STATUS_FILTERS: { value: string; label: string }[] = [
  { value: '', label: 'Všechny statusy' },
  { value: 'active', label: 'Aktivní' },
  { value: 'on_hold', label: 'Pozastaveno' },
  { value: 'completed', label: 'Dokončeno' },
  { value: 'cancelled', label: 'Zrušeno' },
  { value: 'archived', label: 'Archivováno' },
];

export default function ProjectsPage() {
  const [search, setSearch] = useState('');
  const debouncedSearch = useDebounce(search, 300);
  const [statusFilter, setStatusFilter] = useState('');
  const [page, setPage] = useState(1);
  const [sorting, setSorting] = useState<SortingState>([{ id: 'name', desc: false }]);
  const [formOpen, setFormOpen] = useState(false);
  const [editProject, setEditProject] = useState<Project | null>(null);
  const [deleteProject, setDeleteProject] = useState<Project | null>(null);
  const [archiveProject, setArchiveProject] = useState<Project | null>(null);
  const [restoreProject, setRestoreProject] = useState<Project | null>(null);
  const [viewProject, setViewProject] = useState<Project | null>(null);

  const { data, isLoading, isError, error } = useProjects({
    search: debouncedSearch || undefined,
    page,
    per_page: DEFAULT_PER_PAGE,
    status: statusFilter || undefined,
  });

  const deleteMutation = useDeleteProject();
  const archiveMutation = useArchiveProject();
  const restoreMutation = useRestoreProject();
  const wpLoginMutation = useWpLoginUrl();
  const { data: phpVersionsData } = usePhpVersions();
  const { data: phpJobsData } = usePhpVersionJobs();
  const { data: hostingJobsData } = useHostingJobs();
  const setPhpVersionMutation = useSetPhpVersion();

  const installedPhpVersions = (phpVersionsData?.versions ?? []).filter(v => v.installed);
  const currentPhpVersion = (project: Project) => project.php_version ?? '8.5';
  const activePhpJobs = phpJobsData?.active ?? [];
  const activeHostingJobs = hostingJobsData?.data?.filter(
    (j) => j.status === 'pending' || j.status === 'regenerating_ssl' || j.status === 'generating_vhosts' || j.status === 'reloading'
  ) ?? [];

  const handleSetPhpVersion = useCallback(async (project: Project, version: string) => {
    if (version === currentPhpVersion(project)) return;
    try {
      await setPhpVersionMutation.mutateAsync({ id: project.id, php_version: version });
    } catch {
      // chyba se zobrazí v UI
    }
  }, [setPhpVersionMutation]);

  const handleWpLogin = useCallback(async (project: Project) => {
    try {
      const result = await wpLoginMutation.mutateAsync(project.id);
      window.open(result.url, '_blank');
    } catch {
      // chyba se zobrazí v UI
    }
  }, [wpLoginMutation]);

  const handleDelete = useCallback(async () => {
    if (!deleteProject) return;
    try {
      await deleteMutation.mutateAsync(deleteProject.id);
      setDeleteProject(null);
    } catch {
      // chyba se zobrazí v stavu stránky
    }
  }, [deleteProject, deleteMutation]);

  const handleArchive = useCallback(async () => {
    if (!archiveProject) return;
    try {
      await archiveMutation.mutateAsync(archiveProject.id);
      setArchiveProject(null);
    } catch {
      // chyba se zobrazí v stavu stránky
    }
  }, [archiveProject, archiveMutation]);

  const handleRestore = useCallback(async () => {
    if (!restoreProject) return;
    try {
      await restoreMutation.mutateAsync(restoreProject.id);
      setRestoreProject(null);
    } catch {
      // chyba se zobrazí v stavu stránky
    }
  }, [restoreProject, restoreMutation]);

  const openEdit = (project: Project) => {
    setEditProject(project);
    setFormOpen(true);
  };

  const openCreate = () => {
    setEditProject(null);
    setFormOpen(true);
  };

  const closeForm = () => {
    setFormOpen(false);
    setEditProject(null);
  };

  const totalPages = data ? Math.max(1, Math.ceil(data.total / data.per_page)) : 1;

  const columns: ColumnDef<Project>[] = [
    {
      accessorKey: 'name',
      header: 'Název',
      cell: ({ row }) => (
        <span className="font-medium">{row.original.name}</span>
      ),
    },
    {
      accessorKey: 'client_name',
      header: 'Klient',
      enableSorting: false,
      cell: ({ row }) => row.original.client_name ?? '—',
    },
    {
      accessorKey: 'status',
      header: 'Status',
      cell: ({ row }) => {
        const badge = projectStatusBadge(row.original.status);
        return <Badge variant={badge.variant}>{badge.label}</Badge>;
      },
    },
    {
      accessorKey: 'type',
      header: 'Typ',
      enableSorting: false,
      cell: ({ row }) => {
        const type = row.original.type;
        if (type === 'wordpress') {
          return <Badge variant="default">WordPress</Badge>;
        }
        if (type === 'static') {
          return <Badge variant="outline">Static</Badge>;
        }
        if (type === 'php') {
          return <Badge variant="secondary">PHP</Badge>;
        }
        return <span className="text-muted-foreground">—</span>;
      },
    },
    {
      id: 'php_version',
      header: 'PHP',
      enableSorting: false,
      cell: ({ row }) => {
        const p = row.original;
        if (!p.folder_path || p.type === 'static') {
          return <span className="text-muted-foreground">—</span>;
        }
        const current = currentPhpVersion(p);
        const activeJob = activePhpJobs.find((j) => j.project_id === p.id);
        const isPending = setPhpVersionMutation.isPending && setPhpVersionMutation.variables?.id === p.id;

        if (activeJob) {
          const labels: Record<string, string> = {
            pending: 'Čeká…',
            starting_fpm: 'Start FPM…',
            regenerating: 'Generuji…',
            reloading: 'Reload…',
          };
          return <Badge variant="secondary">{labels[activeJob.status] ?? activeJob.status}</Badge>;
        }

        return (
          <Select
            value={current}
            disabled={isPending || installedPhpVersions.length === 0}
            onChange={(e) => handleSetPhpVersion(p, e.target.value)}
            className="w-20"
          >
            {installedPhpVersions.map((v) => (
              <option key={v.version} value={v.version}>
                {v.version}
              </option>
            ))}
          </Select>
        );
      },
    },
    {
      accessorKey: 'budget_cents',
      header: 'Rozpočet',
      cell: ({ row }) => (row.original.budget_cents ? fmtMoney(row.original.budget_cents) : '—'),
    },
    {
      accessorKey: 'deadline',
      header: 'Termín',
      cell: ({ row }) => (row.original.deadline ? fmtDate(row.original.deadline) : '—'),
    },
    {
      id: 'actions',
      header: 'Akce',
      enableSorting: false,
      cell: ({ row }) => {
        const p = row.original;
        const isArchived = p.status === 'archived';
        const hasActiveHosting = activeHostingJobs.some((j) => j.project_id === p.id);
        return (
          <ActionButtons
            actions={[
              { icon: 'view', label: 'Zobrazit', onClick: () => setViewProject(p) },
              ...(p.folder_path
                ? [{ icon: 'preview' as const, label: hasActiveHosting ? 'Generuji SSL…' : 'Náhled', onClick: () => !hasActiveHosting && window.open(`https://${p.folder_path}.localhost/`, '_blank'), disabled: hasActiveHosting }]
                : []),
              ...(p.type === 'wordpress'
                ? [{ icon: 'login' as const, label: 'Přihlásit', onClick: () => handleWpLogin(p) }]
                : []),
              { icon: 'edit', label: 'Upravit', onClick: () => openEdit(p) },
              ...(isArchived
                ? [{ icon: 'restore' as const, label: 'Obnovit', onClick: () => setRestoreProject(p) }]
                : [{ icon: 'archive' as const, label: 'Archivovat', onClick: () => setArchiveProject(p) }]),
              { icon: 'delete', label: 'Smazat', onClick: () => setDeleteProject(p), destructive: true },
            ]}
          />
        );
      },
    },
  ];

  return (
    <div className="space-y-6 p-6">
      {/* Header */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-center gap-3">
          <h1 className="text-2xl font-semibold">Projekty</h1>
          {activeHostingJobs.length > 0 && (
            <Badge variant="secondary">
              Generuji SSL ({activeHostingJobs.length})
            </Badge>
          )}
        </div>
        <Button onClick={openCreate}>
          <Plus className="h-4 w-4" />
          Nový projekt
        </Button>
      </div>

      {/* Filtry */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
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
          value={statusFilter}
          onChange={(e) => {
            setStatusFilter(e.target.value);
            setPage(1);
          }}
          className="sm:w-48"
        >
          {STATUS_FILTERS.map((opt) => (
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
            Načítání projektů...
          </CardContent>
        </Card>
      )}

      {/* Error stav */}
      {isError && (
        <Card>
          <CardContent className="py-10 text-center text-destructive">
            Chyba při načítání projektů:{' '}
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
                {search || statusFilter
                  ? 'Žádné projekty neodpovídají filtrům.'
                  : 'Zatím nebyly vytvořeny žádné projekty.'}
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
      <ProjectFormDialog open={formOpen} onClose={closeForm} project={editProject} />
      <ConfirmDeleteDialog
        open={!!deleteProject}
        onClose={() => setDeleteProject(null)}
        onConfirm={handleDelete}
        title="Smazat projekt"
        description={`Opravdu chcete smazat projekt „${deleteProject?.name ?? ''}"? Budou trvale smazány: soubory na disku, databáze, Apache vhost a SSL certifikát. Tuto akci NELZE vrátit zpět.`}
        entityName={deleteProject?.name ?? ''}
      />
      <ConfirmDialog
        open={!!archiveProject}
        onClose={() => setArchiveProject(null)}
        onConfirm={handleArchive}
        title="Archivovat projekt"
        description={`Opravdu chcete archivovat projekt „${archiveProject?.name ?? ''}"? Archivovaný projekt bude skryt, ale lze jej obnovit.`}
        confirmLabel="Archivovat"
      />
      <ConfirmDialog
        open={!!restoreProject}
        onClose={() => setRestoreProject(null)}
        onConfirm={handleRestore}
        title="Obnovit projekt"
        description={`Opravdu chcete obnovit projekt „${restoreProject?.name ?? ''}" z archivu?`}
        confirmLabel="Obnovit"
      />
      <ProjectDetailModal
        open={!!viewProject}
        onClose={() => setViewProject(null)}
        projectId={viewProject?.id ?? null}
      />
    </div>
  );
}
