import { useState, useEffect } from 'react';
import { Dialog } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import { useClients } from '@/hooks/useClients';
import { useCreateProject, useUpdateProject, type Project, type ProjectInput } from '@/hooks/useProjects';
import { ApiError } from '@/lib/api';
import { cn } from '@/lib/utils';

interface ProjectFormDialogProps {
  open: boolean;
  onClose: () => void;
  project?: Project | null;
}

const emptyForm: ProjectInput = {
  client_id: null,
  name: '',
  description: '',
  status: 'active',
  budget_cents: 0,
  started_at: null,
  deadline: null,
  type: null,
  folder_path: null,
};

const STATUS_OPTIONS: { value: Project['status']; label: string }[] = [
  { value: 'active', label: 'Aktivní' },
  { value: 'on_hold', label: 'Pozastaveno' },
  { value: 'completed', label: 'Dokončeno' },
  { value: 'cancelled', label: 'Zrušeno' },
];

// Převede ISO datum (např. 2024-01-15T00:00:00Z) na yyyy-mm-dd pro input[type=date]
function toDateInput(value: string | null): string {
  if (!value) return '';
  return value.slice(0, 10);
}

export function ProjectFormDialog({ open, onClose, project }: ProjectFormDialogProps) {
  const isEdit = !!project;
  const createMutation = useCreateProject();
  const updateMutation = useUpdateProject(project?.id ?? 0);
  const mutation = isEdit ? updateMutation : createMutation;

  // Načteme klienty pro select (větší stránka, abychom měli dost možností)
  const { data: clientsData } = useClients({ per_page: 200 });

  const [form, setForm] = useState<ProjectInput>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (open) {
      if (project) {
        setForm({
          client_id: project.client_id,
          name: project.name,
          description: project.description ?? '',
          status: project.status === 'archived' ? 'active' : project.status,
          budget_cents: project.budget_cents,
          started_at: project.started_at,
          deadline: project.deadline,
          type: project.type ?? null,
          folder_path: project.folder_path ?? null,
        });
      } else {
        setForm(emptyForm);
      }
      setErrors({});
    }
  }, [open, project]);

  const update = <K extends keyof ProjectInput>(field: K, value: ProjectInput[K]) => {
    setForm((prev) => ({ ...prev, [field]: value }));
  };

  const validate = (): boolean => {
    const e: Record<string, string> = {};
    if (!form.name?.trim()) e.name = 'Název projektu je povinný';
    setErrors(e);
    return Object.keys(e).length === 0;
  };

  const handleSubmit = async (ev: React.FormEvent) => {
    ev.preventDefault();
    if (!validate()) return;
    try {
      // budget_cents: uživatel zadává Kč, převod na centy * 100
      const payload: ProjectInput = {
        ...form,
        name: form.name.trim(),
        description: form.description?.trim() || null,
        started_at: form.started_at || null,
        deadline: form.deadline || null,
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

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title={isEdit ? 'Upravit projekt' : 'Nový projekt'}
      description={isEdit ? 'Upravte údaje projektu.' : 'Vytvořte nový projekt.'}
      className="max-w-xl"
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="space-y-1.5">
          <Label htmlFor="name">Název *</Label>
          <Input
            id="name"
            value={form.name}
            onChange={(e) => update('name', e.target.value)}
          />
          {errors.name && <p className="text-xs text-destructive">{errors.name}</p>}
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="client_id">Klient</Label>
          <Select
            id="client_id"
            value={form.client_id ?? ''}
            onChange={(e) => update('client_id', e.target.value ? Number(e.target.value) : null)}
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
          <Label htmlFor="description">Popis</Label>
          <textarea
            id="description"
            value={form.description ?? ''}
            onChange={(e) => update('description', e.target.value)}
            rows={3}
            className={cn(
              'flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50'
            )}
          />
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-1.5">
            <Label htmlFor="status">Status</Label>
            <Select
              id="status"
              value={form.status ?? 'active'}
              onChange={(e) => update('status', e.target.value as ProjectInput['status'])}
            >
              {STATUS_OPTIONS.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </Select>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="budget">Rozpočet (Kč)</Label>
            <Input
              id="budget"
              type="number"
              min={0}
              value={form.budget_cents ? Math.round(form.budget_cents / 100) : ''}
              onChange={(e) => update('budget_cents', e.target.value ? Math.round(Number(e.target.value) * 100) : 0)}
              placeholder="0"
            />
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-1.5">
            <Label htmlFor="started_at">Začátek</Label>
            <Input
              id="started_at"
              type="date"
              value={toDateInput(form.started_at ?? null)}
              onChange={(e) => update('started_at', e.target.value || null)}
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="deadline">Termín</Label>
            <Input
              id="deadline"
              type="date"
              value={toDateInput(form.deadline ?? null)}
              onChange={(e) => update('deadline', e.target.value || null)}
            />
          </div>
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
