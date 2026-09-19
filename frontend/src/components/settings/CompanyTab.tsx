import { useState, useEffect } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useCompanyProfile, useUpdateCompanyProfile, type CompanyProfile } from '@/hooks/useSettings';
import { ApiError } from '@/lib/api';
import { cn } from '@/lib/utils';

const emptyCompany: CompanyProfile = {
  type: 'individual',
  first_name: '',
  last_name: '',
  company_name: '',
  ico: '',
  dic: '',
  email: '',
  phone: '',
  address: '',
  bank_account: '',
  iban: '',
  swift: '',
};

export function CompanyTab() {
  const { data, isLoading } = useCompanyProfile();
  const updateMutation = useUpdateCompanyProfile();

  const [form, setForm] = useState<CompanyProfile>(emptyCompany);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    if (data) setForm(data);
  }, [data]);

  const update = (field: keyof CompanyProfile, value: string) => {
    setForm((prev) => ({ ...prev, [field]: value }));
    setSaved(false);
  };

  const updateType = (newType: 'individual' | 'company' | 'nonprofit' | 'government') => {
    setForm((prev) => {
      const next = { ...prev, type: newType };
      if (newType === 'individual') {
        next.company_name = '';
        next.ico = '';
        next.dic = '';
      } else {
        next.first_name = '';
        next.last_name = '';
      }
      return next;
    });
    setSaved(false);
  };

  const handleSubmit = async (ev: React.FormEvent) => {
    ev.preventDefault();
    setError(null);
    setSaved(false);
    try {
      await updateMutation.mutateAsync(form);
      setSaved(true);
    } catch (err) {
      if (err instanceof ApiError) {
        setError(err.body.error || 'Chyba při ukládání');
      } else if (err instanceof Error) {
        setError(err.message);
      } else {
        setError('Neznámá chyba');
      }
    }
  };

  if (isLoading) {
    return (
      <Card>
        <CardContent className="py-10 text-center text-muted-foreground">
          Načítání profilu firmy...
        </CardContent>
      </Card>
    );
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Profil firmy (prodávající)</CardTitle>
      </CardHeader>
      <CardContent>
        <form onSubmit={handleSubmit} className="space-y-4">
          {/* Přepínač typu */}
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
            {([
              { value: 'individual', label: 'Osoba' },
              { value: 'company', label: 'Firma' },
              { value: 'nonprofit', label: 'Neziskový' },
              { value: 'government', label: 'Státní správa' },
            ] as { value: 'individual' | 'company' | 'nonprofit' | 'government'; label: string }[]).map((opt) => (
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
                <Label htmlFor="cp-first-name">Jméno</Label>
                <Input
                  id="cp-first-name"
                  value={form.first_name ?? ''}
                  onChange={(e) => update('first_name', e.target.value)}
                />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="cp-last-name">Příjmení</Label>
                <Input
                  id="cp-last-name"
                  value={form.last_name ?? ''}
                  onChange={(e) => update('last_name', e.target.value)}
                />
              </div>
            </div>
          ) : (
            <div className="space-y-4">
              <div className="space-y-1.5">
                <Label htmlFor="cp-company-name">
                  {form.type === 'government' ? 'Název úřadu' : 'Název firmy'}
                </Label>
                <Input
                  id="cp-company-name"
                  value={form.company_name ?? ''}
                  onChange={(e) => update('company_name', e.target.value)}
                />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-1.5">
                  <Label htmlFor="cp-ico">IČO</Label>
                  <Input
                    id="cp-ico"
                    value={form.ico ?? ''}
                    onChange={(e) => update('ico', e.target.value)}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="cp-dic">DIČ</Label>
                  <Input
                    id="cp-dic"
                    value={form.dic ?? ''}
                    onChange={(e) => update('dic', e.target.value)}
                  />
                </div>
              </div>
            </div>
          )}

          {/* Společné */}
          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-1.5">
              <Label htmlFor="cp-email">E-mail</Label>
              <Input
                id="cp-email"
                type="email"
                value={form.email ?? ''}
                onChange={(e) => update('email', e.target.value)}
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="cp-phone">Telefon</Label>
              <Input
                id="cp-phone"
                value={form.phone ?? ''}
                onChange={(e) => update('phone', e.target.value)}
              />
            </div>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="cp-address">Adresa</Label>
            <textarea
              id="cp-address"
              value={form.address ?? ''}
              onChange={(e) => update('address', e.target.value)}
              rows={2}
              className={cn(
                'flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50'
              )}
            />
          </div>

          <div className="grid grid-cols-3 gap-4">
            <div className="space-y-1.5">
              <Label htmlFor="cp-bank-account">Bankovní účet</Label>
              <Input
                id="cp-bank-account"
                value={form.bank_account ?? ''}
                onChange={(e) => update('bank_account', e.target.value)}
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="cp-iban">IBAN</Label>
              <Input
                id="cp-iban"
                value={form.iban ?? ''}
                onChange={(e) => update('iban', e.target.value)}
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="cp-swift">SWIFT</Label>
              <Input
                id="cp-swift"
                value={form.swift ?? ''}
                onChange={(e) => update('swift', e.target.value)}
              />
            </div>
          </div>

          {error && <p className="text-sm text-destructive">{error}</p>}
          {saved && <p className="text-sm text-green-600">Profil firmy byl uložen.</p>}

          <div className="flex justify-end pt-2">
            <Button type="submit" disabled={updateMutation.isPending}>
              {updateMutation.isPending ? 'Ukládám...' : 'Uložit'}
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  );
}
