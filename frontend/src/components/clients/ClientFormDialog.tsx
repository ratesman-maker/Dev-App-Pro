import { useState, useEffect } from 'react';
import { Dialog } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useCreateClient, useUpdateClient, type Client, type ClientInput, type ClientType } from '@/hooks/useClients';
import { ApiError } from '@/lib/api';
import { cn } from '@/lib/utils';

interface ClientFormDialogProps {
  open: boolean;
  onClose: () => void;
  client?: Client | null;
}

const emptyForm: ClientInput = {
  type: 'individual',
  first_name: '',
  last_name: '',
  company_name: '',
  contact_name: '',
  contact_email: '',
  contact_phone: '',
  ico: '',
  dic: '',
  bank_account: '',
  email: '',
  phone: '',
  address: '',
  note: '',
};

function isValidEmail(email: string): boolean {
  if (!email) return true;
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

function isValidIco(ico: string): boolean {
  if (!ico) return true;
  return /^\d{8}$/.test(ico);
}

export function ClientFormDialog({ open, onClose, client }: ClientFormDialogProps) {
  const isEdit = !!client;
  const createMutation = useCreateClient();
  const updateMutation = useUpdateClient(client?.id ?? 0);
  const mutation = isEdit ? updateMutation : createMutation;

  const [form, setForm] = useState<ClientInput>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (open) {
      if (client) {
        setForm({
          type: client.type,
          first_name: client.first_name ?? '',
          last_name: client.last_name ?? '',
          company_name: client.company_name ?? '',
          contact_name: client.contact_name ?? '',
          contact_email: client.contact_email ?? '',
          contact_phone: client.contact_phone ?? '',
          ico: client.ico ?? '',
          dic: client.dic ?? '',
          bank_account: client.bank_account ?? '',
          email: client.email ?? '',
          phone: client.phone ?? '',
          address: client.address ?? '',
          note: client.note ?? '',
        });
      } else {
        setForm(emptyForm);
      }
      setErrors({});
    }
  }, [open, client]);

  const update = (field: keyof ClientInput, value: string) => {
    setForm((prev) => ({ ...prev, [field]: value }));
  };

  const updateType = (newType: ClientType) => {
    setForm((prev) => {
      const next = { ...prev, type: newType };
      if (newType === 'individual') {
        // Přepnutí na osobu - vyčistit název firmy a zástupce (osoba je sama kontaktem)
        next.company_name = '';
        next.contact_name = '';
        next.contact_email = '';
        next.contact_phone = '';
      } else {
        // Přepnutí na organizaci - vyčistit jméno/příjmení
        next.first_name = '';
        next.last_name = '';
      }
      return next;
    });
    setErrors({});
  };

  const validate = (): boolean => {
    const e: Record<string, string> = {};
    if (form.type === 'individual') {
      if (!form.first_name?.trim()) e.first_name = 'Jméno je povinné';
      if (!form.last_name?.trim()) e.last_name = 'Příjmení je povinné';
    } else {
      if (!form.company_name?.trim()) e.company_name = 'Název organizace je povinný';
    }
    if (form.email && !isValidEmail(form.email)) e.email = 'Neplatný formát e-mailu';
    if (form.ico && !isValidIco(form.ico)) e.ico = 'IČO musí mít 8 číslic';
    setErrors(e);
    return Object.keys(e).length === 0;
  };

  const handleSubmit = async (ev: React.FormEvent) => {
    ev.preventDefault();
    if (!validate()) return;
    try {
      await mutation.mutateAsync(form);
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

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title={isEdit ? 'Upravit klienta' : 'Nový klient'}
      description={isEdit ? 'Upravte údaje klienta.' : 'Vytvořte nového klienta.'}
      className="max-w-xl"
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        {/* Přepínač typu */}
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
          {([
            { value: 'individual', label: 'Osoba' },
            { value: 'company', label: 'Firma' },
            { value: 'nonprofit', label: 'Neziskový' },
            { value: 'government', label: 'Státní správa' },
          ] as { value: ClientType; label: string }[]).map((opt) => (
            <Button
              key={opt.value}
              type="button"
              variant={form.type === opt.value ? 'default' : 'outline'}
              onClick={() => updateType(opt.value)}
            >
              {opt.label}
            </Button>
          ))}
        </div>

        {form.type === 'individual' ? (
          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-1.5">
              <Label htmlFor="first_name">Jméno *</Label>
              <Input
                id="first_name"
                value={form.first_name ?? ''}
                onChange={(e) => update('first_name', e.target.value)}
              />
              {errors.first_name && <p className="text-xs text-destructive">{errors.first_name}</p>}
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="last_name">Příjmení *</Label>
              <Input
                id="last_name"
                value={form.last_name ?? ''}
                onChange={(e) => update('last_name', e.target.value)}
              />
              {errors.last_name && <p className="text-xs text-destructive">{errors.last_name}</p>}
            </div>
          </div>
        ) : (
          <div className="space-y-4">
            <div className="space-y-1.5">
              <Label htmlFor="company_name">
                {form.type === 'nonprofit' ? 'Název organizace *' :
                 form.type === 'government' ? 'Název úřadu *' :
                 'Název firmy *'}
              </Label>
              <Input
                id="company_name"
                value={form.company_name ?? ''}
                onChange={(e) => update('company_name', e.target.value)}
              />
              {errors.company_name && <p className="text-xs text-destructive">{errors.company_name}</p>}
            </div>
          </div>
        )}

        {/* Zástupce — jen pro organizace (jednání probíhá s kontaktní osobou) */}
        {form.type !== 'individual' && (
          <fieldset className="space-y-4 rounded-md border border-border p-3">
            <legend className="px-1 text-xs font-medium text-muted-foreground">
              Kontaktní osoba (zástupce)
            </legend>
            <div className="grid grid-cols-3 gap-4">
              <div className="space-y-1.5">
                <Label htmlFor="contact_name">Jméno zástupce</Label>
                <Input
                  id="contact_name"
                  value={form.contact_name ?? ''}
                  onChange={(e) => update('contact_name', e.target.value)}
                />
                {errors.contact_name && <p className="text-xs text-destructive">{errors.contact_name}</p>}
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="contact_email">E-mail zástupce</Label>
                <Input
                  id="contact_email"
                  type="email"
                  value={form.contact_email ?? ''}
                  onChange={(e) => update('contact_email', e.target.value)}
                />
                {errors.contact_email && <p className="text-xs text-destructive">{errors.contact_email}</p>}
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="contact_phone">Telefon zástupce</Label>
                <Input
                  id="contact_phone"
                  value={form.contact_phone ?? ''}
                  onChange={(e) => update('contact_phone', e.target.value)}
                />
                {errors.contact_phone && <p className="text-xs text-destructive">{errors.contact_phone}</p>}
              </div>
            </div>
          </fieldset>
        )}

        {/* IČO/DIČ/bankovní účet — pro všechny typy (osoby = OSVČ) */}
        <div className="grid grid-cols-3 gap-4">
          <div className="space-y-1.5">
            <Label htmlFor="ico">IČO</Label>
            <Input
              id="ico"
              value={form.ico ?? ''}
              onChange={(e) => update('ico', e.target.value)}
              placeholder="12345678"
            />
            {errors.ico && <p className="text-xs text-destructive">{errors.ico}</p>}
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="dic">DIČ</Label>
            <Input
              id="dic"
              value={form.dic ?? ''}
              onChange={(e) => update('dic', e.target.value)}
            />
            {errors.dic && <p className="text-xs text-destructive">{errors.dic}</p>}
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="bank_account">Bankovní účet</Label>
            <Input
              id="bank_account"
              value={form.bank_account ?? ''}
              onChange={(e) => update('bank_account', e.target.value)}
            />
          </div>
        </div>

        {/* Společné */}
        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-1.5">
            <Label htmlFor="email">E-mail</Label>
            <Input
              id="email"
              type="email"
              value={form.email ?? ''}
              onChange={(e) => update('email', e.target.value)}
            />
            {errors.email && <p className="text-xs text-destructive">{errors.email}</p>}
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="phone">Telefon</Label>
            <Input
              id="phone"
              value={form.phone ?? ''}
              onChange={(e) => update('phone', e.target.value)}
            />
          </div>
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="address">Adresa</Label>
          <textarea
            id="address"
            value={form.address ?? ''}
            onChange={(e) => update('address', e.target.value)}
            rows={2}
            className={cn(
              'flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50'
            )}
          />
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="note">Poznámka</Label>
          <textarea
            id="note"
            value={form.note ?? ''}
            onChange={(e) => update('note', e.target.value)}
            rows={3}
            className={cn(
              'flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50'
            )}
          />
        </div>

        {errors.form && (
          <p className="text-sm text-destructive">{errors.form}</p>
        )}

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
