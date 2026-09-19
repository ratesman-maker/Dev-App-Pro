import { useState, useEffect } from 'react';
import { Dialog } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import { useCreateWorklog, useUpdateWorklog, type WorklogEntry, type WorklogInput, type WorklogCategory, type WorklogSeverity } from '@/hooks/useWorklog';
import { useProjects } from '@/hooks/useProjects';
import { useClients } from '@/hooks/useClients';
import { ApiError } from '@/lib/api';

interface WorklogFormDialogProps {
  open: boolean;
  onClose: () => void;
  entry?: WorklogEntry | null;
}

const CATEGORIES: { value: WorklogCategory; label: string }[] = [
  { value: 'project', label: 'Projekt' },
  { value: 'security', label: 'Bezpečnost' },
  { value: 'maintenance', label: 'Údržba' },
  { value: 'meeting', label: 'Schůzka' },
  { value: 'other', label: 'Jiné' },
];

const SEVERITIES: { value: WorklogSeverity; label: string }[] = [
  { value: 'info', label: 'Info' },
  { value: 'warning', label: 'Varování' },
  { value: 'critical', label: 'Kritické' },
];

export function WorklogFormDialog({ open, onClose, entry }: WorklogFormDialogProps) {
  const isEdit = !!entry;
  const createMutation = useCreateWorklog();
  const updateMutation = useUpdateWorklog(entry?.id ?? 0);
  const mutation = isEdit ? updateMutation : createMutation;

  const [title, setTitle] = useState('');
  const [description, setDescription] = useState('');
  const [category, setCategory] = useState<WorklogCategory>('project');
  const [severity, setSeverity] = useState<WorklogSeverity>('info');
  const [projectId, setProjectId] = useState<number>(0);
  const [clientId, setClientId] = useState<number>(0);
  const [hours, setHours] = useState<string>('');
  const [isDone, setIsDone] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const { data: projectsData } = useProjects({ per_page: 200 });
  const { data: clientsData } = useClients({ per_page: 200 });

  useEffect(() => {
    if (open) {
      setTitle(entry?.title ?? '');
      setDescription(entry?.description ?? '');
      setCategory((entry?.category as WorklogCategory) ?? 'project');
      setSeverity((entry?.severity as WorklogSeverity) ?? 'info');
      setProjectId(entry?.project_id ?? 0);
      setClientId(entry?.client_id ?? 0);
      setHours(entry?.hours != null ? String(entry.hours) : '');
      setIsDone(!!entry?.is_done);
      setErrors({});
    }
  }, [open, entry]);

  const handleSubmit = async (ev: React.FormEvent) => {
    ev.preventDefault();
    const e: Record<string, string> = {};
    if (!title.trim()) e.title = 'Titulek je povinný';
    setErrors(e);
    if (Object.keys(e).length > 0) return;

    const data: WorklogInput = {
      title: title.trim(),
      description: description.trim() || null,
      category,
      severity,
      project_id: projectId || null,
      client_id: clientId || null,
      hours: hours ? parseFloat(hours) : null,
      is_done: isDone,
    };

    try {
      await mutation.mutateAsync(data);
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
      title={isEdit ? 'Upravit záznam' : 'Nový záznam'}
      description={isEdit ? 'Upravte záznam v pracovním deníku.' : 'Vytvořte nový záznam v pracovním deníku.'}
      className="max-w-2xl"
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="space-y-1.5">
          <Label htmlFor="worklog-title">Titulek *</Label>
          <Input
            id="worklog-title"
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            placeholder="Stručný popis činnosti"
          />
          {errors.title && <p className="text-xs text-destructive">{errors.title}</p>}
        </div>

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label htmlFor="worklog-category">Kategorie</Label>
            <Select
              id="worklog-category"
              value={category}
              onChange={(e) => setCategory(e.target.value as WorklogCategory)}
            >
              {CATEGORIES.map((c) => (
                <option key={c.value} value={c.value}>{c.label}</option>
              ))}
            </Select>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="worklog-severity">Závažnost</Label>
            <Select
              id="worklog-severity"
              value={severity}
              onChange={(e) => setSeverity(e.target.value as WorklogSeverity)}
            >
              {SEVERITIES.map((s) => (
                <option key={s.value} value={s.value}>{s.label}</option>
              ))}
            </Select>
          </div>
        </div>

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label htmlFor="worklog-project">Projekt</Label>
            <Select
              id="worklog-project"
              value={String(projectId)}
              onChange={(e) => setProjectId(Number(e.target.value))}
            >
              <option value="0">— Bez projektu —</option>
              {projectsData?.data.map((p) => (
                <option key={p.id} value={String(p.id)}>{p.name}</option>
              ))}
            </Select>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="worklog-client">Klient</Label>
            <Select
              id="worklog-client"
              value={String(clientId)}
              onChange={(e) => setClientId(Number(e.target.value))}
            >
              <option value="0">— Bez klienta —</option>
              {clientsData?.data.map((c) => (
                <option key={c.id} value={String(c.id)}>{c.full_name}</option>
              ))}
            </Select>
          </div>
        </div>

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label htmlFor="worklog-hours">Hodiny</Label>
            <Input
              id="worklog-hours"
              type="number"
              step="0.25"
              min="0"
              value={hours}
              onChange={(e) => setHours(e.target.value)}
              placeholder="0"
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="worklog-done">Hotovo</Label>
            <label className="flex h-9 items-center gap-2">
              <input
                id="worklog-done"
                type="checkbox"
                checked={isDone}
                onChange={(e) => setIsDone(e.target.checked)}
                className="h-4 w-4 rounded border-input"
              />
              <span className="text-sm">Označit jako dokončené</span>
            </label>
          </div>
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="worklog-description">Popis</Label>
          <textarea
            id="worklog-description"
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            rows={8}
            className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
            placeholder="Detailní popis činnosti..."
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
