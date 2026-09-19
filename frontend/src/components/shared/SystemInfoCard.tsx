import { CheckCircle2, XCircle, AlertCircle } from 'lucide-react';
import type { SystemInfoItem, SystemModule } from '@/hooks/useSystemInfo';
import { Card, CardContent } from '@/components/ui/card';

function StatusIcon({ running }: { running: boolean }) {
  return running ? (
    <CheckCircle2 className="h-5 w-5 text-green-600" />
  ) : (
    <XCircle className="h-5 w-5 text-destructive" />
  );
}

export function SystemInfoCard({ item }: { item: SystemInfoItem }) {
  const detailEntries = Object.entries(item.details || {});
  const hasModuleList = Array.isArray(item.modules) && item.modules.length > 0;
  const hasModuleMap = item.modules && !Array.isArray(item.modules) && Object.keys(item.modules).length > 0;

  return (
    <Card>
      <CardContent className="p-4">
        {/* Header */}
        <div className="flex items-center gap-2">
          <StatusIcon running={item.running} />
          <span className="font-semibold">{item.name}</span>
        </div>
        {item.version && (
          <div className="mt-1 text-sm text-muted-foreground break-all">{item.version}</div>
        )}

        {/* Details */}
        {detailEntries.length > 0 && (
          <dl className="mt-3 space-y-1.5 text-sm">
            {detailEntries.map(([key, value]) => (
              <div key={key} className="space-y-0.5">
                <dt className="text-xs uppercase tracking-wide text-muted-foreground">{key}</dt>
                <dd className="font-medium break-all">{value}</dd>
              </div>
            ))}
          </dl>
        )}

        {/* Module list (Apache) */}
        {hasModuleList && (
          <div className="mt-3 flex flex-wrap gap-1">
            {(item.modules as string[]).map((mod) => (
              <span
                key={mod}
                className="rounded border bg-muted px-1.5 py-0.5 text-xs font-medium"
              >
                {mod}
              </span>
            ))}
          </div>
        )}

        {/* Module map (PHP) */}
        {hasModuleMap && (
          <div className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
            {Object.entries(item.modules as SystemModule).map(([mod, loaded]) => (
              <div key={mod} className="flex items-center gap-1">
                {loaded ? (
                  <CheckCircle2 className="h-3 w-3 text-green-600" />
                ) : (
                  <AlertCircle className="h-3 w-3 text-muted-foreground" />
                )}
                <span className={loaded ? '' : 'text-muted-foreground line-through'}>{mod}</span>
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
