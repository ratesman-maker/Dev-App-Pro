import { useState, useEffect } from 'react';
import { Dialog } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import { Plus, Trash2 } from 'lucide-react';
import { useClients } from '@/hooks/useClients';
import { useProjects } from '@/hooks/useProjects';
import {
  useCreateInvoice,
  useUpdateInvoice,
  useDownloadInvoicePdf,
  type Invoice,
  type InvoiceInput,
  type InvoiceItemInput,
} from '@/hooks/useInvoices';
import { ApiError } from '@/lib/api';
import { cn } from '@/lib/utils';

interface InvoiceFormDialogProps {
  open: boolean;
  onClose: () => void;
  invoice?: Invoice | null;
  presetClientId?: number | null;
}

interface ItemRow {
  description: string;
  quantity: string;
  unit: string;
  unit_price: string;
}

interface InvoiceFormState {
  client_id: string;
  project_id: string;
  invoice_number: string;
  vat_rate_percent: string;
  issue_date: string;
  due_date: string;
  taxable_date: string;
  variable_symbol: string;
  constant_symbol: string;
  iban: string;
  note: string;
  status: Invoice['status'];
  items: ItemRow[];
}

const emptyItem: ItemRow = { description: '', quantity: '1', unit: 'ks', unit_price: '' };

const emptyForm: InvoiceFormState = {
  client_id: '',
  project_id: '',
  invoice_number: '',
  vat_rate_percent: '21',
  issue_date: '',
  due_date: '',
  taxable_date: '',
  variable_symbol: '',
  constant_symbol: '',
  iban: '',
  note: '',
  status: 'draft',
  items: [{ ...emptyItem }],
};

const STATUS_OPTIONS: { value: Invoice['status']; label: string }[] = [
  { value: 'draft', label: 'Koncept' },
  { value: 'sent', label: 'Odesláno' },
  { value: 'paid', label: 'Zaplaceno' },
  { value: 'overdue', label: 'Po splatnosti' },
  { value: 'cancelled', label: 'Zrušeno' },
];

const VAT_OPTIONS = [
  { value: '0', label: '0 %' },
  { value: '15', label: '15 %' },
  { value: '21', label: '21 %' },
];

function toDateInput(value: string | null): string {
  if (!value) return '';
  return value.slice(0, 10);
}

export function InvoiceFormDialog({ open, onClose, invoice, presetClientId }: InvoiceFormDialogProps) {
  const isEdit = !!invoice;
  const createMutation = useCreateInvoice();
  const updateMutation = useUpdateInvoice(invoice?.id ?? 0);
  const pdfMutation = useDownloadInvoicePdf();
  const mutation = isEdit ? updateMutation : createMutation;

  const { data: clientsData } = useClients({ per_page: 200 });
  const { data: projectsData } = useProjects({ per_page: 200 });

  const [form, setForm] = useState<InvoiceFormState>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (open) {
      if (invoice) {
        setForm({
          client_id: invoice.client_id ? String(invoice.client_id) : '',
          project_id: invoice.project_id ? String(invoice.project_id) : '',
          invoice_number: invoice.invoice_number,
          vat_rate_percent: String(invoice.vat_rate_percent),
          issue_date: toDateInput(invoice.issue_date),
          due_date: toDateInput(invoice.due_date),
          taxable_date: toDateInput(invoice.taxable_date),
          variable_symbol: invoice.variable_symbol ?? '',
          constant_symbol: invoice.constant_symbol ?? '',
          iban: invoice.iban ?? '',
          note: invoice.note ?? '',
          status: invoice.status,
          items: invoice.items?.length
            ? invoice.items.map((it) => ({
                description: it.description,
                quantity: String(it.quantity),
                unit: it.unit ?? '',
                unit_price: String((Number(it.unit_price_cents) || 0) / 100),
              }))
            : [{ ...emptyItem }],
        });
      } else {
        setForm({
          ...emptyForm,
          client_id: presetClientId ? String(presetClientId) : '',
        });
      }
      setErrors({});
    }
  }, [open, invoice, presetClientId]);

  const update = <K extends keyof InvoiceFormState>(field: K, value: InvoiceFormState[K]) => {
    setForm((prev) => ({ ...prev, [field]: value }));
  };

  const updateItem = (index: number, field: keyof ItemRow, value: string) => {
    setForm((prev) => {
      const items = prev.items.map((it, i) => (i === index ? { ...it, [field]: value } : it));
      return { ...prev, items };
    });
  };

  const addItem = () => {
    setForm((prev) => ({ ...prev, items: [...prev.items, { ...emptyItem }] }));
  };

  const removeItem = (index: number) => {
    setForm((prev) => {
      const items = prev.items.filter((_, i) => i !== index);
      return { ...prev, items: items.length ? items : [{ ...emptyItem }] };
    });
  };

  const subtotalFromItems = (items: ItemRow[]): number => {
    let total = 0;
    for (const it of items) {
      const qty = parseFloat(it.quantity);
      const price = parseFloat(it.unit_price);
      if (!isNaN(qty) && !isNaN(price) && qty > 0 && price >= 0) {
        total += Math.round(qty * price * 100);
      }
    }
    return total;
  };

  const validate = (): boolean => {
    const e: Record<string, string> = {};
    // Číslo faktury je nepovinné — prázdné pole = automatické číslování na serveru
    if (!form.issue_date) e.issue_date = 'Datum vystavení je povinné';
    if (!form.due_date) e.due_date = 'Datum splatnosti je povinné';

    const validItems = form.items.filter((it) => it.description.trim() !== '');
    if (validItems.length === 0) {
      e.items = 'Přidejte alespoň jednu položku s popisem';
    } else {
      for (let i = 0; i < form.items.length; i++) {
        const it = form.items[i];
        if (it.description.trim() === '') continue;
        const qty = parseFloat(it.quantity);
        const price = parseFloat(it.unit_price);
        if (isNaN(qty) || qty <= 0) {
          e.items = `Položka ${i + 1}: množství musí být větší než 0`;
          break;
        }
        if (isNaN(price) || price < 0) {
          e.items = `Položka ${i + 1}: cena musí být nezáporná`;
          break;
        }
      }
    }

    setErrors(e);
    return Object.keys(e).length === 0;
  };

  const handleSubmit = async (ev: React.FormEvent) => {
    ev.preventDefault();
    if (!validate()) return;
    try {
      const items: InvoiceItemInput[] = form.items
        .filter((it) => it.description.trim() !== '')
        .map((it) => ({
          description: it.description.trim(),
          quantity: parseFloat(it.quantity) || 1,
          unit: it.unit.trim() || null,
          unit_price_cents: Math.round((parseFloat(it.unit_price) || 0) * 100),
        }));

      const payload: InvoiceInput = {
        invoice_number: form.invoice_number.trim(),
        client_id: form.client_id ? Number(form.client_id) : null,
        project_id: form.project_id ? Number(form.project_id) : null,
        vat_rate_percent: form.vat_rate_percent,
        issue_date: form.issue_date,
        due_date: form.due_date,
        taxable_date: form.taxable_date || form.issue_date,
        variable_symbol: form.variable_symbol.trim() || null,
        constant_symbol: form.constant_symbol.trim() || null,
        iban: form.iban.trim() || null,
        note: form.note.trim() || null,
        status: form.status,
        items,
      };
      const created = await mutation.mutateAsync(payload);
      onClose();
      if (!isEdit && created?.id) {
        // Po vystavení rovnou stáhnout PDF (s názvem klient + číslo faktury)
        try {
          await pdfMutation.mutateAsync(created);
        } catch {
          // PDF se stáhne i z tabulky faktur
        }
      }
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

  const subtotal = subtotalFromItems(form.items);
  const vatRate = Number(form.vat_rate_percent) || 0;
  const vatAmount = Math.round(subtotal * vatRate / 100);
  const total = subtotal + vatAmount;

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title={isEdit ? 'Upravit fakturu' : 'Vystavit fakturu'}
      description={isEdit ? 'Upravte údaje faktury.' : 'Zadejte služby a ceny, vygeneruje se PDF s QR platbou.'}
      className="max-w-3xl"
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="space-y-1.5">
          <Label htmlFor="invoice_number">Číslo faktury *</Label>
          <Input
            id="invoice_number"
            value={form.invoice_number}
            onChange={(e) => update('invoice_number', e.target.value)}
            placeholder="nechte prázdné pro automatické číslování"
          />
          {errors.invoice_number && <p className="text-xs text-destructive">{errors.invoice_number}</p>}
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-1.5">
            <Label htmlFor="client_id">Klient</Label>
            <Select
              id="client_id"
              value={form.client_id}
              onChange={(e) => update('client_id', e.target.value)}
              disabled={!!presetClientId}
            >
              <option value="">— bez klienta —</option>
              {clientsData?.data.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.full_name}
                </option>
              ))}
            </Select>
          </div>
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
        </div>

        {/* Položky faktury */}
        <div className="space-y-2">
          <div className="flex items-center justify-between">
            <Label>Položky faktury *</Label>
            <Button type="button" variant="outline" size="sm" onClick={addItem}>
              <Plus className="h-4 w-4" />
              Přidat položku
            </Button>
          </div>
          {errors.items && <p className="text-xs text-destructive">{errors.items}</p>}

          <div className="space-y-2 rounded-md border border-border p-3">
            <div className="grid grid-cols-12 gap-2 text-xs font-medium text-muted-foreground">
              <div className="col-span-5">Popis</div>
              <div className="col-span-2">Množství</div>
              <div className="col-span-2">MJ</div>
              <div className="col-span-2">Cena/MJ (Kč)</div>
              <div className="col-span-1" />
            </div>
            {form.items.map((item, index) => {
              const qty = parseFloat(item.quantity);
              const price = parseFloat(item.unit_price);
              const rowTotal = !isNaN(qty) && !isNaN(price) ? Math.round(qty * price * 100) : 0;
              return (
                <div key={index} className="grid grid-cols-12 items-center gap-2">
                  <div className="col-span-5">
                    <Input
                      value={item.description}
                      onChange={(e) => updateItem(index, 'description', e.target.value)}
                      placeholder="např. Tvorba webu"
                    />
                  </div>
                  <div className="col-span-2">
                    <Input
                      type="number"
                      min="0"
                      step="0.001"
                      value={item.quantity}
                      onChange={(e) => updateItem(index, 'quantity', e.target.value)}
                    />
                  </div>
                  <div className="col-span-2">
                    <Input
                      value={item.unit}
                      onChange={(e) => updateItem(index, 'unit', e.target.value)}
                      placeholder="ks, hod, měs"
                    />
                  </div>
                  <div className="col-span-2">
                    <Input
                      type="number"
                      min="0"
                      step="0.01"
                      value={item.unit_price}
                      onChange={(e) => updateItem(index, 'unit_price', e.target.value)}
                    />
                  </div>
                  <div className="col-span-1 flex items-center justify-end gap-1">
                    <span className="text-xs tabular-nums text-muted-foreground">{(rowTotal / 100).toLocaleString('cs-CZ', { minimumFractionDigits: 2 })}</span>
                    <button
                      type="button"
                      onClick={() => removeItem(index)}
                      className="text-muted-foreground hover:text-destructive"
                      title="Odebrat položku"
                    >
                      <Trash2 className="h-4 w-4" />
                    </button>
                  </div>
                </div>
              );
            })}
          </div>

          <div className="flex items-center justify-end gap-4 text-sm">
            <span className="text-muted-foreground">
              Bez DPH: <strong>{subtotal.toLocaleString('cs-CZ')} Kč</strong>
            </span>
            <span className="text-muted-foreground">
              DPH {vatRate} %: <strong>{vatAmount.toLocaleString('cs-CZ')} Kč</strong>
            </span>
            <span>
              Celkem: <strong>{total.toLocaleString('cs-CZ')} Kč</strong>
            </span>
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-1.5">
            <Label htmlFor="vat_rate_percent">Sazba DPH</Label>
            <Select
              id="vat_rate_percent"
              value={form.vat_rate_percent}
              onChange={(e) => update('vat_rate_percent', e.target.value)}
            >
              {VAT_OPTIONS.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </Select>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="status">Status</Label>
            <Select
              id="status"
              value={form.status}
              onChange={(e) => update('status', e.target.value as Invoice['status'])}
            >
              {STATUS_OPTIONS.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </Select>
          </div>
        </div>

        <div className="grid grid-cols-3 gap-4">
          <div className="space-y-1.5">
            <Label htmlFor="issue_date">Datum vystavení *</Label>
            <Input
              id="issue_date"
              type="date"
              value={form.issue_date}
              onChange={(e) => update('issue_date', e.target.value)}
            />
            {errors.issue_date && <p className="text-xs text-destructive">{errors.issue_date}</p>}
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="taxable_date">Datum plnění (DZP)</Label>
            <Input
              id="taxable_date"
              type="date"
              value={form.taxable_date}
              onChange={(e) => update('taxable_date', e.target.value)}
            />
            {errors.taxable_date && <p className="text-xs text-destructive">{errors.taxable_date}</p>}
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="due_date">Datum splatnosti *</Label>
            <Input
              id="due_date"
              type="date"
              value={form.due_date}
              onChange={(e) => update('due_date', e.target.value)}
            />
            {errors.due_date && <p className="text-xs text-destructive">{errors.due_date}</p>}
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-1.5">
            <Label htmlFor="variable_symbol">Variabilní symbol</Label>
            <Input
              id="variable_symbol"
              value={form.variable_symbol}
              onChange={(e) => update('variable_symbol', e.target.value)}
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="constant_symbol">Konstantní symbol</Label>
            <Input
              id="constant_symbol"
              value={form.constant_symbol}
              onChange={(e) => update('constant_symbol', e.target.value)}
            />
          </div>
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="iban">IBAN (jiný než firemní)</Label>
          <Input
            id="iban"
            value={form.iban}
            onChange={(e) => update('iban', e.target.value)}
            placeholder="nepovinné - použije se IBAN z profilu firmy"
          />
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="note">Poznámka</Label>
          <textarea
            id="note"
            value={form.note}
            onChange={(e) => update('note', e.target.value)}
            rows={2}
            className={cn(
              'flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50'
            )}
          />
        </div>

        {errors.form && <p className="text-sm text-destructive">{errors.form}</p>}

        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="outline" onClick={onClose}>Zrušit</Button>
          <Button type="submit" disabled={mutation.isPending}>
            {mutation.isPending ? 'Ukládám...' : isEdit ? 'Uložit změny' : 'Vystavit a stáhnout PDF'}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
