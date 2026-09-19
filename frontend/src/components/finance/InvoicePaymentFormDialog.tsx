import { useState, useEffect } from 'react';
import { Dialog } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import { useInvoices } from '@/hooks/useInvoices';
import {
  useCreateInvoicePayment,
  useUpdateInvoicePayment,
  type InvoicePaymentInput,
  type InvoicePaymentUpdateInput,
  type InvoicePayment,
} from '@/hooks/useInvoicePayments';
import { ApiError } from '@/lib/api';
import { cn } from '@/lib/utils';

interface InvoicePaymentFormDialogProps {
  open: boolean;
  onClose: () => void;
  invoiceId?: number | null;
  payment?: InvoicePayment | null;
}

interface PaymentFormState {
  invoice_id: string;
  amount_cents: string;
  payment_date: string;
  method: 'cash' | 'bank_transfer' | 'card' | 'other';
  note: string;
}

const emptyForm: PaymentFormState = {
  invoice_id: '',
  amount_cents: '',
  payment_date: '',
  method: 'bank_transfer',
  note: '',
};

const METHOD_OPTIONS: { value: PaymentFormState['method']; label: string }[] = [
  { value: 'cash', label: 'Hotovost' },
  { value: 'bank_transfer', label: 'Bankovní převod' },
  { value: 'card', label: 'Karta' },
  { value: 'other', label: 'Jiné' },
];

function todayStr(): string {
  return new Date().toISOString().slice(0, 10);
}

export function InvoicePaymentFormDialog({ open, onClose, invoiceId, payment }: InvoicePaymentFormDialogProps) {
  const createMutation = useCreateInvoicePayment();
  const updateMutation = useUpdateInvoicePayment();
  const { data: invoicesData } = useInvoices({ per_page: 200 });

  const [form, setForm] = useState<PaymentFormState>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const isEdit = !!payment;

  useEffect(() => {
    if (open) {
      if (payment) {
        setForm({
          invoice_id: String(payment.invoice_id),
          amount_cents: String(payment.amount_cents / 100),
          payment_date: payment.payment_date,
          method: payment.method,
          note: payment.note ?? '',
        });
      } else {
        setForm({
          ...emptyForm,
          invoice_id: invoiceId ? String(invoiceId) : '',
          payment_date: todayStr(),
        });
      }
      setErrors({});
    }
  }, [open, invoiceId, payment]);

  const update = <K extends keyof PaymentFormState>(field: K, value: PaymentFormState[K]) => {
    setForm((prev) => ({ ...prev, [field]: value }));
  };

  const validate = (): boolean => {
    const e: Record<string, string> = {};
    if (!isEdit && !form.invoice_id) e.invoice_id = 'Faktura je povinná';
    if (!form.payment_date) e.payment_date = 'Datum platby je povinné';
    const amount = Number(form.amount_cents);
    if (isNaN(amount) || amount <= 0) e.amount_cents = 'Částka musí být kladná';
    setErrors(e);
    return Object.keys(e).length === 0;
  };

  const handleSubmit = async (ev: React.FormEvent) => {
    ev.preventDefault();
    if (!validate()) return;
    try {
      if (isEdit && payment) {
        const payload: InvoicePaymentUpdateInput = {
          amount_cents: Math.round(Number(form.amount_cents) * 100),
          payment_date: form.payment_date,
          method: form.method,
          note: form.note.trim() || null,
        };
        await updateMutation.mutateAsync({ id: payment.id, data: payload });
      } else {
        const payload: InvoicePaymentInput = {
          invoice_id: Number(form.invoice_id),
          amount_cents: Math.round(Number(form.amount_cents) * 100),
          payment_date: form.payment_date,
          method: form.method,
          note: form.note.trim() || null,
        };
        await createMutation.mutateAsync(payload);
      }
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

  const isPending = createMutation.isPending || updateMutation.isPending;

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title={isEdit ? 'Úprava platby' : 'Nová platba'}
      description={isEdit ? 'Upravte údaje o platbě.' : 'Zaznamenejte platbu faktury.'}
      className="max-w-lg"
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="space-y-1.5">
          <Label htmlFor="invoice_id">Faktura *</Label>
          <Select
            id="invoice_id"
            value={form.invoice_id}
            onChange={(e) => update('invoice_id', e.target.value)}
            disabled={!!invoiceId || isEdit}
          >
            <option value="">— vyberte fakturu —</option>
            {invoicesData?.data.map((inv) => (
              <option key={inv.id} value={inv.id}>
                {inv.invoice_number}
                {inv.client_name ? ` (${inv.client_name})` : ''}
              </option>
            ))}
          </Select>
          {errors.invoice_id && <p className="text-xs text-destructive">{errors.invoice_id}</p>}
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

        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-1.5">
            <Label htmlFor="payment_date">Datum platby *</Label>
            <Input
              id="payment_date"
              type="date"
              value={form.payment_date}
              onChange={(e) => update('payment_date', e.target.value)}
            />
            {errors.payment_date && <p className="text-xs text-destructive">{errors.payment_date}</p>}
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="method">Metoda</Label>
            <Select
              id="method"
              value={form.method}
              onChange={(e) => update('method', e.target.value as PaymentFormState['method'])}
            >
              {METHOD_OPTIONS.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </Select>
          </div>
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="note">Poznámka</Label>
          <textarea
            id="note"
            value={form.note}
            onChange={(e) => update('note', e.target.value)}
            rows={3}
            className={cn(
              'flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50'
            )}
          />
        </div>

        {errors.form && <p className="text-sm text-destructive">{errors.form}</p>}

        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="outline" onClick={onClose}>Zrušit</Button>
          <Button type="submit" disabled={isPending}>
            {isPending ? 'Ukládám...' : isEdit ? 'Uložit změny' : 'Vytvořit platbu'}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
