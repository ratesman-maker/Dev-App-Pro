import { useState } from 'react';
import { ColumnDef } from '@tanstack/react-table';
import {
  Plus,
} from 'lucide-react';
import { fmtDate, fmtMoney } from '@/lib/utils';
import { DataTable } from '@/components/shared/DataTable';
import { ActionButtons } from '@/components/shared/ActionButtons';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Select } from '@/components/ui/select';
import { Card, CardContent } from '@/components/ui/card';
import { ConfirmDeleteDialog } from '@/components/shared/ConfirmDeleteDialog';
import {
  useInvoices,
} from '@/hooks/useInvoices';
import {
  useInvoicePayments,
  useDeleteInvoicePayment,
  type InvoicePayment,
} from '@/hooks/useInvoicePayments';
import { InvoicePaymentFormDialog } from '@/components/finance/InvoicePaymentFormDialog';
import {
  paymentMethodBadge,
} from '@/components/finance/financeHelpers';

export default function PaymentsTab() {
  const [selectedInvoiceId, setSelectedInvoiceId] = useState<number | null>(null);
  const [formOpen, setFormOpen] = useState(false);
  const [deletePayment, setDeletePayment] = useState<InvoicePayment | null>(null);

  const { data: invoicesData } = useInvoices({ per_page: 200 });
  const { data: paymentsData, isLoading, isError, error } = useInvoicePayments(selectedInvoiceId);
  const deleteMutation = useDeleteInvoicePayment();

  const handleDelete = async () => {
    if (!deletePayment) return;
    try {
      await deleteMutation.mutateAsync({ id: deletePayment.id, invoiceId: deletePayment.invoice_id });
      setDeletePayment(null);
    } catch {
      // chyba
    }
  };

  const columns: ColumnDef<InvoicePayment>[] = [
    {
      accessorKey: 'payment_date',
      header: 'Datum',
      cell: ({ row }) => fmtDate(row.original.payment_date),
    },
    {
      accessorKey: 'amount_cents',
      header: 'Částka',
      cell: ({ row }) => <span className="font-medium">{fmtMoney(row.original.amount_cents)}</span>,
    },
    {
      accessorKey: 'method',
      header: 'Metoda',
      cell: ({ row }) => {
        const badge = paymentMethodBadge(row.original.method);
        return <Badge variant={badge.variant}>{badge.label}</Badge>;
      },
    },
    {
      accessorKey: 'note',
      header: 'Poznámka',
      enableSorting: false,
      cell: ({ row }) => row.original.note ?? '—',
    },
    {
      id: 'actions',
      header: 'Akce',
      enableSorting: false,
      cell: ({ row }) => (
        <ActionButtons
          actions={[
            { icon: 'delete', label: 'Smazat', onClick: () => setDeletePayment(row.original), destructive: true },
          ]}
        />
      ),
    },
  ];

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <h2 className="text-xl font-semibold">Platby</h2>
        <Button onClick={() => setFormOpen(true)}>
          <Plus className="h-4 w-4" />
          Nová platba
        </Button>
      </div>

      {/* Výběr faktury */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
        <label className="text-sm text-muted-foreground">Faktura:</label>
        <Select
          value={selectedInvoiceId ? String(selectedInvoiceId) : ''}
          onChange={(e) => setSelectedInvoiceId(e.target.value ? Number(e.target.value) : null)}
          className="sm:w-80"
        >
          <option value="">— vyberte fakturu —</option>
          {invoicesData?.data.map((inv) => (
            <option key={inv.id} value={inv.id}>
              {inv.invoice_number}
              {inv.client_name ? ` (${inv.client_name})` : ''}
            </option>
          ))}
        </Select>
      </div>

      {!selectedInvoiceId && (
        <Card>
          <CardContent className="py-10 text-center text-muted-foreground">
            Vyberte fakturu pro zobrazení jejích plateb.
          </CardContent>
        </Card>
      )}

      {selectedInvoiceId && isLoading && (
        <Card>
          <CardContent className="py-10 text-center text-muted-foreground">
            Načítání plateb...
          </CardContent>
        </Card>
      )}

      {selectedInvoiceId && isError && (
        <Card>
          <CardContent className="py-10 text-center text-destructive">
            Chyba při načítání plateb:{' '}
            {error instanceof Error ? error.message : 'Neznámá chyba'}
          </CardContent>
        </Card>
      )}

      {selectedInvoiceId && paymentsData && (
        <>
          <DataTable columns={columns} data={paymentsData.data} />
          {paymentsData.data.length === 0 && (
            <Card>
              <CardContent className="py-10 text-center text-muted-foreground">
                Tato faktura nemá žádné platby.
              </CardContent>
            </Card>
          )}
        </>
      )}

      <InvoicePaymentFormDialog
        open={formOpen}
        onClose={() => setFormOpen(false)}
        invoiceId={selectedInvoiceId}
      />
      <ConfirmDeleteDialog
        open={!!deletePayment}
        onClose={() => setDeletePayment(null)}
        onConfirm={handleDelete}
        title="Smazat platbu"
        description={`Opravdu chcete smazat tuto platbu? Faktura bude přepočítána. Tuto akci NELZE vrátit zpět.`}
        entityName={`Platba ${deletePayment?.amount_cents ? (deletePayment.amount_cents/100) + ' Kč' : ''}`}
      />
    </div>
  );
}
