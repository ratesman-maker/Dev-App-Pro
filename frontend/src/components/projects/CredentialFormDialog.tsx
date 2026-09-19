import { useState, useEffect } from 'react';
import { Dialog } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import { cn } from '@/lib/utils';
import {
  useCreateCredential,
  useUpdateCredential,
  type ProjectCredential,
  type CredentialInput,
  type CredentialType,
  CREDENTIAL_TYPE_LABELS,
} from '@/hooks/useProjectCredentials';
import { ApiError } from '@/lib/api';

interface CredentialFormDialogProps {
  open: boolean;
  onClose: () => void;
  projectId: number;
  credential?: ProjectCredential | null;
}

const emptyForm: CredentialInput = {
  type: 'sftp',
  name: '',
  host: '',
  port: null,
  username: '',
  password: '',
  database_name: '',
  extra: '',
  note: '',
};

const DEFAULT_PORTS: Record<CredentialType, number | null> = {
  sftp: 22,
  ftp: 21,
  ssh: 22,
  smtp: 587,
  database: 3306,
  admin: null,
  api: null,
  other: null,
};

export function CredentialFormDialog({ open, onClose, projectId, credential }: CredentialFormDialogProps) {
  const isEdit = !!credential;
  const createMutation = useCreateCredential(projectId);
  const updateMutation = useUpdateCredential(credential?.id ?? 0);
  const mutation = isEdit ? updateMutation : createMutation;

  const [form, setForm] = useState<CredentialInput>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [showPassword, setShowPassword] = useState(false);

  useEffect(() => {
    if (open) {
      if (credential) {
        setForm({
          type: credential.type,
          name: credential.name,
          host: credential.host ?? '',
          port: credential.port ?? null,
          username: credential.username ?? '',
          password: credential.password ?? '',
          database_name: credential.database_name ?? '',
          extra: credential.extra ?? '',
          note: credential.note ?? '',
        });
      } else {
        setForm(emptyForm);
      }
      setErrors({});
      setShowPassword(false);
    }
  }, [open, credential]);

  const update = (field: keyof CredentialInput, value: string | number | null) => {
    setForm((prev) => ({ ...prev, [field]: value }));
  };

  const updateType = (newType: CredentialType) => {
    setForm((prev) => ({
      ...prev,
      type: newType,
      port: prev.port === null ? DEFAULT_PORTS[newType] : prev.port,
    }));
  };

  const validate = (): boolean => {
    const e: Record<string, string> = {};
    if (!form.name?.trim()) e.name = 'Název je povinný';
    if (form.port !== null && form.port !== undefined && (form.port < 1 || form.port > 65535)) {
      e.port = 'Port musí být 1-65535';
    }
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

  const showDbField = form.type === 'database';

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title={isEdit ? 'Upravit přístup' : 'Nový přístup'}
      description={isEdit ? 'Upravte údaje přístupu.' : 'Vytvořte nový přístup k projektu.'}
      className="max-w-xl"
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-1.5">
            <Label htmlFor="cred-type">Typ *</Label>
            <Select
              id="cred-type"
              value={form.type}
              onChange={(e) => updateType(e.target.value as CredentialType)}
            >
              {Object.entries(CREDENTIAL_TYPE_LABELS).map(([value, label]) => (
                <option key={value} value={value}>{label}</option>
              ))}
            </Select>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="cred-name">Název *</Label>
            <Input
              id="cred-name"
              value={form.name}
              onChange={(e) => update('name', e.target.value)}
              placeholder="např. Produkční SFTP"
            />
            {errors.name && <p className="text-xs text-destructive">{errors.name}</p>}
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-1.5">
            <Label htmlFor="cred-host">Host</Label>
            <Input
              id="cred-host"
              value={form.host ?? ''}
              onChange={(e) => update('host', e.target.value)}
              placeholder="sftp.example.com"
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="cred-port">Port</Label>
            <Input
              id="cred-port"
              type="number"
              value={form.port ?? ''}
              onChange={(e) => update('port', e.target.value === '' ? null : parseInt(e.target.value, 10))}
              placeholder="auto"
            />
            {errors.port && <p className="text-xs text-destructive">{errors.port}</p>}
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-1.5">
            <Label htmlFor="cred-username">Uživatel</Label>
            <Input
              id="cred-username"
              value={form.username ?? ''}
              onChange={(e) => update('username', e.target.value)}
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="cred-password">Heslo</Label>
            <div className="flex gap-2">
              <Input
                id="cred-password"
                type={showPassword ? 'text' : 'password'}
                value={form.password ?? ''}
                onChange={(e) => update('password', e.target.value)}
                className="flex-1"
              />
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => setShowPassword(!showPassword)}
              >
                {showPassword ? 'Skrýt' : 'Zobrazit'}
              </Button>
            </div>
          </div>
        </div>

        {showDbField && (
          <div className="space-y-1.5">
            <Label htmlFor="cred-database">Databáze</Label>
            <Input
              id="cred-database"
              value={form.database_name ?? ''}
              onChange={(e) => update('database_name', e.target.value)}
              placeholder="název databáze"
            />
          </div>
        )}

        <div className="space-y-1.5">
          <Label htmlFor="cred-note">Poznámka</Label>
          <textarea
            id="cred-note"
            value={form.note ?? ''}
            onChange={(e) => update('note', e.target.value)}
            rows={2}
            className={cn(
              'flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring'
            )}
          />
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
