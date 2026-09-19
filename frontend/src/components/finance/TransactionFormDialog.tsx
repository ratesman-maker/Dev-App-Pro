import { useState, useEffect } from 'react';
import { Dialog } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import { useClients } from '@/hooks/useClients';
import { useProjects } from '@/hooks/useProjects';
import { useInvoices } from '@/hooks/useInvoices';
import {
  useCreateTransaction,
  useUpdateTransaction,
  type Transaction,
  type TransactionInput,
} from '@/hooks/useTransactions';
import { ApiError } from '@/lib/api';

interface TransactionFormDialogProps {
  open: boolean;
  onClose: () => void;
  transaction?: Transaction | null;
  presetProjectId?: number;
  presetClientId?: number;
}

interface TransactionFormState {
  type: 'income' | 'expense';
  amount_cents: string;
  category: Transaction['category'];
  description: string;
  transaction_date: string;
  project_id: string;
  client_id: string;
  invoice_id: string;
}

const emptyForm: TransactionFormState = {
  type: 'expense',
  amount_cents: '',
  category: 'office',
  description: '',
  transaction_date: '',
  project_id: '',
  client_id: '',
  invoice_id: '',
};

const EXPENSE_CATEGORIES: { value: Transaction['category']; label: string }[] = [
  { value: 'office', label: 'Kancelář' },
  { value: 'software', label: 'Software' },
  { value: 'travel', label: 'Cestovné' },
  { value: 'marketing', label: 'Marketing' },
  { value: 'hardware', label: 'Hardware' },
  { value: 'services', label: 'Služby' },
  { value: 'other', label: 'Jiné' },
];

const INCOME_CATEGORIES: { value: Transaction['category']; label: string }[] = [
  { value: 'income_project', label: 'Příjem z projektu' },
  { value: 'income_consulting', label: 'Konzultace' },
  { value: 'other', label: 'Jiné' },
];

function todayStr(): string {
  return new Date().toISOString().slice(0, 10);
}

function categoryForType(type: 'income' | 'expense'): Transaction['category'] {
  return type === 'income' ? 'income_project' : 'office';
}

export function TransactionFormDialog({ open, onClose, transaction, presetProjectId, presetClientId }: TransactionFormDialogProps) {
  const isEdit = !!transaction;
  const isPayment = !!presetProjectId || !!presetClientId;
  const createMutation = useCreateTransaction();
  const updateMutation = useUpdateTransaction(transaction?.id ?? 0);
  const mutation = isEdit ? updateMutation : createMutation;

  const { data: clientsData } = useClients({ per_page: 200 });
  const { data: projectsData } = useProjects({ per_page: 200 });
  const { data: invoicesData } = useInvoices({ per_page: 200 });

  const [form, setForm] = useState<TransactionFormState>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (open) {
      if (transaction) {
        setForm({
          type: transaction.type,
          amount_cents: transaction.amount_cents ? String(transaction.amount_cents / 100) : '',
          category: transaction.category,
          description: transaction.description ?? '',
          transaction_date: transaction.transaction_date.slice(0, 10),
          project_id: transaction.project_id ? String(transaction.project_id) : '',
          client_id: transaction.client_id ? String(transaction.client_id) : '',
          invoice_id: transaction.invoice_id ? String(transaction.invoice_id) : '',
        });
      } else if (isPayment) {
        setForm({
          ...emptyForm,
          type: 'income',
          category: 'income_project',
          transaction_date: todayStr(),
          project_id: presetProjectId ? String(presetProjectId) : '',
          client_id: presetClientId ? String(presetClientId) : '',
        });
      } else {
        setForm({ ...emptyForm, transaction_date: todayStr() });
      }
      setErrors({});
    }
  }, [open, transaction, presetProjectId, presetClientId]);

  const update = <K extends keyof TransactionFormState>(field: K, value: TransactionFormState[K]) => {
    setForm((prev) => ({ ...prev, [field]: value }));
  };

  const handleTypeChange = (type: 'income' | 'expense') => {
    setForm((prev) => ({ ...prev, type, category: categoryForType(type) }));
  };

  const validate = (): boolean => {
    const e: Record<string, string> = {};
    if (!form.type) e.type = 'Typ je povinný';
    if (!form.transaction_date) e.transaction_date = 'Datum transakce je povinné';
    const amount = Number(form.amount_cents);
    if (isNaN(amount) || amount <= 0) e.amount_cents = 'Částka musí být kladná';
    setErrors(e);
    return Object.keys(e).length === 0;
  };

  const handleSubmit = async (ev: React.FormEvent) => {
    ev.preventDefault();
    if (!validate()) return;
    try {
      const payload: TransactionInput = {
        type: form.type,
        amount_cents: Math.round(Number(form.amount_cents) * 100),
        category: form.category,
        description: form.description.trim() || null,
        transaction_date: form.transaction_date,
        project_id: form.project_id ? Number(form.project_id) : null,
        client_id: form.client_id ? Number(form.client_id) : null,
        invoice_id: form.invoice_id ? Number(form.invoice_id) : null,
      };
      await mutation.mutateAsync(payload);
      onClose();
    } catch (err) {
      if (err instanceof ApiError && err.body.fields) {
        setErrors(err.body.fields);
      } else if (err instanceof Error) {
        setErrors({ form: err.message });
      } else {
        setErrors({ form: 'Neznámá chyba' });
      }
    }
  };

  const categoryOptions = form.type === 'income' ? INCOME_CATEGORIES : EXPENSE_CATEGORIES;

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title={isEdit ? 'Upravit transakci' : isPayment ? 'Zaznamenat platbu' : 'Nová transakce'}
      description={isEdit ? 'Upravte údaje transakce.' : isPayment ? 'Zaznamenejte platbu klienta bez faktury.' : 'Vytvořte novou transakci.'}
      className="max-w-xl"
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-1.5">
            <Label htmlFor="type">Typ *</Label>
            <Select
              id="type"
              value={form.type}
              onChange={(e) => handleTypeChange(e.target.value as 'income' | 'expense')}
            >
              <option value="expense">Výdaj</option>
              <option value="income">Příjem</option>
            </Select>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="amount_cents">Částka (Kč) *</Label>
            <Input
              id="amount_cents"
              type="number"
              min={0}
              step="0.01"
              value={form.amount_cents}
              onChange={(e) => update('amount_cents', e.target.value)}
              placeholder="0"
            />
            {errors.amount_cents && <p className="text-xs text-destructive">{errors.amount_cents}</p>}
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-1.5">
            <Label htmlFor="category">Kategorie</Label>
            <Select
              id="category"
              value={form.category}
              onChange={(e) => update('category', e.target.value as Transaction['category'])}
            >
              {categoryOptions.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </Select>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="transaction_date">Datum transakce *</Label>
            <Input
              id="transaction_date"
              type="date"
              value={form.transaction_date}
              onChange={(e) => update('transaction_date', e.target.value)}
            />
            {errors.transaction_date && <p className="text-xs text-destructive">{errors.transaction_date}</p>}
          </div>
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="description">Popis</Label>
          <Input
            id="description"
            value={form.description}
            onChange={(e) => update('description', e.target.value)}
          />
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-1.5">
            <Label htmlFor="project_id">Projekt</Label>
            <Select
              id="project_id"
              value={form.project_id}
              onChange={(e) => update('project_id', e.target.value)}
            >
              <option value="">— bez projektu —</option>
              {projectsData?.data.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </Select>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="client_id">Klient</Label>
            <Select
              id="client_id"
              value={form.client_id}
              onChange={(e) => update('client_id', e.target.value)}
            >
              <option value="">— bez klienta —</option>
              {clientsData?.data.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.full_name}
                </option>
              ))}
            </Select>
          </div>
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="invoice_id">Faktura</Label>
          <Select
            id="invoice_id"
            value={form.invoice_id}
            onChange={(e) => update('invoice_id', e.target.value)}
          >
            <option value="">— bez faktury —</option>
            {invoicesData?.data.map((inv) => (
              <option key={inv.id} value={inv.id}>
                {inv.invoice_number} ({inv.status})
              </option>
            ))}
          </Select>
        </div>

        {errors.form && <p className="text-sm text-destructive">{errors.form}</p>}

        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="outline" onClick={onClose}>Zrušit</Button>
          <Button type="submit" disabled={mutation.isPending}>
            {mutation.isPending ? 'Ukládám...' : isEdit ? 'Uložit změny' : 'Vytvořit'}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
