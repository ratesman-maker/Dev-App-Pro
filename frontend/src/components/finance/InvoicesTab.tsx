import { useState } from 'react';
import { useDebounce } from '@/hooks/useDebounce';
import { ColumnDef, SortingState } from '@tanstack/react-table';
import {
  Plus,
  Search,
  ChevronLeft,
  ChevronRight,
} from 'lucide-react';
import { fmtDate, fmtMoney } from '@/lib/utils';
import { DataTable } from '@/components/shared/DataTable';
import { ActionButtons } from '@/components/shared/ActionButtons';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Select } from '@/components/ui/select';
import { Card, CardContent } from '@/components/ui/card';
import { ConfirmDeleteDialog } from '@/components/shared/ConfirmDeleteDialog';
import {
  useInvoices,
  useDeleteInvoice,
  useDownloadInvoicePdf,
  type Invoice,
} from '@/hooks/useInvoices';
import { InvoiceFormDialog } from '@/components/finance/InvoiceFormDialog';
import { InvoiceDetailModal } from '@/components/invoices/InvoiceDetailModal';
import {
  PER_PAGE,
  INVOICE_STATUS_FILTERS,
  invoiceStatusBadge,
} from '@/components/finance/financeHelpers';

export default function InvoicesTab() {
  const [search, setSearch] = useState('');
  const debouncedSearch = useDebounce(search, 300);
  const [statusFilter, setStatusFilter] = useState('');
  const [page, setPage] = useState(1);
  const [sorting, setSorting] = useState<SortingState>([{ id: 'invoice_number', desc: false }]);
  const [formOpen, setFormOpen] = useState(false);
  const [editInvoice, setEditInvoice] = useState<Invoice | null>(null);
  const [deleteInvoice, setDeleteInvoice] = useState<Invoice | null>(null);
  const [viewInvoice, setViewInvoice] = useState<Invoice | null>(null);

  const { data, isLoading, isError, error } = useInvoices({
    search: debouncedSearch || undefined,
    page,
    per_page: PER_PAGE,
    status: statusFilter || undefined,
  });

  const deleteMutation = useDeleteInvoice();
  const pdfMutation = useDownloadInvoicePdf();

  const handleDelete = async () => {
    if (!deleteInvoice) return;
    try {
      await deleteMutation.mutateAsync(deleteInvoice.id);
      setDeleteInvoice(null);
    } catch {
      // chyba se zobrazí v toast/stavu
    }
  };

  const handlePdf = async (invoice: Invoice) => {
    try {
      await pdfMutation.mutateAsync(invoice);
    } catch {
      // chyba stažení PDF
    }
  };

  const openEdit = (invoice: Invoice) => {
    setEditInvoice(invoice);
    setFormOpen(true);
  };

  const openCreate = () => {
    setEditInvoice(null);
    setFormOpen(true);
  };

  const closeForm = () => {
    setFormOpen(false);
    setEditInvoice(null);
  };

  const totalPages = data ? Math.max(1, Math.ceil(data.total / data.per_page)) : 1;

  const columns: ColumnDef<Invoice>[] = [
    {
      accessorKey: 'invoice_number',
      header: 'Číslo',
      cell: ({ row }) => <span className="font-medium">{row.original.invoice_number}</span>,
    },
    {
      accessorKey: 'client_name',
      header: 'Klient',
      enableSorting: false,
      cell: ({ row }) => row.original.client_name ?? '—',
    },
    {
      accessorKey: 'issue_date',
      header: 'Vystavena',
      cell: ({ row }) => fmtDate(row.original.issue_date),
    },
    {
      accessorKey: 'due_date',
      header: 'Splatnost',
      cell: ({ row }) => fmtDate(row.original.due_date),
    },
    {
      accessorKey: 'subtotal_cents',
      header: 'Bez DPH',
      cell: ({ row }) => fmtMoney(row.original.subtotal_cents),
    },
    {
      accessorKey: 'vat_amount_cents',
      header: 'DPH',
      cell: ({ row }) => fmtMoney(row.original.vat_amount_cents),
    },
    {
      accessorKey: 'amount_cents',
      header: 'Celkem',
      cell: ({ row }) => <span className="font-medium">{fmtMoney(row.original.amount_cents)}</span>,
    },
    {
      accessorKey: 'paid_cents',
      header: 'Zaplaceno',
      cell: ({ row }) => fmtMoney(row.original.paid_cents),
    },
    {
      accessorKey: 'status',
      header: 'Status',
      cell: ({ row }) => {
        const badge = invoiceStatusBadge(row.original.status);
        return <Badge variant={badge.variant}>{badge.label}</Badge>;
      },
    },
    {
      id: 'actions',
      header: 'Akce',
      enableSorting: false,
      cell: ({ row }) => (
        <ActionButtons
          actions={[
            { icon: 'view', label: 'Zobrazit', onClick: () => setViewInvoice(row.original) },
            { icon: 'edit', label: 'Upravit', onClick: () => openEdit(row.original) },
            { icon: 'pdf', label: 'Stáhnout PDF', onClick: () => handlePdf(row.original) },
            { icon: 'delete', label: 'Smazat', onClick: () => setDeleteInvoice(row.original), destructive: true },
          ]}
        />
      ),
    },
  ];

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <h2 className="text-xl font-semibold">Faktury</h2>
        <Button onClick={openCreate}>
          <Plus className="h-4 w-4" />
          Vystavit fakturu
        </Button>
      </div>

      {/* Filtry */}
      <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:flex-wrap">
        <div className="relative max-w-sm flex-1">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            placeholder="Hledat podle čísla..."
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
          className="lg:w-48"
        >
          {INVOICE_STATUS_FILTERS.map((opt) => (
            <option key={opt.value} value={opt.value}>
              {opt.label}
            </option>
          ))}
        </Select>
      </div>

      {isLoading && (
        <Card>
          <CardContent className="py-10 text-center text-muted-foreground">
            Načítání faktur...
          </CardContent>
        </Card>
      )}

      {isError && (
        <Card>
          <CardContent className="py-10 text-center text-destructive">
            Chyba při načítání faktur:{' '}
            {error instanceof Error ? error.message : 'Neznámá chyba'}
          </CardContent>
        </Card>
      )}

      {data && (
        <>
          <DataTable columns={columns} data={data.data} sorting={sorting} onSortingChange={setSorting} />

          {data.data.length === 0 && (
            <Card>
              <CardContent className="py-10 text-center text-muted-foreground">
                {search || statusFilter ? 'Žádné faktury neodpovídají filtrům.' : 'Zatím nebyly vytvořeny žádné faktury.'}
              </CardContent>
            </Card>
          )}

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
                <span className="px-2">{page} / {totalPages}</span>
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

      <InvoiceFormDialog open={formOpen} onClose={closeForm} invoice={editInvoice} />
      <ConfirmDeleteDialog
        open={!!deleteInvoice}
        onClose={() => setDeleteInvoice(null)}
        onConfirm={handleDelete}
        title="Smazat fakturu"
        description={`Opravdu chcete smazat fakturu „${deleteInvoice?.invoice_number ?? ''}"? Všechny její platby budou smazány. Tuto akci NELZE vrátit zpět.`}
        entityName={deleteInvoice?.invoice_number ?? ''}
      />
      <InvoiceDetailModal
        open={!!viewInvoice}
        onClose={() => setViewInvoice(null)}
        invoiceId={viewInvoice?.id ?? null}
      />
    </div>
  );
}
