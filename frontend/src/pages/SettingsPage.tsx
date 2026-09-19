import { useState, useEffect } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useAuth } from '@/hooks/useAuth';
import { useTheme } from '@/hooks/useTheme';
import { useSidebar } from '@/hooks/useSidebar';
import {
  useSettings,
  useUpdateSettings,
  useUpdatePreferences,
} from '@/hooks/useSettings';
import { ApiError } from '@/lib/api';
import { cn } from '@/lib/utils';
import { ProfileTab } from '@/components/settings/ProfileTab';
import { CompanyTab } from '@/components/settings/CompanyTab';
import { NotificationsTab } from '@/components/settings/NotificationsTab';

type Tab = 'profile' | 'company' | 'app' | 'notifications' | 'appearance';

const TABS: { value: Tab; label: string }[] = [
  { value: 'profile', label: 'Profil' },
  { value: 'company', label: 'Firma' },
  { value: 'app', label: 'Aplikace' },
  { value: 'notifications', label: 'Notifikace' },
  { value: 'appearance', label: 'Vzhled' },
];

export default function SettingsPage() {
  const [tab, setTab] = useState<Tab>('profile');

  return (
    <div className="space-y-6 p-6">
      <h1 className="text-2xl font-semibold">Nastavení</h1>

      {/* Tab navigace */}
      <div className="flex flex-wrap gap-2 border-b">
        {TABS.map((t) => (
          <button
            key={t.value}
            onClick={() => setTab(t.value)}
            className={cn(
              'border-b-2 px-4 py-2 text-sm font-medium transition-colors',
              tab === t.value
                ? 'border-primary text-primary'
                : 'border-transparent text-muted-foreground hover:text-foreground'
            )}
          >
            {t.label}
          </button>
        ))}
      </div>

      {tab === 'profile' && <ProfileTab />}
      {tab === 'company' && <CompanyTab />}
      {tab === 'app' && <AppTab />}
      {tab === 'notifications' && <NotificationsTab />}
      {tab === 'appearance' && <AppearanceTab />}
    </div>
  );
}

/* ============================= Aplikace ============================= */

const APP_FIELDS: { key: string; label: string; type?: string; placeholder?: string; isSelect?: boolean; options?: { value: string; label: string }[] }[] = [
  { key: 'default_vat_rate', label: 'Výchozí sazba DPH (%)', type: 'number', placeholder: '21' },
  { key: 'default_due_days', label: 'Výchozí splatnost (dní)', type: 'number', placeholder: '14' },
  { key: 'invoice_number_format', label: 'Formát číslování faktur', placeholder: 'INV-{YYYY}-{NNNN}' },
  { key: 'currency', label: 'Měna', placeholder: 'CZK' },
  { key: 'currency_decimals', label: 'Desetinná místa', type: 'number', placeholder: '0' },
  { key: 'timezone', label: 'Časová zóna', placeholder: 'Europe/Prague' },
  { key: 'first_day_of_week', label: 'První den týdne', isSelect: true, options: [
    { value: '1', label: 'Pondělí' },
    { value: '7', label: 'Neděle' },
  ] },
  { key: 'fiscal_year_start', label: 'Začátek fiskálního roku', placeholder: '01-01' },
];

function AppTab() {
  const { data, isLoading } = useSettings();
  const updateMutation = useUpdateSettings();

  const [form, setForm] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    if (data) setForm({ ...data });
  }, [data]);

  const update = (key: string, value: string) => {
    setForm((prev) => ({ ...prev, [key]: value }));
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
          Načítání nastavení...
        </CardContent>
      </Card>
    );
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Nastavení aplikace</CardTitle>
      </CardHeader>
      <CardContent>
        <form onSubmit={handleSubmit} className="space-y-4">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            {APP_FIELDS.map((field) => (
              <div key={field.key} className="space-y-1.5">
                <Label htmlFor={`setting-${field.key}`}>{field.label}</Label>
                {field.isSelect ? (
                  <Select
                    id={`setting-${field.key}`}
                    value={form[field.key] ?? ''}
                    onChange={(e) => update(field.key, e.target.value)}
                  >
                    {field.options?.map((opt) => (
                      <option key={opt.value} value={opt.value}>
                        {opt.label}
                      </option>
                    ))}
                  </Select>
                ) : (
                  <Input
                    id={`setting-${field.key}`}
                    type={field.type ?? 'text'}
                    value={form[field.key] ?? ''}
                    onChange={(e) => update(field.key, e.target.value)}
                    placeholder={field.placeholder}
                  />
                )}
              </div>
            ))}
          </div>

          {error && <p className="text-sm text-destructive">{error}</p>}
          {saved && <p className="text-sm text-green-600">Nastavení bylo uloženo.</p>}

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

/* ============================== Vzhled ============================== */

function AppearanceTab() {
  const { user } = useAuth();
  const { theme, setTheme } = useTheme();
  const { collapsed, toggle } = useSidebar();
  const updatePreferences = useUpdatePreferences();

  const [perPage, setPerPage] = useState(50);

  useEffect(() => {
    if (user) setPerPage(user.per_page);
  }, [user]);

  const handleThemeChange = (value: 'light' | 'dark') => {
    setTheme(value);
    if (user) {
      updatePreferences.mutate({ theme: value });
    }
  };

  const handlePerPageChange = (value: number) => {
    setPerPage(value);
    if (user) {
      updatePreferences.mutate({ per_page: value });
    }
  };

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader>
          <CardTitle>Vzhled</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="space-y-1.5">
            <Label>Téma</Label>
            <div className="flex gap-2">
              <Button
                type="button"
                variant={theme === 'light' ? 'default' : 'outline'}
                onClick={() => handleThemeChange('light')}
                className="flex-1 max-w-xs"
              >
                Světlý
              </Button>
              <Button
                type="button"
                variant={theme === 'dark' ? 'default' : 'outline'}
                onClick={() => handleThemeChange('dark')}
                className="flex-1 max-w-xs"
              >
                Tmavý
              </Button>
            </div>
            <p className="text-xs text-muted-foreground">Změny se aplikují okamžitě.</p>
          </div>

          <div className="space-y-1.5">
            <Label>Sidebar</Label>
            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={!collapsed}
                onChange={toggle}
                className="h-4 w-4 rounded border-input"
              />
              Rozbalený sidebar
            </label>
            <p className="text-xs text-muted-foreground">Změny se aplikují okamžitě.</p>
          </div>

          <div className="space-y-1.5">
            <Label>Počet položek na stránku</Label>
            <Select
              value={String(perPage)}
              onChange={(e) => handlePerPageChange(Number(e.target.value))}
              className="max-w-xs"
            >
              <option value="10">10</option>
              <option value="20">20</option>
              <option value="50">50</option>
              <option value="100">100</option>
            </Select>
          </div>
        </CardContent>
      </Card>

      {/* Živý preview */}
      <Card>
        <CardHeader>
          <CardTitle>Náhled</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="rounded-md border p-4">
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <span className="text-sm font-medium">Ukázkový nadpis</span>
                <span className="rounded-md bg-primary px-2 py-0.5 text-xs text-primary-foreground">Badge</span>
              </div>
              <div className="space-y-1">
                <div className="h-2 w-full rounded bg-muted" />
                <div className="h-2 w-3/4 rounded bg-muted" />
              </div>
              <div className="flex gap-2">
                <Button size="sm">Tlačítko</Button>
                <Button size="sm" variant="outline">Sekundární</Button>
              </div>
            </div>
          </div>
          <p className="mt-2 text-xs text-muted-foreground">
            Téma: {theme === 'dark' ? 'Tmavý' : 'Světlý'} · Sidebar: {collapsed ? 'sbalený' : 'rozbalený'} · Položek na stránku: {perPage}
          </p>
        </CardContent>
      </Card>
    </div>
  );
}
