import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Trash2, FileText } from 'lucide-react';
import { api, ApiError } from '@/lib/api';
import { fmtDate, fmtDateTime, fmtMoney, fmtBytes } from '@/lib/utils';
import { EmailLink, PhoneLink } from '@/components/shared/ContactLinks';
import { type Client } from '@/hooks/useClients';
import { useProjects } from '@/hooks/useProjects';
import {
  useInvoices,
  useUpdateInvoice,
  useDeleteInvoice,
  useDownloadInvoicePdf,
  type Invoice,
} from '@/hooks/useInvoices';
import { useTransactions, type Transaction } from '@/hooks/useTransactions';
import { useNotes } from '@/hooks/useNotes';
import { useFiles } from '@/hooks/useFiles';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Select } from '@/components/ui/select';
import { DetailModal, type DetailModalTab } from '@/components/shared/DetailModal';
import { ActionButtons } from '@/components/shared/ActionButtons';
import { ClientFormDialog } from '@/components/clients/ClientFormDialog';
import { InvoiceFormDialog } from '@/components/finance/InvoiceFormDialog';
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

function transactionTypeBadge(type: Transaction['type']): { variant: BadgeVariant; label: string } {
  switch (type) {
    case 'income':
      return { variant: 'default', label: 'Příjem' };
    case 'expense':
      return { variant: 'destructive', label: 'Výdaj' };
    default:
      return { variant: 'outline', label: type };
  }
}

const INVOICE_STATUS_OPTIONS: { value: Invoice['status']; label: string }[] = [
  { value: 'draft', label: 'Koncept' },
  { value: 'sent', label: 'Odesláno' },
  { value: 'paid', label: 'Zaplaceno' },
  { value: 'overdue', label: 'Po splatnosti' },
  { value: 'cancelled', label: 'Zrušeno' },
];

/**
 * Řádek faktury v detailu klienta s akcemi:
 * rychlá změna statusu, úprava, PDF, smazání.
 */
function ClientInvoiceRow({ invoice }: { invoice: Invoice }) {
  const [formOpen, setFormOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);

  const updateMutation = useUpdateInvoice(invoice.id);
  const deleteMutation = useDeleteInvoice();
  const pdfMutation = useDownloadInvoicePdf();

  const badge = invoiceStatusBadge(invoice.status);
  const remaining = invoice.amount_cents - invoice.paid_cents;

  const handleStatusChange = async (status: Invoice['status']) => {
    if (status === invoice.status) return;
    try {
      await updateMutation.mutateAsync({ status });
    } catch {
      // chyba — zůstane původní status
    }
  };

  const handleDelete = async () => {
    try {
      await deleteMutation.mutateAsync(invoice.id);
      setDeleteOpen(false);
    } catch {
      // chyba smazání
    }
  };

  return (
    <>
      <li className="py-3">
        <div className="flex items-center justify-between gap-2">
          <span className="font-medium">{invoice.invoice_number}</span>
          <div className="flex items-center gap-2">
            <Badge variant={badge.variant}>{badge.label}</Badge>
            <Select
              value={invoice.status}
              onChange={(e) => handleStatusChange(e.target.value as Invoice['status'])}
              className="h-7 w-36 text-xs"
              aria-label="Změnit status faktury"
            >
              {INVOICE_STATUS_OPTIONS.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </Select>
          </div>
        </div>
        <div className="mt-1 flex items-center justify-between gap-2 text-xs text-muted-foreground">
          <div className="flex items-center gap-4">
            <span>Částka: {fmtMoney(invoice.amount_cents)}</span>
            <span>Splatnost: {fmtDate(invoice.due_date)}</span>
            {remaining > 0 && <span>Zbývá: {fmtMoney(remaining)}</span>}
          </div>
          <ActionButtons
            actions={[
              { icon: 'edit', label: 'Upravit fakturu', onClick: () => setFormOpen(true) },
              { icon: 'pdf', label: 'Stáhnout PDF', onClick: () => pdfMutation.mutateAsync(invoice) },
              { icon: 'delete', label: 'Smazat fakturu', onClick: () => setDeleteOpen(true), destructive: true },
            ]}
          />
        </div>
      </li>
      <InvoiceFormDialog open={formOpen} onClose={() => setFormOpen(false)} invoice={invoice} />
      <ConfirmDeleteDialog
        open={deleteOpen}
        onClose={() => setDeleteOpen(false)}
        onConfirm={handleDelete}
        title="Smazat fakturu"
        description={`Opravdu chcete smazat fakturu „${invoice.invoice_number}"? Včetně všech plateb. Tuto akci NELZE vrátit zpět.`}
        entityName={invoice.invoice_number}
      />
    </>
  );
}

function projectStatusBadge(status: string): { variant: BadgeVariant; label: string } {
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

function DetailRow({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5 py-2 border-b border-border last:border-0">
      <dt className="text-xs font-medium text-muted-foreground">{label}</dt>
      <dd className="text-sm whitespace-pre-wrap">{value || '—'}</dd>
    </div>
  );
}

function LoadingState({ text }: { text: string }) {
  return (
    <div className="py-10 text-center text-sm text-muted-foreground">{text}</div>
  );
}

function ErrorState({ message }: { message: string }) {
  return (
    <div className="py-10 text-center text-sm text-destructive">{message}</div>
  );
}

function EmptyState({ text }: { text: string }) {
  return (
    <div className="py-10 text-center text-sm text-muted-foreground">{text}</div>
  );
}

interface ClientDetailModalProps {
  open: boolean;
  onClose: () => void;
  clientId: number | null;
}

export function ClientDetailModal({ open, onClose, clientId }: ClientDetailModalProps) {
  const qc = useQueryClient();
  const [formOpen, setFormOpen] = useState(false);
  const [invoiceFormOpen, setInvoiceFormOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);

  const { data: client, isLoading, isError, error } = useQuery<Client>({
    queryKey: ['client', clientId],
    queryFn: () => api.get<Client>(`/clients/${clientId}`),
    enabled: open && !!clientId,
  });

  const isNotFound = isError && error instanceof ApiError && error.status === 404;

  const { data: projectsData, isLoading: projectsLoading, isError: projectsError } = useProjects({
    client_id: clientId ?? undefined,
    per_page: 200,
    enabled: open && !!clientId,
  });
  const { data: invoicesData, isLoading: invoicesLoading, isError: invoicesError } = useInvoices({
    client_id: clientId ?? undefined,
    per_page: 200,
    enabled: open && !!clientId,
  });
  const { data: transactionsData, isLoading: transactionsLoading, isError: transactionsError } = useTransactions({
    client_id: clientId ?? undefined,
    per_page: 200,
    enabled: open && !!clientId,
  });
  const { data: notesData, isLoading: notesLoading, isError: notesError } = useNotes({
    entity_type: 'client',
    entity_id: clientId ?? undefined,
    per_page: 200,
    enabled: open && !!clientId,
  });
  const { data: filesData, isLoading: filesLoading, isError: filesError } = useFiles({
    entity_type: 'client',
    entity_id: clientId ?? undefined,
    per_page: 200,
    enabled: open && !!clientId,
  });

  const handleDelete = async () => {
    if (!clientId) return;
    try {
      await api.delete(`/clients/${clientId}`);
      qc.invalidateQueries({ queryKey: ['clients'] });
      onClose();
    } catch {
      // chyba se zobrazí v dialogu
    }
  };

  // Loading stav
  if (open && isLoading) {
    return (
      <DetailModal
        open={open}
        onClose={onClose}
        title="Načítání..."
        tabs={[{ id: 'detail', label: 'Detail', content: <LoadingState text="Načítání klienta..." /> }]}
      />
    );
  }

  // 404 stav
  if (open && isNotFound) {
    return (
      <DetailModal
        open={open}
        onClose={onClose}
        title="Klient nenalezen"
        tabs={[{ id: 'detail', label: 'Detail', content: <EmptyState text="Klient neexistuje nebo byl smazán." /> }]}
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

  if (!open || !client) return null;

  const projects = projectsData?.data ?? [];
  const invoices = invoicesData?.data ?? [];
  const transactions = transactionsData?.data ?? [];
  const notes = notesData?.data ?? [];
  const files = filesData?.data ?? [];

  const tabs: DetailModalTab[] = [
    {
      id: 'detail',
      label: 'Detail',
      content: (
        <Card>
          <CardContent>
            <dl>
              <DetailRow label="Typ" value={
                client.type === 'individual' ? 'Osoba'
                : client.type === 'company' ? 'Firma'
                : client.type === 'nonprofit' ? 'Neziskový sektor'
                : client.type === 'government' ? 'Státní správa'
                : client.type
              } />
              {client.type === 'individual' ? (
                <DetailRow
                  label="Jméno"
                  value={`${client.first_name ?? ''} ${client.last_name ?? ''}`.trim()}
                />
              ) : (
                <>
                  <DetailRow
                    label={client.type === 'government' ? 'Název úřadu' : 'Název organizace'}
                    value={client.company_name}
                  />
                  <DetailRow label="Zástupce" value={client.contact_name} />
                  <DetailRow label="E-mail zástupce" value={<EmailLink email={client.contact_email} />} />
                  <DetailRow label="Telefon zástupce" value={<PhoneLink phone={client.contact_phone} />} />
                </>
              )}
              <DetailRow label="IČO" value={client.ico} />
              <DetailRow label="DIČ" value={client.dic} />
              <DetailRow label="E-mail" value={<EmailLink email={client.email} />} />
              <DetailRow label="Telefon" value={<PhoneLink phone={client.phone} />} />
              <DetailRow label="Adresa" value={client.address} />
              <DetailRow label="Bankovní účet" value={client.bank_account} />
              <DetailRow label="Poznámka" value={client.note} />
              <DetailRow label="Vytvořeno" value={fmtDateTime(client.created_at)} />
            </dl>
          </CardContent>
        </Card>
      ),
    },
    {
      id: 'projects',
      label: `Projekty (${projects.length})`,
      content: projectsLoading ? (
        <LoadingState text="Načítání projektů..." />
      ) : projectsError ? (
        <ErrorState message="Chyba při načítání projektů." />
      ) : projects.length === 0 ? (
        <EmptyState text="Žádné projekty." />
      ) : (
        <ul className="divide-y divide-border">
          {projects.map((p) => {
            const badge = projectStatusBadge(p.status);
            return (
              <li key={p.id} className="py-3">
                <div className="flex items-center justify-between gap-2">
                  <span className="font-medium">{p.name}</span>
                  <Badge variant={badge.variant}>{badge.label}</Badge>
                </div>
                <div className="mt-1 flex items-center gap-4 text-xs text-muted-foreground">
                  <span>Rozpočet: {p.budget_cents ? fmtMoney(p.budget_cents) : '—'}</span>
                  <span>Termín: {p.deadline ? fmtDate(p.deadline) : '—'}</span>
                </div>
              </li>
            );
          })}
        </ul>
      ),
    },
    {
      id: 'invoices',
      label: `Faktury (${invoices.length})`,
      content: invoicesLoading ? (
        <LoadingState text="Načítání faktur..." />
      ) : invoicesError ? (
        <ErrorState message="Chyba při načítání faktur." />
      ) : invoices.length === 0 ? (
        <EmptyState text="Žádné faktury." />
      ) : (
        <ul className="divide-y divide-border">
          {invoices.map((inv) => (
            <ClientInvoiceRow key={inv.id} invoice={inv} />
          ))}
        </ul>
      ),
    },
    {
      id: 'transactions',
      label: `Transakce (${transactions.length})`,
      content: transactionsLoading ? (
        <LoadingState text="Načítání transakcí..." />
      ) : transactionsError ? (
        <ErrorState message="Chyba při načítání transakcí." />
      ) : transactions.length === 0 ? (
        <EmptyState text="Žádné transakce." />
      ) : (
        <ul className="divide-y divide-border">
          {transactions.map((t) => {
            const badge = transactionTypeBadge(t.type);
            return (
              <li key={t.id} className="py-3">
                <div className="flex items-center justify-between gap-2">
                  <div className="flex items-center gap-2">
                    <Badge variant={badge.variant}>{badge.label}</Badge>
                    <span className="text-sm">{t.description || '—'}</span>
                  </div>
                  <span className={t.type === 'income' ? 'text-sm font-medium text-emerald-600' : 'text-sm font-medium text-destructive'}>
                    {t.type === 'income' ? '+' : '−'}{fmtMoney(t.amount_cents)}
                  </span>
                </div>
                <div className="mt-1 text-xs text-muted-foreground">
                  {fmtDate(t.transaction_date)}
                </div>
              </li>
            );
          })}
        </ul>
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
        title={client.full_name || '—'}
        badges={
          <Badge variant="secondary">
            {client.type === 'individual' ? 'Osoba'
              : client.type === 'company' ? 'Firma'
              : client.type === 'nonprofit' ? 'Neziskový'
              : client.type === 'government' ? 'Státní správa'
              : client.type}
          </Badge>
        }
        actions={
          <>
            <Button variant="default" size="sm" onClick={() => setInvoiceFormOpen(true)}>
              <FileText className="h-4 w-4" />
              Vystavit fakturu
            </Button>
            <Button variant="outline" size="sm" onClick={() => setFormOpen(true)}>
              <Pencil className="h-4 w-4" />
              Upravit
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
      <InvoiceFormDialog
        open={invoiceFormOpen}
        onClose={() => setInvoiceFormOpen(false)}
        presetClientId={clientId}
      />
      <ClientFormDialog open={formOpen} onClose={() => setFormOpen(false)} client={client} />
      <ConfirmDeleteDialog
        open={deleteOpen}
        onClose={() => setDeleteOpen(false)}
        onConfirm={handleDelete}
        title="Smazat klienta"
        description={`Opravdu chcete smazat klienta „${client.full_name ?? ''}"? Všechna jeho data (projekty, faktury, transakce, poznámky, soubory) budou trvale odstraněna. Tuto akci NELZE vrátit zpět.`}
        entityName={client.full_name ?? ''}
      />
    </>
  );
}
