import { useState } from 'react';
import { useDebounce } from '@/hooks/useDebounce';
import { useNavigate } from 'react-router-dom';
import { ColumnDef, SortingState } from '@tanstack/react-table';
import {
  Plus,
  Search,
  ChevronLeft,
  ChevronRight,
} from 'lucide-react';
import { cn, fmtDate, fmtMoney } from '@/lib/utils';
import { DataTable } from '@/components/shared/DataTable';
import { ActionButtons } from '@/components/shared/ActionButtons';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Select } from '@/components/ui/select';
import { Card, CardContent } from '@/components/ui/card';
import { ConfirmDeleteDialog } from '@/components/shared/ConfirmDeleteDialog';
import {
  useTransactions,
  useDeleteTransaction,
  type Transaction,
} from '@/hooks/useTransactions';
import { TransactionFormDialog } from '@/components/finance/TransactionFormDialog';
import {
  PER_PAGE,
  TRANSACTION_TYPE_FILTERS,
  TRANSACTION_CATEGORY_FILTERS,
  transactionTypeBadge,
  transactionCategoryBadge,
} from '@/components/finance/financeHelpers';

export default function TransactionsTab() {
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const debouncedSearch = useDebounce(search, 300);
  const [typeFilter, setTypeFilter] = useState('');
  const [categoryFilter, setCategoryFilter] = useState('');
  const [fromFilter, setFromFilter] = useState('');
  const [toFilter, setToFilter] = useState('');
  const [page, setPage] = useState(1);
  const [sorting, setSorting] = useState<SortingState>([{ id: 'transaction_date', desc: true }]);
  const [formOpen, setFormOpen] = useState(false);
  const [editTransaction, setEditTransaction] = useState<Transaction | null>(null);
  const [deleteTransaction, setDeleteTransaction] = useState<Transaction | null>(null);

  const { data, isLoading, isError, error } = useTransactions({
    search: debouncedSearch || undefined,
    page,
    per_page: PER_PAGE,
    type: typeFilter || undefined,
    category: categoryFilter || undefined,
    from: fromFilter || undefined,
    to: toFilter || undefined,
  });

  const deleteMutation = useDeleteTransaction();

  const handleDelete = async () => {
    if (!deleteTransaction) return;
    try {
      await deleteMutation.mutateAsync(deleteTransaction.id);
      setDeleteTransaction(null);
    } catch {
      // chyba
    }
  };

  const openEdit = (transaction: Transaction) => {
    setEditTransaction(transaction);
    setFormOpen(true);
  };

  const openCreate = () => {
    setEditTransaction(null);
    setFormOpen(true);
  };

  const closeForm = () => {
    setFormOpen(false);
    setEditTransaction(null);
  };

  const totalPages = data ? Math.max(1, Math.ceil(data.total / data.per_page)) : 1;

  const columns: ColumnDef<Transaction>[] = [
    {
      accessorKey: 'transaction_date',
      header: 'Datum',
      cell: ({ row }) => fmtDate(row.original.transaction_date),
    },
    {
      accessorKey: 'type',
      header: 'Typ',
      cell: ({ row }) => {
        const badge = transactionTypeBadge(row.original.type);
        return <Badge variant={badge.variant}>{badge.label}</Badge>;
      },
    },
    {
      accessorKey: 'category',
      header: 'Kategorie',
      cell: ({ row }) => {
        const badge = transactionCategoryBadge(row.original.category);
        return <Badge variant={badge.variant}>{badge.label}</Badge>;
      },
    },
    {
      accessorKey: 'description',
      header: 'Popis',
      enableSorting: false,
      cell: ({ row }) => row.original.description ?? '—',
    },
    {
      accessorKey: 'amount_cents',
      header: 'Částka',
      cell: ({ row }) => (
        <span className={cn('font-medium', row.original.type === 'income' ? 'text-primary' : 'text-destructive')}>
          {row.original.type === 'income' ? '+' : '-'}{fmtMoney(row.original.amount_cents)}
        </span>
      ),
    },
    {
      accessorKey: 'project_name',
      header: 'Projekt',
      enableSorting: false,
      cell: ({ row }) => row.original.project_name ?? '—',
    },
    {
      accessorKey: 'client_name',
      header: 'Klient',
      enableSorting: false,
      cell: ({ row }) => row.original.client_name ?? '—',
    },
    {
      id: 'actions',
      header: 'Akce',
      enableSorting: false,
      cell: ({ row }) => (
        <ActionButtons
          actions={[
            { icon: 'view', label: 'Zobrazit', onClick: () => navigate(`/transactions/${row.original.id}`) },
            { icon: 'edit', label: 'Upravit', onClick: () => openEdit(row.original) },
            { icon: 'delete', label: 'Smazat', onClick: () => setDeleteTransaction(row.original), destructive: true },
          ]}
        />
      ),
    },
  ];

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <h2 className="text-xl font-semibold">Transakce</h2>
        <Button onClick={openCreate}>
          <Plus className="h-4 w-4" />
          Nová transakce
        </Button>
      </div>

      {/* Filtry */}
      <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:flex-wrap">
        <div className="relative max-w-sm flex-1">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            placeholder="Hledat podle popisu..."
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
            className="pl-9"
          />
        </div>
        <Select
          value={typeFilter}
          onChange={(e) => {
            setTypeFilter(e.target.value);
            setPage(1);
          }}
          className="lg:w-40"
        >
          {TRANSACTION_TYPE_FILTERS.map((opt) => (
            <option key={opt.value} value={opt.value}>
              {opt.label}
            </option>
          ))}
        </Select>
        <Select
          value={categoryFilter}
          onChange={(e) => {
            setCategoryFilter(e.target.value);
            setPage(1);
          }}
          className="lg:w-48"
        >
          {TRANSACTION_CATEGORY_FILTERS.map((opt) => (
            <option key={opt.value} value={opt.value}>
              {opt.label}
            </option>
          ))}
        </Select>
        <Input
          type="date"
          value={fromFilter}
          onChange={(e) => {
            setFromFilter(e.target.value);
            setPage(1);
          }}
          className="lg:w-40"
          aria-label="Od data"
        />
        <Input
          type="date"
          value={toFilter}
          onChange={(e) => {
            setToFilter(e.target.value);
            setPage(1);
          }}
          className="lg:w-40"
          aria-label="Do data"
        />
      </div>

      {isLoading && (
        <Card>
          <CardContent className="py-10 text-center text-muted-foreground">
            Načítání transakcí...
          </CardContent>
        </Card>
      )}

      {isError && (
        <Card>
          <CardContent className="py-10 text-center text-destructive">
            Chyba při načítání transakcí:{' '}
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
                {search || typeFilter || categoryFilter || fromFilter || toFilter
                  ? 'Žádné transakce neodpovídají filtrům.'
                  : 'Zatím nebyly vytvořeny žádné transakce.'}
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

      <TransactionFormDialog open={formOpen} onClose={closeForm} transaction={editTransaction} />
      <ConfirmDeleteDialog
        open={!!deleteTransaction}
        onClose={() => setDeleteTransaction(null)}
        onConfirm={handleDelete}
        title="Smazat transakci"
        description={`Opravdu chcete smazat tuto transakci? Tuto akci NELZE vrátit zpět.`}
        entityName={`Transakce ${deleteTransaction?.amount_cents ? (deleteTransaction.amount_cents/100) + ' Kč' : ''}`}
      />
    </div>
  );
}
