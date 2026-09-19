import { useState } from 'react';
import { ExternalLink, Loader2, Globe, XCircle, Server, Database, Mail } from 'lucide-react';
import { cn } from '@/lib/utils';
import {
  useWpInstalls,
  useCreateWpInstall,
  isInProgress,
  type WpInstall,
} from '@/hooks/useWpInstalls';
import { useSystemInfo } from '@/hooks/useSystemInfo';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Dialog } from '@/components/ui/dialog';
import { SystemInfoCard } from '@/components/shared/SystemInfoCard';

type TabKey = 'wordpress' | 'system';

const TABS: { key: TabKey; label: string }[] = [
  { key: 'wordpress', label: 'WordPress instalátor' },
  { key: 'system', label: 'Systém' },
];

export default function ToolsPage() {
  const [tab, setTab] = useState<TabKey>('wordpress');

  return (
    <div className="space-y-6 p-6">
      <h1 className="text-2xl font-semibold">Nástroje</h1>

      {/* Záložky */}
      <div className="flex gap-1 border-b">
        {TABS.map((t) => (
          <button
            key={t.key}
            onClick={() => setTab(t.key)}
            className={cn(
              'px-4 py-2 text-sm font-medium transition-colors',
              tab === t.key
                ? 'border-b-2 border-primary text-primary'
                : 'text-muted-foreground hover:text-foreground'
            )}
          >
            {t.label}
          </button>
        ))}
      </div>

      {/* Obsah záložek */}
      {tab === 'wordpress' && <WordPressTab />}
      {tab === 'system' && <SystemTab />}
    </div>
  );
}

function WordPressTab() {
  const [siteName, setSiteName] = useState('');
  const [adminEmail, setAdminEmail] = useState('');
  const [adminUser, setAdminUser] = useState('admin');
  const [adminPassword, setAdminPassword] = useState('');
  const [createdInstall, setCreatedInstall] = useState<WpInstall | null>(null);

  const { data } = useWpInstalls();
  const createMutation = useCreateWpInstall();

  const installs = data?.data ?? [];
  const activeJobs = installs.filter((i) => isInProgress(i.status));
  const failedJobs = installs.filter((i) => i.status === 'failed');

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      const result = await createMutation.mutateAsync({
        site_name: siteName,
        admin_email: adminEmail,
        admin_user: adminUser || undefined,
        admin_password: adminPassword || undefined,
      });
      setSiteName('');
      setAdminEmail('');
      setAdminUser('admin');
      setAdminPassword('');
      if (result?.id) {
        setCreatedInstall(result as WpInstall);
      }
    } catch {
      // chyba se zobrazí v UI přes mutation state
    }
  };

  return (
    <div className="space-y-6">
      {/* WordPress Installer */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Globe className="h-5 w-5" />
            WordPress Installer
          </CardTitle>
          <CardDescription>
            Vytvořte novou WordPress instalaci jedním kliknutím. Web bude plně nainstalován
            (databáze, admin účet) a dostupný na adrese{' '}
            <code className="rounded bg-muted px-1 py-0.5 text-sm">{'{název}.localhost'}</code>.
            Projekt se automaticky objeví v sekci Projekty.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={handleCreate} className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-end">
            <div className="space-y-2">
              <Label htmlFor="site_name">Název webu</Label>
              <div className="flex items-center">
                <Input
                  id="site_name"
                  placeholder="napr-muj-web"
                  value={siteName}
                  onChange={(e) => setSiteName(e.target.value)}
                  pattern="[a-z0-9-]+"
                  minLength={3}
                  maxLength={50}
                  required
                />
                <span className="ml-2 shrink-0 text-sm text-muted-foreground">.localhost</span>
              </div>
            </div>
            <div className="space-y-2">
              <Label htmlFor="admin_email">E-mail administrátora</Label>
              <Input
                id="admin_email"
                type="email"
                placeholder="admin@example.com"
                value={adminEmail}
                onChange={(e) => setAdminEmail(e.target.value)}
                required
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="admin_user">Uživatel admin</Label>
              <Input
                id="admin_user"
                placeholder="admin"
                value={adminUser}
                onChange={(e) => setAdminUser(e.target.value)}
                minLength={3}
                maxLength={60}
                pattern="[a-zA-Z0-9._-]+"
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="admin_password">Heslo admin (prázdné = náhodné)</Label>
              <Input
                id="admin_password"
                type="text"
                placeholder="(vygeneruje se)"
                value={adminPassword}
                onChange={(e) => setAdminPassword(e.target.value)}
                minLength={8}
              />
            </div>
            <div className="sm:col-span-2 lg:col-span-4">
              <Button type="submit" disabled={createMutation.isPending}>
                {createMutation.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
                Instalovat
              </Button>
            </div>
          </form>

          {createMutation.isError && (
            <p className="mt-3 text-sm text-destructive">
              {createMutation.error instanceof Error
                ? createMutation.error.message
                : 'Vytvoření instalace selhalo.'}
            </p>
          )}
        </CardContent>
      </Card>

      {/* Probíhající instalace */}
      {activeJobs.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-base">
              <Loader2 className="h-4 w-4 animate-spin" />
              Probíhající instalace
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-2">
            {activeJobs.map((job) => (
              <div key={job.id} className="flex items-center justify-between rounded border p-3">
                <div>
                  <span className="font-medium">{job.site_name}.localhost</span>
                  <span className="ml-2 text-sm text-muted-foreground">
                    {statusLabel(job.status)}
                  </span>
                </div>
                <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
              </div>
            ))}
          </CardContent>
        </Card>
      )}

      {/* Selhané instalace */}
      {failedJobs.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-base text-destructive">
              <XCircle className="h-4 w-4" />
              Selhané instalace
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-2">
            {failedJobs.map((job) => (
              <div key={job.id} className="rounded border border-destructive/50 p-3">
                <div className="font-medium">{job.site_name}.localhost</div>
                {job.error_message && (
                  <div className="mt-1 text-sm text-destructive">{job.error_message}</div>
                )}
              </div>
            ))}
          </CardContent>
        </Card>
      )}

      {/* Dialog s přihlašovacími údaji po instalaci */}
      <Dialog
        open={!!createdInstall}
        onClose={() => setCreatedInstall(null)}
        title="Instalace naplánována"
        description={`WordPress instalace „${createdInstall?.site_name ?? ''}.localhost" byla naplánována. Instalace proběhne automaticky během 1–2 minut. Projekt se objeví v sekci Projekty.`}
      >
        {createdInstall && (
          <div className="space-y-3">
            <div className="rounded border bg-muted p-3 text-sm">
              <div className="font-medium">Přihlašovací údaje:</div>
              <div className="mt-2 space-y-1">
                <div><strong>URL:</strong> https://{createdInstall.site_url}</div>
                <div><strong>Admin URL:</strong> https://{createdInstall.site_url}/wp-admin/</div>
                <div><strong>Uživatel:</strong> {createdInstall.admin_user ?? 'admin'}</div>
                <div><strong>Heslo:</strong> {createdInstall.admin_password ?? '—'}</div>
              </div>
            </div>
            <div className="flex gap-2">
              <Button asChild variant="outline" size="sm">
                <a
                  href={`https://${createdInstall.site_url}/wp-admin/`}
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  <ExternalLink className="h-3.5 w-3.5" />
                  Otevřít wp-admin
                </a>
              </Button>
              <Button variant="outline" size="sm" onClick={() => setCreatedInstall(null)}>
                Zavřít
              </Button>
            </div>
            <p className="text-xs text-muted-foreground">
              Poznámka: Údaje si uložte. Po zavření tohoto dialogu je najdete v detailu projektu v sekci Projekty.
            </p>
          </div>
        )}
      </Dialog>
    </div>
  );
}

function SystemTab() {
  const { data, isLoading, isError } = useSystemInfo();

  if (isLoading) {
    return (
      <div className="space-y-3">
        <Card>
          <CardContent className="py-10 text-center text-muted-foreground">
            <Loader2 className="mx-auto h-6 w-6 animate-spin" />
            <p className="mt-2">Načítání systémových informací...</p>
          </CardContent>
        </Card>
      </div>
    );
  }

  if (isError || !data) {
    return (
      <Card>
        <CardContent className="py-10 text-center text-destructive">
          Chyba při načítání systémových informací.
        </CardContent>
      </Card>
    );
  }

  const items = [data.php, data.apache, data.mariadb, data.node, data.wpcli, data.mkcert, data.composer, data.ssl_cert, data.disk, data.cron];

  return (
    <div className="space-y-6">
      {/* Webové nástroje */}
      <div className="space-y-3">
        <div className="flex items-center gap-2">
          <Server className="h-5 w-5 text-muted-foreground" />
          <h2 className="text-lg font-semibold">Webové nástroje</h2>
        </div>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {/* phpMyAdmin */}
          <Card>
            <CardContent className="flex items-center gap-3 p-4">
              <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10">
                <Database className="h-5 w-5 text-primary" />
              </div>
              <div className="flex-1 min-w-0">
                <div className="font-medium">phpMyAdmin</div>
                <div className="text-sm text-muted-foreground">Správa MariaDB databází</div>
              </div>
              <Button asChild variant="outline" size="sm">
                <a href="https://localhost/phpmyadmin/" target="_blank" rel="noopener noreferrer">
                  <ExternalLink className="h-3.5 w-3.5" />
                  Otevřít
                </a>
              </Button>
            </CardContent>
          </Card>

          {/* Mailpit */}
          <Card>
            <CardContent className="flex items-center gap-3 p-4">
              <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10">
                <Mail className="h-5 w-5 text-primary" />
              </div>
              <div className="flex-1 min-w-0">
                <div className="font-medium">Mailpit</div>
                <div className="text-sm text-muted-foreground">Zachytávání odchozích e-mailů</div>
              </div>
              <Button asChild variant="outline" size="sm">
                <a href="https://localhost/mailpit/" target="_blank" rel="noopener noreferrer">
                  <ExternalLink className="h-3.5 w-3.5" />
                  Otevřít
                </a>
              </Button>
            </CardContent>
          </Card>
        </div>
      </div>

      {/* Systémové informace */}
      <div className="space-y-3">
        <div className="flex items-center gap-2">
          <Server className="h-5 w-5 text-muted-foreground" />
          <h2 className="text-lg font-semibold">Systémové informace</h2>
        </div>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          {items.map((item) => (
            <SystemInfoCard key={item.name} item={item} />
          ))}
        </div>
      </div>
    </div>
  );
}

function statusLabel(status: string): string {
  const labels: Record<string, string> = {
    pending: 'Čeká ve frontě',
    downloading: 'Stahování WordPressu',
    extracting: 'Rozbalování',
    creating_db: 'Vytváření databáze',
    configuring: 'Konfigurace',
    pending_uninstall: 'Mazání',
  };
  return labels[status] ?? status;
}
