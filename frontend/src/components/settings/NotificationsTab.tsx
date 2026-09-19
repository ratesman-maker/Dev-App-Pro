import { useState, useEffect } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useSettings, useUpdateSettings } from '@/hooks/useSettings';
import { ApiError } from '@/lib/api';

interface NotificationSetting {
  key: string;
  label: string;
  description?: string;
}

const CRUD_SETTINGS: { group: string; items: NotificationSetting[] }[] = [
  {
    group: 'Úkoly',
    items: [
      { key: 'notif_task_created', label: 'Nový úkol' },
      { key: 'notif_task_updated', label: 'Úkol upraven' },
      { key: 'notif_task_deleted', label: 'Úkol smazán' },
    ],
  },
  {
    group: 'Faktury',
    items: [
      { key: 'notif_invoice_created', label: 'Nová faktura' },
      { key: 'notif_invoice_updated', label: 'Faktura upravena' },
      { key: 'notif_invoice_deleted', label: 'Faktura smazána' },
    ],
  },
  {
    group: 'Platby',
    items: [
      { key: 'notif_payment_created', label: 'Nová platba' },
      { key: 'notif_payment_updated', label: 'Platba upravena' },
      { key: 'notif_payment_deleted', label: 'Platba smazána' },
    ],
  },
  {
    group: 'Transakce',
    items: [
      { key: 'notif_transaction_created', label: 'Nová transakce' },
      { key: 'notif_transaction_updated', label: 'Transakce upravena' },
      { key: 'notif_transaction_deleted', label: 'Transakce smazána' },
    ],
  },
];

const DEADLINE_SETTINGS: NotificationSetting[] = [
  { key: 'notif_task_deadline_soon', label: 'Úkol se blíží termínu' },
  { key: 'notif_task_overdue', label: 'Úkol je po termínu' },
  { key: 'notif_invoice_due_soon', label: 'Faktura se blíží splatnosti' },
  { key: 'notif_invoice_overdue', label: 'Faktura je po splatnosti' },
];

function Toggle({ checked, onChange, label }: { checked: boolean; onChange: (v: boolean) => void; label: string }) {
  return (
    <label className="flex cursor-pointer items-center justify-between gap-3 rounded-md border px-3 py-2 transition-colors hover:bg-accent/50">
      <span className="text-sm">{label}</span>
      <button
        type="button"
        role="switch"
        aria-checked={checked}
        onClick={() => onChange(!checked)}
        className={`relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors ${
          checked ? 'bg-primary' : 'bg-input'
        }`}
      >
        <span
          className={`inline-block h-4 w-4 transform rounded-full bg-background transition-transform ${
            checked ? 'translate-x-4' : 'translate-x-0.5'
          }`}
        />
      </button>
    </label>
  );
}

export function NotificationsTab() {
  const { data, isLoading } = useSettings();
  const updateMutation = useUpdateSettings();

  const [form, setForm] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    if (data) setForm({ ...data });
  }, [data]);

  const isOn = (key: string): boolean => (form[key] ?? '1') === '1';

  const toggle = (key: string) => {
    setForm((prev) => ({ ...prev, [key]: isOn(key) ? '0' : '1' }));
    setSaved(false);
  };

  const update = (key: string, value: string) => {
    setForm((prev) => ({ ...prev, [key]: value }));
    setSaved(false);
  };

  const handleSubmit = async (ev: React.FormEvent) => {
    ev.preventDefault();
    setError(null);
    setSaved(false);
    try {
      // Poslat jen notif_* klíče
      const payload: Record<string, string> = {};
      for (const key of Object.keys(form)) {
        if (key.startsWith('notif_')) {
          payload[key] = form[key];
        }
      }
      await updateMutation.mutateAsync(payload);
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
        <CardTitle>Notifikace</CardTitle>
      </CardHeader>
      <CardContent>
        <form onSubmit={handleSubmit} className="space-y-6">
          {/* Dny předem */}
          <div className="space-y-1.5">
            <Label htmlFor="notif_days_before">Kolik dní předem upozornit na termín</Label>
            <Input
              id="notif_days_before"
              type="number"
              min={1}
              max={30}
              value={form.notif_days_before ?? '2'}
              onChange={(e) => update('notif_days_before', e.target.value)}
              className="w-24"
            />
            <p className="text-xs text-muted-foreground">
              Počet dní před termínem úkolu a splatností faktury, kdy se vytvoří notifikace.
            </p>
          </div>

          {/* Termínové notifikace */}
          <div className="space-y-3">
            <h3 className="text-sm font-medium">Termínové připomínky</h3>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
              {DEADLINE_SETTINGS.map((item) => (
                <Toggle
                  key={item.key}
                  checked={isOn(item.key)}
                  onChange={() => toggle(item.key)}
                  label={item.label}
                />
              ))}
            </div>
          </div>

          {/* CRUD notifikace */}
          <div className="space-y-4">
            <h3 className="text-sm font-medium">Akce</h3>
            {CRUD_SETTINGS.map((group) => (
              <div key={group.group} className="space-y-2">
                <h4 className="text-xs font-medium text-muted-foreground">{group.group}</h4>
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
                  {group.items.map((item) => (
                    <Toggle
                      key={item.key}
                      checked={isOn(item.key)}
                      onChange={() => toggle(item.key)}
                      label={item.label}
                    />
                  ))}
                </div>
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
