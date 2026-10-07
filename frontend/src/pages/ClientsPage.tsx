import { useState, useCallback } from 'react';
import { ColumnDef, SortingState } from '@tanstack/react-table';
import { Plus, Search, ChevronLeft, ChevronRight } from 'lucide-react';
import { useClients, useDeleteClient, type Client } from '@/hooks/useClients';
import { useDebounce } from '@/hooks/useDebounce';
import { DataTable } from '@/components/shared/DataTable';
import { ActionButtons } from '@/components/shared/ActionButtons';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { ClientFormDialog } from '@/components/clients/ClientFormDialog';
import { ClientDetailModal } from '@/components/clients/ClientDetailModal';
import { ConfirmDeleteDialog } from '@/components/shared/ConfirmDeleteDialog';
import { EmailLink, PhoneLink } from '@/components/shared/ContactLinks';
import { DEFAULT_PER_PAGE } from '@/lib/constants';

// Mapování TanStack sort id → API sort parametr
function sortToApi(sorting: SortingState): string | undefined {
  if (sorting.length === 0) return 'last_name';
  const s = sorting[0];
  // Sloupec "full_name" řadíme na backendu podle last_name (osoby) / company_name (firmy)
  if (s.id === 'full_name') return s.desc ? '-last_name' : 'last_name';
  return s.desc ? `-${s.id}` : s.id;
}

export default function ClientsPage() {
  const [search, setSearch] = useState('');
  const debouncedSearch = useDebounce(search, 300);
  const [page, setPage] = useState(1);
  const [sorting, setSorting] = useState<SortingState>([{ id: 'full_name', desc: false }]);
  const [formOpen, setFormOpen] = useState(false);
  const [editClient, setEditClient] = useState<Client | null>(null);
  const [deleteClient, setDeleteClient] = useState<Client | null>(null);
  const [viewClient, setViewClient] = useState<Client | null>(null);

  const { data, isLoading, isError, error } = useClients({
    search: debouncedSearch || undefined,
    page,
    per_page: DEFAULT_PER_PAGE,
    sort: sortToApi(sorting),
  });

  const deleteMutation = useDeleteClient();

  const handleDelete = useCallback(async () => {
    if (!deleteClient) return;
    try {
      await deleteMutation.mutateAsync(deleteClient.id);
      setDeleteClient(null);
    } catch {
      // chyba se zobrazí v stavu stránky při dalším renderu
    }
  }, [deleteClient, deleteMutation]);

  const openEdit = (client: Client) => {
    setEditClient(client);
    setFormOpen(true);
  };

  const openCreate = () => {
    setEditClient(null);
    setFormOpen(true);
  };

  const closeForm = () => {
    setFormOpen(false);
    setEditClient(null);
  };

  const totalPages = data ? Math.max(1, Math.ceil(data.total / data.per_page)) : 1;

  const columns: ColumnDef<Client>[] = [
    {
      accessorKey: 'full_name',
      header: 'Jméno',
      cell: ({ row }) => (
        <span className="font-medium">{row.original.full_name || '—'}</span>
      ),
    },
    {
      accessorKey: 'type',
      header: 'Typ',
      enableSorting: false,
      cell: ({ row }) => {
        const t = row.original.type;
        const label = t === 'individual' ? 'Osoba'
          : t === 'company' ? 'Firma'
          : t === 'nonprofit' ? 'Neziskový'
          : t === 'government' ? 'Státní správa'
          : t;
        const variant = 'secondary';
        return <Badge variant={variant}>{label}</Badge>;
      },
    },
    {
      accessorKey: 'contact_name',
      header: 'Zástupce',
      enableSorting: false,
      cell: ({ row }) => {
        const c = row.original;
        if (!c.contact_name && !c.contact_email && !c.contact_phone) return '—';
        const contact = [c.contact_email, c.contact_phone].filter(Boolean).join(' · ');
        return (
          <div className="flex flex-col">
            <span>{c.contact_name}</span>
            {contact ? <span className="text-xs text-muted-foreground">{contact}</span> : null}
          </div>
        );
      },
    },
    {
      accessorKey: 'ico',
      header: 'IČO',
      enableSorting: false,
      cell: ({ row }) => row.original.ico || '—',
    },
    {
      accessorKey: 'email',
      header: 'E-mail',
      enableSorting: false,
      cell: ({ row }) => <EmailLink email={row.original.email} />,
    },
    {
      accessorKey: 'phone',
      header: 'Telefon',
      enableSorting: false,
      cell: ({ row }) => <PhoneLink phone={row.original.phone} />,
    },
    {
      id: 'actions',
      header: 'Akce',
      enableSorting: false,
      cell: ({ row }) => (
        <ActionButtons
          actions={[
            { icon: 'view', label: 'Zobrazit', onClick: () => setViewClient(row.original) },
            { icon: 'edit', label: 'Upravit', onClick: () => openEdit(row.original) },
            { icon: 'delete', label: 'Smazat', onClick: () => setDeleteClient(row.original), destructive: true },
          ]}
        />
      ),
    },
  ];

  return (
    <div className="space-y-6 p-6">
      {/* Header */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <h1 className="text-2xl font-semibold">Klienti</h1>
        <Button onClick={openCreate}>
          <Plus className="h-4 w-4" />
          Nový klient
        </Button>
      </div>

      {/* Vyhledávání */}
      <div className="relative max-w-sm">
        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          placeholder="Hledat podle jména nebo e-mailu..."
          value={search}
          onChange={(e) => {
            setSearch(e.target.value);
            setPage(1);
          }}
          className="pl-9"
        />
      </div>

      {/* Loading stav */}
      {isLoading && (
        <Card>
          <CardContent className="py-10 text-center text-muted-foreground">
            Načítání klientů...
          </CardContent>
        </Card>
      )}

      {/* Error stav */}
      {isError && (
        <Card>
          <CardContent className="py-10 text-center text-destructive">
            Chyba při načítání klientů:{' '}
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
                {search
                  ? 'Žádní klienti neodpovídají vyhledávání.'
                  : 'Zatím nebyli vytvořeni žádní klienti.'}
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
      <ClientFormDialog open={formOpen} onClose={closeForm} client={editClient} />
      <ConfirmDeleteDialog
        open={!!deleteClient}
        onClose={() => setDeleteClient(null)}
        onConfirm={handleDelete}
        title="Smazat klienta"
        description={`Opravdu chcete smazat klienta „${deleteClient?.full_name ?? ''}"? Všechna jeho data (projekty, faktury, transakce, poznámky, soubory) budou trvale odstraněna. Tuto akci NELZE vrátit zpět.`}
        entityName={deleteClient?.full_name ?? ''}
      />
      <ClientDetailModal
        open={!!viewClient}
        onClose={() => setViewClient(null)}
        clientId={viewClient?.id ?? null}
      />
    </div>
  );
}
