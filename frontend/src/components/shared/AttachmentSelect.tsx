import { useClients } from '@/hooks/useClients';
import { useProjects } from '@/hooks/useProjects';
import { useTasks } from '@/hooks/useTasks';
import { useInvoices } from '@/hooks/useInvoices';
import { Select } from '@/components/ui/select';
import { Label } from '@/components/ui/label';
import { Users, FolderKanban, ListTodo, FileText } from 'lucide-react';

export interface AttachmentValue {
  entity_type: string;
  entity_id: number;
}

interface AttachmentSelectProps {
  value: AttachmentValue[];
  onChange: (value: AttachmentValue[]) => void;
}

const ENTITY_LABELS: Record<string, string> = {
  client: 'Klient',
  project: 'Projekt',
  task: 'Úkol',
  invoice: 'Faktura',
};

const ENTITY_ICONS: Record<string, typeof Users> = {
  client: Users,
  project: FolderKanban,
  task: ListTodo,
  invoice: FileText,
};

export function AttachmentSelect({ value, onChange }: AttachmentSelectProps) {
  const { data: clientsData } = useClients({ per_page: 200 });
  const { data: projectsData } = useProjects({ per_page: 200 });
  const { data: tasksData } = useTasks({ per_page: 200 });
  const { data: invoicesData } = useInvoices({ per_page: 200 });

  const getEntityId = (type: string): number => {
    const found = value.find((a) => a.entity_type === type);
    return found ? found.entity_id : 0;
  };

  const setEntity = (type: string, id: number) => {
    const others = value.filter((a) => a.entity_type !== type);
    if (id > 0) {
      onChange([...others, { entity_type: type, entity_id: id }]);
    } else {
      onChange(others);
    }
  };

  const entityTypes = ['client', 'project', 'task', 'invoice'];

  return (
    <div className="space-y-3">
      <Label>Vazby</Label>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        {entityTypes.map((type) => {
          const Icon = ENTITY_ICONS[type];
          let options: { id: number; label: string }[] = [];
          if (type === 'client') {
            options = (clientsData?.data ?? []).map((c) => ({ id: c.id, label: c.full_name }));
          } else if (type === 'project') {
            options = (projectsData?.data ?? []).map((p) => ({ id: p.id, label: p.name }));
          } else if (type === 'task') {
            options = (tasksData?.data ?? []).map((t) => ({ id: t.id, label: t.title }));
          } else if (type === 'invoice') {
            options = (invoicesData?.data ?? []).map((i) => ({ id: i.id, label: `${i.invoice_number}` }));
          }
          return (
            <div key={type} className="space-y-1.5">
              <label className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                <Icon className="h-3.5 w-3.5" />
                {ENTITY_LABELS[type]}
              </label>
              <Select
                value={String(getEntityId(type))}
                onChange={(e) => setEntity(type, Number(e.target.value))}
              >
                <option value="0">— žádná —</option>
                {options.map((opt) => (
                  <option key={opt.id} value={opt.id}>
                    {opt.label}
                  </option>
                ))}
              </Select>
            </div>
          );
        })}
      </div>
    </div>
  );
}
