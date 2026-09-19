import { useState, ReactNode } from 'react';
import { X } from 'lucide-react';
import { cn } from '@/lib/utils';

export interface DetailModalTab {
  id: string;
  label: string;
  content: ReactNode;
}

interface DetailModalProps {
  open: boolean;
  onClose: () => void;
  title: string;
  subtitle?: string;
  badges?: ReactNode;
  actions?: ReactNode;
  tabs: DetailModalTab[];
  defaultTab?: string;
  size?: 'md' | 'lg' | 'xl';
}

const sizeMap = {
  md: 'max-w-2xl',
  lg: 'max-w-4xl',
  xl: 'max-w-6xl',
};

export function DetailModal({
  open,
  onClose,
  title,
  subtitle,
  badges,
  actions,
  tabs,
  defaultTab,
  size = 'lg',
}: DetailModalProps) {
  const [active, setActive] = useState(defaultTab ?? tabs[0]?.id ?? '');

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-black/60" onClick={onClose} />
      <div className={cn('relative z-50 flex max-h-[90vh] w-full flex-col rounded-lg border bg-popover', sizeMap[size])}>
        {/* Header — povolí zalamování, aby tlačítka nepřetékala mimo okno */}
        <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 border-b p-6 pb-4">
          <div className="min-w-0 flex-1 space-y-1">
            <div className="flex flex-wrap items-center gap-3">
              <h2 className="min-w-0 break-words text-lg font-semibold">{title}</h2>
              {badges}
            </div>
            {subtitle && <p className="text-sm text-muted-foreground">{subtitle}</p>}
          </div>
          <div className="flex flex-wrap items-center justify-end gap-2">
            {actions}
            <button onClick={onClose} className="rounded-sm p-1 opacity-70 hover:opacity-100">
              <X className="h-4 w-4" />
            </button>
          </div>
        </div>

        {/* Tabs — horizontální scroll, aby se taby vešly */}
        <div className="flex gap-1 overflow-x-auto border-b px-6">
          {tabs.map((tab) => (
            <button
              key={tab.id}
              onClick={() => setActive(tab.id)}
              className={cn(
                'relative -mb-px shrink-0 border-b-2 px-3 py-2.5 text-sm font-medium transition-colors',
                active === tab.id
                  ? 'border-primary text-foreground'
                  : 'border-transparent text-muted-foreground hover:text-foreground'
              )}
            >
              {tab.label}
            </button>
          ))}
        </div>

        {/* Content */}
        <div className="min-h-0 flex-1 overflow-y-auto p-6">
          {tabs.find((t) => t.id === active)?.content}
        </div>
      </div>
    </div>
  );
}
