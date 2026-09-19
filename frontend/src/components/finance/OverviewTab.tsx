import { useState } from 'react';
import { useDebounce } from '@/hooks/useDebounce';
import { ColumnDef, SortingState } from '@tanstack/react-table';
import { Search, ChevronLeft, ChevronRight, Pencil, Plus } from 'lucide-react';
import { fmtDate, fmtMoney } from '@/lib/utils';
import { DataTable } from '@/components/shared/DataTable';
import { ActionButtons } from '@/components/shared/ActionButtons';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Select } from '@/components/ui/select';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { ConfirmDeleteDialog } from '@/components/shared/ConfirmDeleteDialog';
import { TransactionFormDialog } from '@/components/finance/TransactionFormDialog';
import { PER_PAGE, transactionCategoryBadge, paymentMethodBadge } from '@/components/finance/financeHelpers';
import { useFinanceOverview, type FinanceOverviewItem } from '@/hooks/useFinanceOverview';
import { useDeleteTransaction, type Transaction } from '@/hooks/useTransactions';
import { useUpdateInvoicePayment, useDeleteInvoicePayment } from '@/hooks/useInvoicePayments';
import { ApiError } from '@/lib/api';

const TYPE_FILTERS = [
  { value: '', label: 'Všechny typy' },
  { value: 'income', label: 'Příjmy' },
  { value: 'expense', label: 'Výdaje' },
];

const PAYMENT_METHODS = [
  { value: 'cash', label: 'Hotovost' },
  { value: 'bank_transfer', label: 'Bankovní převod' },
  { value: 'card', label: 'Karta' },
  { value: 'other', label: 'Jiné' },
];

export default function OverviewTab() {
  const [search, setSearch] = useState('');
  const debouncedSearch = useDebounce(search, 300);
  const [typeFilter, setTypeFilter] = useState('');
  const [fromFilter, setFromFilter] = useState('');
  const [toFilter, setToFilter] = useState('');
  const [page, setPage] = useState(1);
  const [sorting, setSorting] = useState<SortingState>([{ id: 'date', desc: true }]);

  // Detail/edit dialog
  const [detailItem, setDetailItem] = useState<FinanceOverviewItem | null>(null);
  const [editMode, setEditMode] = useState(false);
  const [deleteItem, setDeleteItem] = useState<FinanceOverviewItem | null>(null);
  // Create dialog
  const [createOpen, setCreateOpen] = useState(false);

  const { data, isLoading, isError, error } = useFinanceOverview({
    search: debouncedSearch || undefined,
    type: typeFilter || undefined,
    from: fromFilter || undefined,
    to: toFilter || undefined,
    page,
    per_page: PER_PAGE,
  });

  const deleteTransaction = useDeleteTransaction();
  const deletePayment = useDeleteInvoicePayment();
  const deleteTransactionMutate = deleteTransaction.mutateAsync;

  const totalPages = data ? Math.max(1, Math.ceil(data.total / data.per_page)) : 1;

  // Součty na aktuální stránce
  const pageIncome = data?.data.filter((r) => r.type === 'income').reduce((s, r) => s + r.amount_cents, 0) ?? 0;
  const pageExpense = data?.data.filter((r) => r.type === 'expense').reduce((s, r) => s + r.amount_cents, 0) ?? 0;

  const openDetail = (item: FinanceOverviewItem) => {
    setDetailItem(item);
    setEditMode(false);
  };

  const openEdit = (item: FinanceOverviewItem) => {
    setDetailItem(item);
    setEditMode(true);
  };

  const closeDetail = () => {
    setDetailItem(null);
    setEditMode(false);
  };

  const columns: ColumnDef<FinanceOverviewItem>[] = [
    {
      accessorKey: 'date',
      header: 'Datum',
      cell: ({ row }) => <span className="whitespace-nowrap">{fmtDate(row.original.date)}</span>,
    },
    {
      accessorKey: 'type',
      header: 'Typ',
      cell: ({ row }) => {
        const isIncome = row.original.type === 'income';
        return (
          <Badge variant={isIncome ? 'default' : 'secondary'}>
            {isIncome ? 'Příjem' : 'Výdaj'}
          </Badge>
        );
      },
    },
    {
      accessorKey: 'source',
      header: 'Zdroj',
      enableSorting: false,
      cell: ({ row }) => (
        <Badge variant={row.original.source === 'transaction' ? 'secondary' : 'outline'}>
          {row.original.source === 'transaction' ? 'Transakce' : 'Platba faktury'}
        </Badge>
      ),
    },
    {
      id: 'category',
      header: 'Kategorie / Metoda',
      enableSorting: false,
      cell: ({ row }) => {
        if (row.original.source === 'transaction') {
          const badge = transactionCategoryBadge(row.original.category as Transaction['category']);
          return <Badge variant={badge.variant}>{badge.label}</Badge>;
        }
        const badge = paymentMethodBadge(row.original.payment_method as any);
        return <Badge variant={badge.variant}>{badge.label}</Badge>;
      },
    },
    {
      accessorKey: 'description',
      header: 'Popis',
      enableSorting: false,
      cell: ({ row }) => {
        const desc = row.original.description;
        const inv = row.original.invoice_number;
        return (
          <div className="flex flex-col gap-0.5">
            {desc && <span className="max-w-xs truncate" title={desc}>{desc}</span>}
            {inv && <span className="text-xs text-muted-foreground">Faktura: {inv}</span>}
            {!desc && !inv && <span className="text-muted-foreground">—</span>}
          </div>
        );
      },
    },
    {
      accessorKey: 'amount_cents',
      header: 'Částka',
      cell: ({ row }) => (
        <span className="whitespace-nowrap font-medium">
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
      cell: ({ row }) => (row.original.client_name && row.original.client_name !== '' ? row.original.client_name : '—'),
    },
    {
      id: 'actions',
      header: 'Akce',
      enableSorting: false,
      cell: ({ row }) => (
        <ActionButtons
          actions={[
            { icon: 'view', label: 'Zobrazit', onClick: () => openDetail(row.original) },
            { icon: 'edit', label: 'Upravit', onClick: () => openEdit(row.original) },
            { icon: 'delete', label: 'Smazat', onClick: () => setDeleteItem(row.original), destructive: true },
          ]}
        />
      ),
    },
  ];

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <h2 className="text-xl font-semibold">Přehled příjmů a výdajů</h2>
        <Button onClick={() => setCreateOpen(true)}>
          <Plus className="h-4 w-4" />
          Nová transakce
        </Button>
      </div>

      {/* Filtry */}
      <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:flex-wrap">
        <div className="relative max-w-sm flex-1">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            placeholder="Hledat podle popisu, faktury..."
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
          {TYPE_FILTERS.map((opt) => (
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

      {/* Součty na stránce */}
      {data && data.data.length > 0 && (
        <div className="flex gap-4 text-sm">
          <span className="text-muted-foreground">
            Příjmy na stránce: <span className="font-medium">{fmtMoney(pageIncome)}</span>
          </span>
          <span className="text-muted-foreground">
            Výdaje na stránce: <span className="font-medium">{fmtMoney(pageExpense)}</span>
          </span>
          <span className="text-muted-foreground">
            Saldo: <span className="font-medium">{fmtMoney(pageIncome - pageExpense)}</span>
          </span>
        </div>
      )}

      {isLoading && (
        <Card>
          <CardContent className="py-10 text-center text-muted-foreground">
            Načítání přehledu...
          </CardContent>
        </Card>
      )}

      {isError && (
        <Card>
          <CardContent className="py-10 text-center text-destructive">
            Chyba při načítání přehledu: {error instanceof Error ? error.message : 'Neznámá chyba'}
          </CardContent>
        </Card>
      )}

      {data && (
        <>
          <DataTable columns={columns} data={data.data} sorting={sorting} onSortingChange={setSorting} />

          {data.data.length === 0 && (
            <Card>
              <CardContent className="py-10 text-center text-muted-foreground">
                {search || typeFilter || fromFilter || toFilter
                  ? 'Žádné záznamy neodpovídají filtrům.'
                  : 'Zatím nejsou žádné finanční záznamy.'}
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

      {/* Detail / Edit dialog */}
      {detailItem && (
        <OverviewDetailDialog
          item={detailItem}
          editMode={editMode}
          onClose={closeDetail}
          onToggleEdit={() => setEditMode((v) => !v)}
        />
      )}

      {/* Create dialog */}
      <TransactionFormDialog open={createOpen} onClose={() => setCreateOpen(false)} />

      {/* Delete dialog */}
      <ConfirmDeleteDialog
        open={!!deleteItem}
        onClose={() => setDeleteItem(null)}
        onConfirm={async () => {
          if (!deleteItem) return;
          try {
            if (deleteItem.source === 'transaction') {
              await deleteTransactionMutate(deleteItem.source_id);
            } else {
              // invoice_payment - potřebuje invoiceId z položky
              await deletePayment.mutateAsync({ id: deleteItem.source_id, invoiceId: 0 });
            }
          } catch {
            // chyba zobrazena přes toast
          }
          setDeleteItem(null);
        }}
        title={deleteItem?.source === 'transaction' ? 'Smazat transakci' : 'Smazat platbu'}
        description={deleteItem?.source === 'transaction'
          ? 'Opravdu chcete smazat tuto transakci? Tuto akci NELZE vrátit zpět.'
          : 'Opravdu chcete smazat tuto platbu? Toto ovlivní i stav faktury. Tuto akci NELZE vrátit zpět.'}
        entityName={deleteItem ? `${fmtMoney(deleteItem.amount_cents)}` : ''}
      />
    </div>
  );
}

// === Detail / Edit Dialog ===

interface OverviewDetailDialogProps {
  item: FinanceOverviewItem;
  editMode: boolean;
  onClose: () => void;
  onToggleEdit: () => void;
}

function OverviewDetailDialog({ item, editMode, onClose, onToggleEdit }: OverviewDetailDialogProps) {
  if (item.source === 'transaction') {
    return <TransactionEditDialog item={item} editMode={editMode} onClose={onClose} onToggleEdit={onToggleEdit} />;
  }
  return <PaymentEditDialog item={item} editMode={editMode} onClose={onClose} onToggleEdit={onToggleEdit} />;
}

// === Transaction edit ===

interface TransactionEditDialogProps {
  item: FinanceOverviewItem;
  editMode: boolean;
  onClose: () => void;
  onToggleEdit: () => void;
}

function TransactionEditDialog({ item, editMode, onClose, onToggleEdit }: TransactionEditDialogProps) {
  // === Detail view (read-only) ===
  if (!editMode) {
    return (
      <Dialog
        open={true}
        onClose={onClose}
        title="Detail transakce"
        description={fmtDate(item.date)}
        className="max-w-lg"
      >
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <DetailField label="Typ" value={
              <Badge variant="default">Příjem</Badge>
            } />
            <DetailField label="Částka" value={
              <span className="font-medium">
                +{fmtMoney(item.amount_cents)}
              </span>
            } />
            <DetailField label="Kategorie" value={transactionCategoryBadge(item.category as Transaction['category']).label} />
            <DetailField label="Datum" value={fmtDate(item.date)} />
            <DetailField label="Projekt" value={item.project_name ?? '—'} />
            <DetailField label="Klient" value={item.client_name || '—'} />
            <DetailField label="Faktura" value={item.invoice_number ?? '—'} />
          </div>
          <DetailField label="Popis" value={item.description ?? '—'} fullWidth />
          <div className="flex justify-end gap-2 pt-2">
            <Button variant="outline" onClick={onClose}>Zavřít</Button>
            <Button onClick={onToggleEdit}>
              <Pencil className="h-4 w-4" />
              Upravit
            </Button>
          </div>
        </div>
      </Dialog>
    );
  }

  // === Edit view - použij existující TransactionFormDialog ===
  const tx: Transaction = {
    id: item.source_id,
    project_id: item.project_id,
    project_name: item.project_name,
    client_id: item.client_id,
    client_name: item.client_name,
    invoice_id: item.invoice_id ?? null,
    invoice_number: item.invoice_number,
    type: item.type,
    amount_cents: item.amount_cents,
    category: item.category as Transaction['category'],
    description: item.description,
    transaction_date: item.date,
    created_at: item.created_at,
    updated_at: item.updated_at ?? item.created_at,
  };

  return <TransactionFormDialog open={true} onClose={onClose} transaction={tx} />;
}

// === Payment edit ===

interface PaymentEditDialogProps {
  item: FinanceOverviewItem;
  editMode: boolean;
  onClose: () => void;
  onToggleEdit: () => void;
}

function PaymentEditDialog({ item, editMode, onClose, onToggleEdit }: PaymentEditDialogProps) {
  const [form, setForm] = useState({
    amount_cents: String(item.amount_cents / 100),
    payment_date: item.date,
    method: item.payment_method ?? 'bank_transfer',
    note: item.description ?? '',
  });
  const [errors, setErrors] = useState<Record<string, string>>({});

  const updatePayment = useUpdateInvoicePayment();

  const update = (field: string, value: string) => {
    setForm((prev) => ({ ...prev, [field]: value }));
  };

  const handleSubmit = async (ev: React.FormEvent) => {
    ev.preventDefault();
    const e: Record<string, string> = {};
    const amount = Number(form.amount_cents);
    if (isNaN(amount) || amount <= 0) e.amount_cents = 'Částka musí být kladná';
    if (!form.payment_date) e.payment_date = 'Datum platby je povinné';
    setErrors(e);
    if (Object.keys(e).length > 0) return;

    try {
      await updatePayment.mutateAsync({
        id: item.source_id,
        data: {
          amount_cents: Math.round(Number(form.amount_cents) * 100),
          payment_date: form.payment_date,
          method: form.method as any,
          note: form.note.trim() || null,
        },
      });
      onClose();
    } catch (err) {
      if (err instanceof ApiError && err.body.fields) {
        setErrors(err.body.fields);
      } else if (err instanceof Error) {
        setErrors({ form: err.message });
      }
    }
  };

  // === Detail view (read-only) ===
  if (!editMode) {
    return (
      <Dialog
        open={true}
        onClose={onClose}
        title="Detail platby faktury"
        description={fmtDate(item.date)}
        className="max-w-lg"
      >
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <DetailField label="Typ" value={
              <Badge variant="default">Příjem</Badge>
            } />
            <DetailField label="Částka" value={
              <span className="font-medium">+{fmtMoney(item.amount_cents)}</span>
            } />
            <DetailField label="Faktura" value={item.invoice_number ?? '—'} />
            <DetailField label="Metoda" value={paymentMethodBadge(item.payment_method as any).label} />
            <DetailField label="Datum" value={fmtDate(item.date)} />
            <DetailField label="Projekt" value={item.project_name ?? '—'} />
            <DetailField label="Klient" value={item.client_name || '—'} />
          </div>
          <DetailField label="Poznámka" value={item.description ?? '—'} fullWidth />
          <div className="flex justify-end gap-2 pt-2">
            <Button variant="outline" onClick={onClose}>Zavřít</Button>
            <Button onClick={onToggleEdit}>
              <Pencil className="h-4 w-4" />
              Upravit
            </Button>
          </div>
        </div>
      </Dialog>
    );
  }

  // === Edit view ===
  return (
    <Dialog
      open={true}
      onClose={onClose}
      title="Upravit platbu"
      description={`Faktura: ${item.invoice_number ?? '—'}`}
      className="max-w-xl"
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-1.5">
            <Label htmlFor="pay-amount">Částka (Kč) *</Label>
            <Input
              id="pay-amount"
              type="number"
              min={0}
              step="0.01"
              value={form.amount_cents}
              onChange={(e) => update('amount_cents', e.target.value)}
            />
            {errors.amount_cents && <p className="text-xs text-destructive">{errors.amount_cents}</p>}
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="pay-date">Datum platby *</Label>
            <Input
              id="pay-date"
              type="date"
              value={form.payment_date}
              onChange={(e) => update('payment_date', e.target.value)}
            />
            {errors.payment_date && <p className="text-xs text-destructive">{errors.payment_date}</p>}
          </div>
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="pay-method">Metoda</Label>
          <Select id="pay-method" value={form.method} onChange={(e) => update('method', e.target.value)}>
            {PAYMENT_METHODS.map((opt) => (
              <option key={opt.value} value={opt.value}>{opt.label}</option>
            ))}
          </Select>
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="pay-note">Poznámka</Label>
          <Input
            id="pay-note"
            value={form.note}
            onChange={(e) => update('note', e.target.value)}
          />
        </div>

        {errors.form && <p className="text-sm text-destructive">{errors.form}</p>}

        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="outline" onClick={onClose}>Zrušit</Button>
          <Button type="submit" disabled={updatePayment.isPending}>
            {updatePayment.isPending ? 'Ukládám...' : 'Uložit změny'}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}

// === Helper: Detail field ===

function DetailField({ label, value, fullWidth }: { label: string; value: React.ReactNode; fullWidth?: boolean }) {
  return (
    <div className={fullWidth ? 'col-span-2 space-y-1' : 'space-y-1'}>
      <p className="text-xs text-muted-foreground">{label}</p>
      <div className="text-sm font-medium">{value}</div>
    </div>
  );
}
