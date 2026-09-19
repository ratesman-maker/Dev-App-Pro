import { useState, useEffect } from 'react';
import { Dialog } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import { useProjects } from '@/hooks/useProjects';
import { useCreateTask, useUpdateTask, type Task, type TaskInput } from '@/hooks/useTasks';
import { ApiError } from '@/lib/api';
import { cn } from '@/lib/utils';

interface TaskFormDialogProps {
  open: boolean;
  onClose: () => void;
  task?: Task | null;
}

const emptyForm: TaskInput = {
  project_id: 0,
  title: '',
  description: '',
  status: 'todo',
  priority: 'medium',
  due_date: null,
  assigned_to: '',
  estimated_minutes: 0,
};

const STATUS_OPTIONS: { value: Task['status']; label: string }[] = [
  { value: 'todo', label: 'K vyřešení' },
  { value: 'in_progress', label: 'V řešení' },
  { value: 'done', label: 'Hotové' },
  { value: 'cancelled', label: 'Zrušeno' },
];

const PRIORITY_OPTIONS: { value: Task['priority']; label: string }[] = [
  { value: 'low', label: 'Nízká' },
  { value: 'medium', label: 'Střední' },
  { value: 'high', label: 'Vysoká' },
  { value: 'urgent', label: 'Urgentní' },
];

function toDateInput(value: string | null): string {
  if (!value) return '';
  return value.slice(0, 10);
}

export function TaskFormDialog({ open, onClose, task }: TaskFormDialogProps) {
  const isEdit = !!task;
  const createMutation = useCreateTask();
  const updateMutation = useUpdateTask(task?.id ?? 0);
  const mutation = isEdit ? updateMutation : createMutation;

  // Načteme projekty pro select
  const { data: projectsData } = useProjects({ per_page: 200 });

  const [form, setForm] = useState<TaskInput>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (open) {
      if (task) {
        setForm({
          project_id: task.project_id,
          title: task.title,
          description: task.description ?? '',
          status: task.status,
          priority: task.priority,
          due_date: task.due_date,
          assigned_to: task.assigned_to ?? '',
          estimated_minutes: task.estimated_minutes,
        });
      } else {
        setForm(emptyForm);
      }
      setErrors({});
    }
  }, [open, task]);

  const update = <K extends keyof TaskInput>(field: K, value: TaskInput[K]) => {
    setForm((prev) => ({ ...prev, [field]: value }));
  };

  const validate = (): boolean => {
    const e: Record<string, string> = {};
    if (!form.project_id) e.project_id = 'Projekt je povinný';
    if (!form.title?.trim()) e.title = 'Název úkolu je povinný';
    setErrors(e);
    return Object.keys(e).length === 0;
  };

  const handleSubmit = async (ev: React.FormEvent) => {
    ev.preventDefault();
    if (!validate()) return;
    try {
      const payload: TaskInput = {
        ...form,
        title: form.title.trim(),
        description: form.description?.trim() || null,
        due_date: form.due_date || null,
        assigned_to: form.assigned_to?.trim() || null,
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
      title={isEdit ? 'Upravit úkol' : 'Nový úkol'}
      description={isEdit ? 'Upravte údaje úkolu.' : 'Vytvořte nový úkol.'}
      className="max-w-xl"
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="space-y-1.5">
          <Label htmlFor="project_id">Projekt *</Label>
          <Select
            id="project_id"
            value={form.project_id || ''}
            onChange={(e) => update('project_id', e.target.value ? Number(e.target.value) : 0)}
          >
            <option value="">— vyberte projekt —</option>
            {projectsData?.data.map((p) => (
              <option key={p.id} value={p.id}>
                {p.name}
              </option>
            ))}
          </Select>
          {errors.project_id && <p className="text-xs text-destructive">{errors.project_id}</p>}
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="title">Název *</Label>
          <Input
            id="title"
            value={form.title}
            onChange={(e) => update('title', e.target.value)}
          />
          {errors.title && <p className="text-xs text-destructive">{errors.title}</p>}
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
              value={form.status ?? 'todo'}
              onChange={(e) => update('status', e.target.value as TaskInput['status'])}
            >
              {STATUS_OPTIONS.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </Select>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="priority">Priorita</Label>
            <Select
              id="priority"
              value={form.priority ?? 'medium'}
              onChange={(e) => update('priority', e.target.value as TaskInput['priority'])}
            >
              {PRIORITY_OPTIONS.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </Select>
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-1.5">
            <Label htmlFor="due_date">Termín</Label>
            <Input
              id="due_date"
              type="date"
              value={toDateInput(form.due_date ?? null)}
              onChange={(e) => update('due_date', e.target.value || null)}
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="assigned_to">Přiřazeno</Label>
            <Input
              id="assigned_to"
              value={form.assigned_to ?? ''}
              onChange={(e) => update('assigned_to', e.target.value)}
            />
          </div>
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="estimated_minutes">Odhad (minuty)</Label>
          <Input
            id="estimated_minutes"
            type="number"
            min={0}
            value={form.estimated_minutes || ''}
            onChange={(e) => update('estimated_minutes', e.target.value ? Number(e.target.value) : 0)}
            placeholder="0"
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
