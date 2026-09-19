import { memo } from 'react';
import { Button } from '@/components/ui/button';
import { Eye, Pencil, Trash2, Archive, RotateCcw, Download, FileText, LogIn, ExternalLink } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

export interface ActionButtonConfig {
  icon: 'view' | 'edit' | 'delete' | 'archive' | 'restore' | 'download' | 'pdf' | 'login' | 'preview';
  label: string;
  onClick: () => void;
  destructive?: boolean;
  disabled?: boolean;
}

const iconMap: Record<ActionButtonConfig['icon'], LucideIcon> = {
  view: Eye,
  edit: Pencil,
  delete: Trash2,
  archive: Archive,
  restore: RotateCcw,
  download: Download,
  pdf: FileText,
  login: LogIn,
  preview: ExternalLink,
};

function ActionButtonsInner({ actions }: { actions: ActionButtonConfig[] }) {
  return (
    <div className="flex items-center gap-1">
      {actions.map((a) => {
        const Icon = iconMap[a.icon];
        return (
          <Button
            key={a.label}
            variant="ghost"
            size="icon"
            className={a.destructive ? 'text-destructive hover:bg-destructive/10' : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground'}
            onClick={a.onClick}
            disabled={a.disabled}
            aria-label={a.label}
            title={a.label}
          >
            <Icon className="h-4 w-4" />
          </Button>
        );
      })}
    </div>
  );
}

export const ActionButtons = memo(ActionButtonsInner);
