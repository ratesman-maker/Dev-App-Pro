import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Pencil, Trash2, Download, Plus } from 'lucide-react';
import { api, ApiError } from '@/lib/api';
import { fmtDate, fmtDateTime, fmtMoney, fmtBytes } from '@/lib/utils';
import { type Invoice, useDeleteInvoice, useDownloadInvoicePdf } from '@/hooks/useInvoices';
import {
  useInvoicePayments,
  useDeleteInvoicePayment,
  type InvoicePayment,
} from '@/hooks/useInvoicePayments';
import { useNotes } from '@/hooks/useNotes';
import { useFiles } from '@/hooks/useFiles';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { ActionButtons } from '@/components/shared/ActionButtons';
import { DetailModal, type DetailModalTab } from '@/components/shared/DetailModal';
import { InvoiceFormDialog } from '@/components/finance/InvoiceFormDialog';
import { InvoicePaymentFormDialog } from '@/components/finance/InvoicePaymentFormDialog';
import { ConfirmDeleteDialog } from '@/components/shared/ConfirmDeleteDialog';

type BadgeVariant = 'default' | 'secondary' | 'destructive' | 'outline';

function invoiceStatusBadge(status: Invoice['status']): { variant: BadgeVariant; label: string } {
  switch (status) {
    case 'draft':
      return { variant: 'secondary', label: 'Koncept' };
    case 'sent':
      return { variant: 'default', label: 'Odesláno' };
    case 'paid':
      return { variant: 'default', label: 'Zaplaceno' };
    case 'overdue':
      return { variant: 'destructive', label: 'Po splatnosti' };
    case 'cancelled':
      return { variant: 'outline', label: 'Zrušeno' };
    default:
      return { variant: 'outline', label: status };
  }
}

function paymentMethodBadge(method: InvoicePayment['method']): { variant: BadgeVariant; label: string } {
  switch (method) {
    case 'cash':
      return { variant: 'secondary', label: 'Hotovost' };
    case 'bank_transfer':
      return { variant: 'default', label: 'Bankovní převod' };
    case 'card':
      return { variant: 'default', label: 'Karta' };
    case 'other':
      return { variant: 'outline', label: 'Jiné' };
    default:
      return { variant: 'outline', label: method };
  }
}

function DetailRow({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5 py-2 border-b border-border last:border-0">
      <dt className="text-xs font-medium text-muted-foreground">{label}</dt>
      <dd className="text-sm whitespace-pre-wrap">{value || '—'}</dd>
    </div>
  );
}

function LoadingState({ text }: { text: string }) {
  return <div className="py-10 text-center text-sm text-muted-foreground">{text}</div>;
}

function ErrorState({ message }: { message: string }) {
  return <div className="py-10 text-center text-sm text-destructive">{message}</div>;
}

function EmptyState({ text }: { text: string }) {
  return <div className="py-10 text-center text-sm text-muted-foreground">{text}</div>;
}

interface InvoiceDetailModalProps {
  open: boolean;
  onClose: () => void;
  invoiceId: number | null;
}

export function InvoiceDetailModal({ open, onClose, invoiceId }: InvoiceDetailModalProps) {
  const [formOpen, setFormOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [paymentFormOpen, setPaymentFormOpen] = useState(false);
  const [editingPayment, setEditingPayment] = useState<InvoicePayment | null>(null);
  const [deletePayment, setDeletePayment] = useState<InvoicePayment | null>(null);

  const { data: invoice, isLoading, isError, error } = useQuery<Invoice>({
    queryKey: ['invoice', invoiceId],
    queryFn: () => api.get<Invoice>(`/invoices/${invoiceId}`),
    enabled: open && !!invoiceId,
  });

  const isNotFound = isError && error instanceof ApiError && error.status === 404;

  const { data: paymentsData, isLoading: paymentsLoading, isError: paymentsError } = useInvoicePayments(invoice ? invoiceId : null);
  const { data: notesData, isLoading: notesLoading, isError: notesError } = useNotes({
    entity_type: 'invoice',
    entity_id: invoiceId ?? undefined,
    per_page: 200,
  });
  const { data: filesData, isLoading: filesLoading, isError: filesError } = useFiles({
    entity_type: 'invoice',
    entity_id: invoiceId ?? undefined,
    per_page: 200,
  });

  const deleteInvoiceMutation = useDeleteInvoice();
  const pdfMutation = useDownloadInvoicePdf();
  const deletePaymentMutation = useDeleteInvoicePayment();

  const handleDelete = async () => {
    if (!invoiceId) return;
    try {
      await deleteInvoiceMutation.mutateAsync(invoiceId);
      onClose();
    } catch {
      // chyba se zobrazí v dialogu
    }
  };

  const handlePdf = async () => {
    if (!invoice) return;
    try {
      await pdfMutation.mutateAsync(invoice);
    } catch {
      // chyba stažení PDF
    }
  };

  const handleDeletePayment = async () => {
    if (!deletePayment) return;
    try {
      await deletePaymentMutation.mutateAsync({ id: deletePayment.id, invoiceId: deletePayment.invoice_id });
      setDeletePayment(null);
    } catch {
      // chyba
    }
  };

  // Loading stav
  if (open && isLoading) {
    return (
      <DetailModal
        open={open}
        onClose={onClose}
        title="Načítání..."
        tabs={[{ id: 'detail', label: 'Detail', content: <LoadingState text="Načítání faktury..." /> }]}
      />
    );
  }

  // 404 stav
  if (open && isNotFound) {
    return (
      <DetailModal
        open={open}
        onClose={onClose}
        title="Faktura nenalezena"
        tabs={[{ id: 'detail', label: 'Detail', content: <EmptyState text="Faktura neexistuje nebo byla smazána." /> }]}
      />
    );
  }

  // Error stav
  if (open && isError) {
    return (
      <DetailModal
        open={open}
        onClose={onClose}
        title="Chyba"
        tabs={[{ id: 'detail', label: 'Detail', content: <ErrorState message={error instanceof Error ? error.message : 'Neznámá chyba'} /> }]}
      />
    );
  }

  if (!open || !invoice) return null;

  const statusBadge = invoiceStatusBadge(invoice.status);
  const payments = paymentsData?.data ?? [];
  const notes = notesData?.data ?? [];
  const files = filesData?.data ?? [];
  const remaining = invoice.amount_cents - invoice.paid_cents;

  const tabs: DetailModalTab[] = [
    {
      id: 'detail',
      label: 'Detail',
      content: (
        <Card>
          <CardContent>
            <dl>
              <DetailRow label="Klient" value={invoice.client_name} />
              <DetailRow label="Projekt" value={invoice.project_name} />
              <DetailRow label="Status" value={<Badge variant={statusBadge.variant}>{statusBadge.label}</Badge>} />
              <DetailRow label="Vystavena" value={fmtDate(invoice.issue_date)} />
              <DetailRow label="Datum plnění (DZP)" value={invoice.taxable_date ? fmtDate(invoice.taxable_date) : null} />
              <DetailRow label="Splatnost" value={fmtDate(invoice.due_date)} />
              <DetailRow label="Variabilní symbol" value={invoice.variable_symbol} />
              <DetailRow label="Konstantní symbol" value={invoice.constant_symbol} />
              <DetailRow label="IBAN" value={invoice.iban} />
              <DetailRow label="Poznámka" value={invoice.note} />
              <DetailRow
                label="Archivní PDF"
                value={invoice.frozen_pdf ? 'Zmrazená kopie vydané faktury' : null}
              />
              <DetailRow label="Vytvořeno" value={fmtDateTime(invoice.created_at)} />
              {invoice.items?.length > 0 && (
                <div className="py-2">
                  <dt className="text-xs font-medium text-muted-foreground mb-2">Položky</dt>
                  <div className="space-y-1">
                    {invoice.items.map((item) => (
                      <div key={item.id} className="flex items-center justify-between gap-2 text-sm">
                        <span className="min-w-0 flex-1 truncate">
                          {item.description}
                          {item.quantity !== '1' && item.quantity !== 1 && (
                            <span className="text-muted-foreground">
                              {' '}× {item.quantity}{item.unit ? ` ${item.unit}` : ''}
                            </span>
                          )}
                        </span>
                        <span className="tabular-nums text-muted-foreground">
                          {fmtMoney(Number(item.unit_price_cents) * Number(item.quantity))}
                        </span>
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </dl>
          </CardContent>
        </Card>
      ),
    },
    {
      id: 'amounts',
      label: 'Částky',
      content: (
        <Card>
          <CardContent>
            <dl>
              <DetailRow label="Bez DPH" value={fmtMoney(invoice.subtotal_cents)} />
              <DetailRow label="DPH sazba" value={`${invoice.vat_rate_percent} %`} />
              <DetailRow label="DPH částka" value={fmtMoney(invoice.vat_amount_cents)} />
              <DetailRow label="Celkem" value={<span className="font-medium">{fmtMoney(invoice.amount_cents)}</span>} />
              <DetailRow label="Zaplaceno" value={fmtMoney(invoice.paid_cents)} />
              <DetailRow
                label="Zbývá zaplatit"
                value={
                  <span className={remaining > 0 ? 'font-medium text-destructive' : 'font-medium text-emerald-600'}>
                    {fmtMoney(remaining)}
                  </span>
                }
              />
            </dl>
          </CardContent>
        </Card>
      ),
    },
    {
      id: 'payments',
      label: `Platby (${payments.length})`,
      content: paymentsLoading ? (
        <LoadingState text="Načítání plateb..." />
      ) : paymentsError ? (
        <ErrorState message="Chyba při načítání plateb." />
      ) : (
        <div className="space-y-3">
          <div className="flex justify-end">
            <Button size="sm" onClick={() => { setEditingPayment(null); setPaymentFormOpen(true); }}>
              <Plus className="h-4 w-4" />
              Přidat platbu
            </Button>
          </div>
          {payments.length === 0 ? (
            <EmptyState text="Žádné platby." />
          ) : (
            <ul className="divide-y divide-border">
              {payments.map((p) => {
                const methodBadge = paymentMethodBadge(p.method);
                return (
                  <li key={p.id} className="flex items-center justify-between gap-2 py-3">
                    <div className="flex items-center gap-3">
                      <div className="flex flex-col">
                        <span className="font-medium">{fmtMoney(p.amount_cents)}</span>
                        <div className="flex items-center gap-2 text-xs text-muted-foreground">
                          <span>{fmtDate(p.payment_date)}</span>
                          <Badge variant={methodBadge.variant}>{methodBadge.label}</Badge>
                          {p.note && <span>· {p.note}</span>}
                        </div>
                      </div>
                    </div>
                    <ActionButtons
                      actions={[
                        { icon: 'edit', label: 'Upravit platbu', onClick: () => { setEditingPayment(p); setPaymentFormOpen(true); } },
                        { icon: 'delete', label: 'Smazat platbu', onClick: () => setDeletePayment(p), destructive: true },
                      ]}
                    />
                  </li>
                );
              })}
            </ul>
          )}
        </div>
      ),
    },
    {
      id: 'notes',
      label: `Poznámky (${notes.length})`,
      content: notesLoading ? (
        <LoadingState text="Načítání poznámek..." />
      ) : notesError ? (
        <ErrorState message="Chyba při načítání poznámek." />
      ) : notes.length === 0 ? (
        <EmptyState text="Žádné poznámky." />
      ) : (
        <ul className="divide-y divide-border">
          {notes.map((n) => (
            <li key={n.id} className="py-3">
              {n.title && <div className="font-medium">{n.title}</div>}
              <div className="text-sm text-muted-foreground whitespace-pre-wrap">{n.content}</div>
              <div className="mt-1 text-xs text-muted-foreground">
                {fmtDateTime(n.created_at)}
                {n.user_name && ` · ${n.user_name}`}
              </div>
            </li>
          ))}
        </ul>
      ),
    },
    {
      id: 'files',
      label: `Soubory (${files.length})`,
      content: filesLoading ? (
        <LoadingState text="Načítání souborů..." />
      ) : filesError ? (
        <ErrorState message="Chyba při načítání souborů." />
      ) : files.length === 0 ? (
        <EmptyState text="Žádné soubory." />
      ) : (
        <ul className="divide-y divide-border">
          {files.map((f) => (
            <li key={f.id} className="flex items-center gap-3 py-3">
              {f.is_image && f.thumbnail_path ? (
                <img
                  src={`/api/files/${f.id}/thumbnail`}
                  alt={f.original_name}
                  className="h-12 w-12 rounded object-cover"
                  loading="lazy"
                  width={48}
                  height={48}
                />
              ) : (
                <div className="flex h-12 w-12 items-center justify-center rounded bg-muted text-xs text-muted-foreground">
                  {f.mime_type.split('/')[0] === 'image' ? 'IMG' : f.original_name.split('.').pop()?.toUpperCase().slice(0, 4) || 'FILE'}
                </div>
              )}
              <div className="flex-1 min-w-0">
                <div className="truncate text-sm font-medium">{f.original_name}</div>
                <div className="text-xs text-muted-foreground">
                  {f.mime_type} · {fmtBytes(f.size_bytes)}
                </div>
              </div>
            </li>
          ))}
        </ul>
      ),
    },
  ];

  return (
    <>
      <DetailModal
        open={open}
        onClose={onClose}
        title={invoice.invoice_number}
        badges={<Badge variant={statusBadge.variant}>{statusBadge.label}</Badge>}
        actions={
          <>
            <Button variant="outline" size="sm" onClick={() => setFormOpen(true)}>
              <Pencil className="h-4 w-4" />
              Upravit
            </Button>
            <Button variant="outline" size="sm" onClick={handlePdf} disabled={pdfMutation.isPending}>
              <Download className="h-4 w-4" />
              PDF
            </Button>
            <Button variant="destructive" size="sm" onClick={() => setDeleteOpen(true)}>
              <Trash2 className="h-4 w-4" />
              Smazat
            </Button>
          </>
        }
        tabs={tabs}
        size="lg"
      />
      <InvoiceFormDialog open={formOpen} onClose={() => setFormOpen(false)} invoice={invoice} />
      <InvoicePaymentFormDialog open={paymentFormOpen} onClose={() => { setPaymentFormOpen(false); setEditingPayment(null); }} invoiceId={invoiceId} payment={editingPayment} />
      <ConfirmDeleteDialog
        open={deleteOpen}
        onClose={() => setDeleteOpen(false)}
        onConfirm={handleDelete}
        title="Smazat fakturu"
        description={`Opravdu chcete smazat fakturu „${invoice.invoice_number}"? Všechny její platby budou smazány. Tuto akci NELZE vrátit zpět.`}
        entityName={invoice.invoice_number}
      />
      <ConfirmDeleteDialog
        open={!!deletePayment}
        onClose={() => setDeletePayment(null)}
        onConfirm={handleDeletePayment}
        title="Smazat platbu"
        description="Opravdu chcete smazat tuto platbu? Faktura bude přepočítána. Tuto akci NELZE vrátit zpět."
        entityName={deletePayment ? `${(deletePayment.amount_cents / 100).toLocaleString('cs-CZ')} Kč` : ''}
      />
    </>
  );
}
