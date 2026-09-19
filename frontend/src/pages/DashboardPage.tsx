import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Area, AreaChart, CartesianGrid, XAxis } from 'recharts';
import type { ColumnDef, SortingState } from '@tanstack/react-table';
import {
  AlertTriangle,
  ArrowRight,
  CalendarClock,
  DollarSign,
  FileText,
  FolderKanban,
  MessageSquare,
  Users,
  type LucideIcon,
} from 'lucide-react';
import { api } from '@/lib/api';
import { cn, fmtDate, fmtMoney } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { ChartContainer, ChartTooltip, ChartTooltipContent, type ChartConfig } from '@/components/ui/chart';
import { DataTable } from '@/components/shared/DataTable';
import { ClientDetailModal } from '@/components/clients/ClientDetailModal';
import { ProjectDetailModal } from '@/components/projects/ProjectDetailModal';

type BadgeVariant = 'default' | 'secondary' | 'destructive' | 'outline';

function clientTypeLabel(type: string): string {
  switch (type) {
    case 'individual': return 'Osoba';
    case 'company': return 'Firma';
    case 'nonprofit': return 'Neziskový';
    case 'government': return 'Státní správa';
    default: return type;
  }
}

function projectStatusBadge(status: string): { variant: BadgeVariant; label: string } {
  switch (status) {
    case 'active': return { variant: 'default', label: 'Aktivní' };
    case 'on_hold': return { variant: 'secondary', label: 'Pozastaveno' };
    case 'completed': return { variant: 'secondary', label: 'Dokončeno' };
    case 'cancelled': return { variant: 'destructive', label: 'Zrušeno' };
    case 'archived': return { variant: 'outline', label: 'Archivováno' };
    default: return { variant: 'outline', label: status };
  }
}

// === Typy odpovědi /api/dashboard ===
interface ClientOverview {
  id: number;
  type: string;
  name: string;
  email: string | null;
  projects_count: number;
  active_projects_count: number;
  invoices_count: number;
  open_cents: number;
  overdue_count: number;
  overdue_cents: number;
  income_cents: number;
  last_note: { title: string | null; snippet: string | null; created_at: string | null } | null;
  last_activity_at: string;
}

interface ProjectOverview {
  id: number;
  name: string;
  status: string;
  client_name: string | null;
  budget_cents: number;
  income_cents: number;
  invoices_count: number;
  open_cents: number;
  deadline: string | null;
}

interface FinancePoint {
  month: string;
  income_cents: number;
  expense_cents: number;
}

interface OverdueInvoice {
  id: number;
  invoice_number: string;
  client_name: string | null;
  amount_cents: number;
  due_date: string;
  days_overdue: number;
}

interface DeadlineItem {
  id: number;
  name: string;
  deadline: string;
  days_left: number;
  client_name: string | null;
}

interface RecentNote {
  id: number;
  title: string | null;
  snippet: string | null;
  created_at: string;
  entity_label: string;
}

interface DashboardData {
  kpis: {
    clients: number;
    projects: number;
    active_projects: number;
    open_invoices_count: number;
    open_cents: number;
    overdue_count: number;
    overdue_cents: number;
  };
  clients: ClientOverview[];
  projects: ProjectOverview[];
  finance_series: FinancePoint[];
  attention: {
    overdue_invoices: OverdueInvoice[];
    upcoming_deadlines: DeadlineItem[];
    recent_notes: RecentNote[];
  };
}

function useDashboard() {
  return useQuery<DashboardData>({
    queryKey: ['dashboard'],
    queryFn: () => api.get<DashboardData>('/dashboard'),
  });
}

// === Karty s metrikami (shadcn section-cards) ===
function MetricCard({
  icon: Icon,
  label,
  value,
  badge,
  badgeTone = 'outline',
  footer1,
  footer2,
  danger = false,
}: {
  icon: LucideIcon;
  label: string;
  value: string;
  badge?: string;
  badgeTone?: BadgeVariant;
  footer1: string;
  footer2: string;
  danger?: boolean;
}) {
  return (
    <Card className="bg-gradient-to-t from-primary/5 to-card">
      <CardHeader className="flex flex-row items-start justify-between space-y-0 pb-2">
        <div className="space-y-1">
          <CardDescription>{label}</CardDescription>
          <CardTitle className={cn('text-2xl font-semibold tabular-nums', danger && 'text-destructive')}>{value}</CardTitle>
        </div>
        <div className="flex flex-col items-end gap-2">
          <Icon className={cn('h-5 w-5', danger ? 'text-destructive' : 'text-muted-foreground')} />
          {badge !== undefined && <Badge variant={badgeTone}>{badge}</Badge>}
        </div>
      </CardHeader>
      <CardFooter className="flex-col items-start gap-1 text-sm">
        <div className="font-medium">{footer1}</div>
        <div className="text-muted-foreground">{footer2}</div>
      </CardFooter>
    </Card>
  );
}

// === Graf příjmů a výdajů (shadcn chart-area) ===
// Klíče configu musí odpovídat datovým položkám (income_cents / expense_cents).
// Barvy se definují přes CSS třídu .chart-finance (CSP style-src 'self').
const chartConfig = {
  income_cents: { label: 'Příjmy', color: 'var(--color-income)' },
  expense_cents: { label: 'Výdaje', color: 'var(--color-expense)' },
} satisfies ChartConfig;

function FinanceChart({ series }: { series: FinancePoint[] }) {
  const [range, setRange] = useState<'12m' | '6m' | '3m'>('12m');
  const months = range === '12m' ? 12 : range === '6m' ? 6 : 3;
  const data = series.slice(-months);

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between space-y-0">
        <div className="space-y-1">
          <CardTitle>Příjmy a výdaje</CardTitle>
          <CardDescription>Transakce za poslední {range === '12m' ? 'rok' : `${months} měsíců`}</CardDescription>
        </div>
        <div className="flex items-center gap-1 rounded-lg border p-0.5">
          {(['12m', '6m', '3m'] as const).map((r) => (
            <button
              key={r}
              type="button"
              onClick={() => setRange(r)}
              className={cn(
                'rounded-md px-2.5 py-1 text-xs font-medium transition-colors',
                range === r ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground'
              )}
            >
              {r === '12m' ? '12M' : r === '6m' ? '6M' : '3M'}
            </button>
          ))}
        </div>
      </CardHeader>
      <CardContent className="px-2 pt-4 sm:px-6 sm:pt-6">
        <ChartContainer config={chartConfig} className="chart-finance aspect-auto h-[260px] w-full">
          <AreaChart data={data} margin={{ top: 5, right: 10, left: 10, bottom: 0 }}>
            <defs>
              <linearGradient id="fillIncome" x1="0" y1="0" x2="0" y2="1">
                <stop offset="5%" stopColor="var(--color-income)" stopOpacity={0.9} />
                <stop offset="95%" stopColor="var(--color-income)" stopOpacity={0.1} />
              </linearGradient>
              <linearGradient id="fillExpense" x1="0" y1="0" x2="0" y2="1">
                <stop offset="5%" stopColor="var(--color-expense)" stopOpacity={0.7} />
                <stop offset="95%" stopColor="var(--color-expense)" stopOpacity={0.05} />
              </linearGradient>
            </defs>
            <CartesianGrid vertical={false} />
            <XAxis
              dataKey="month"
              tickLine={false}
              axisLine={false}
              tickMargin={8}
              minTickGap={24}
              tickFormatter={(value: string) => {
                const [y, m] = value.split('-');
                return `${m}/${y.slice(2)}`;
              }}
            />
            <ChartTooltip
              cursor={false}
              content={
                <ChartTooltipContent
                  labelFormatter={(label) => {
                    const [y, m] = String(label).split('-');
                    const names = ['Leden', 'Únor', 'Březen', 'Duben', 'Květen', 'Červen', 'Červenec', 'Srpen', 'Září', 'Říjen', 'Listopad', 'Prosinec'];
                    return `${names[Number(m) - 1]} ${y}`;
                  }}
                  formatter={(value) => <span className="font-medium tabular-nums">{fmtMoney(Number(value))}</span>}
                />
              }
            />
            <Area dataKey="income_cents" type="monotone" stroke="var(--color-income)" fill="url(#fillIncome)" strokeWidth={2} />
            <Area dataKey="expense_cents" type="monotone" stroke="var(--color-expense)" fill="url(#fillExpense)" strokeWidth={2} />
          </AreaChart>
        </ChartContainer>
      </CardContent>
    </Card>
  );
}

// === Tabulka klientů ===
const clientColumns: ColumnDef<ClientOverview>[] = [
  {
    accessorKey: 'name',
    header: 'Klient',
    meta: { width: '30%' },
    cell: ({ row }) => (
      <div className="min-w-0">
        <div className="truncate font-medium">{row.original.name || '—'}</div>
        <div className="truncate text-xs text-muted-foreground">
          {clientTypeLabel(row.original.type)}
          {row.original.email ? ` · ${row.original.email}` : ''}
        </div>
      </div>
    ),
  },
  {
    id: 'projects',
    header: 'Projekty',
    meta: { width: '12%' },
    cell: ({ row }) => (
      <span className="tabular-nums">
        {row.original.projects_count === 0 ? '—' : `${row.original.active_projects_count}/${row.original.projects_count} aktivních`}
      </span>
    ),
    sortingFn: (a, b) => a.original.projects_count - b.original.projects_count,
  },
  {
    id: 'invoices',
    header: 'Faktury',
    meta: { width: '18%' },
    cell: ({ row }) => {
      const c = row.original;
      if (c.invoices_count === 0) return '—';
      return (
        <span className={cn('tabular-nums', c.overdue_count > 0 && 'font-medium text-destructive')}>
          {c.invoices_count} ks
          {c.overdue_count > 0 ? ` · ${c.overdue_count} po spl.` : ''}
        </span>
      );
    },
  },
  {
    id: 'income',
    header: 'Příjmy',
    meta: { width: '14%' },
    cell: ({ row }) => (row.original.income_cents > 0 ? fmtMoney(row.original.income_cents) : '—'),
    sortingFn: (a, b) => a.original.income_cents - b.original.income_cents,
  },
  {
    id: 'note',
    header: 'Poslední poznámka',
    meta: { width: '18%' },
    cell: ({ row }) => {
      const n = row.original.last_note;
      if (!n) return '—';
      return (
        <div className="min-w-0">
          <div className="truncate">{n.title || n.snippet}</div>
          {n.created_at && <div className="text-xs text-muted-foreground">{fmtDate(n.created_at)}</div>}
        </div>
      );
    },
  },
  {
    id: 'activity',
    header: 'Aktivita',
    meta: { width: '8%' },
    cell: ({ row }) => <span className="tabular-nums">{fmtDate(row.original.last_activity_at)}</span>,
    sortingFn: (a, b) => a.original.last_activity_at.localeCompare(b.original.last_activity_at),
  },
];

// === Tabulka projektů ===
const projectColumns: ColumnDef<ProjectOverview>[] = [
  {
    accessorKey: 'name',
    header: 'Projekt',
    meta: { width: '26%' },
    cell: ({ row }) => (
      <div className="min-w-0">
        <div className="truncate font-medium">{row.original.name}</div>
        {row.original.client_name && <div className="truncate text-xs text-muted-foreground">{row.original.client_name}</div>}
      </div>
    ),
  },
  {
    id: 'status',
    header: 'Status',
    meta: { width: '10%' },
    cell: ({ row }) => {
      const b = projectStatusBadge(row.original.status);
      return <Badge variant={b.variant}>{b.label}</Badge>;
    },
    sortingFn: (a, b) => a.original.status.localeCompare(b.original.status),
  },
  {
    id: 'budget',
    header: 'Rozpočet',
    meta: { width: '28%' },
    cell: ({ row }) => {
      const p = row.original;
      if (p.budget_cents <= 0) return '—';
      const pct = Math.min(100, Math.round((p.income_cents / p.budget_cents) * 100));
      return (
        <div className="min-w-0">
          <div className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
            <span className="truncate tabular-nums">
              {fmtMoney(p.income_cents)} / {fmtMoney(p.budget_cents)}
            </span>
            <span className="shrink-0 tabular-nums">{pct} %</span>
          </div>
          <div className="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-muted">
            <div
              className={cn('h-full rounded-full progress-bar', pct >= 100 ? 'bg-emerald-500' : 'bg-primary')}
              style={{ '--progress': `${pct}%` } as React.CSSProperties}
            />
          </div>
        </div>
      );
    },
    sortingFn: (a, b) => {
      const pa = a.original.budget_cents > 0 ? a.original.income_cents / a.original.budget_cents : -1;
      const pb = b.original.budget_cents > 0 ? b.original.income_cents / b.original.budget_cents : -1;
      return pa - pb;
    },
  },
  {
    id: 'invoices',
    header: 'Faktury',
    meta: { width: '18%' },
    cell: ({ row }) => {
      const p = row.original;
      if (p.invoices_count === 0) return '—';
      return (
        <span className={cn('tabular-nums', p.open_cents > 0 && 'font-medium text-destructive')}>
          {p.open_cents > 0 ? `${fmtMoney(p.open_cents)} otevřeno` : `${p.invoices_count} ks`}
        </span>
      );
    },
  },
  {
    id: 'deadline',
    header: 'Termín',
    meta: { width: '18%' },
    cell: ({ row }) => {
      const d = row.original.deadline;
      if (!d) return '—';
      const past = new Date(d) < new Date();
      return <span className={cn('tabular-nums', past && 'font-medium text-destructive')}>{fmtDate(d)}</span>;
    },
    sortingFn: (a, b) => (a.original.deadline ?? '9999').localeCompare(b.original.deadline ?? '9999'),
  },
];

// === Hlavní stránka ===
export default function DashboardPage() {
  const { data, isLoading, isError, error } = useDashboard();
  const [viewClientId, setViewClientId] = useState<number | null>(null);
  const [viewProjectId, setViewProjectId] = useState<number | null>(null);

  const clientSorting: SortingState = [{ id: 'activity', desc: true }];
  const projectSorting: SortingState = [{ id: 'deadline', desc: false }];

  return (
    <div className="space-y-4 p-6">
      {/* Header */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="space-y-1">
          <h1 className="text-2xl font-semibold">Nástěnka</h1>
          <p className="text-sm text-muted-foreground">Klienti, projekty a finance na jednom místě.</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button asChild variant="outline" size="sm">
            <Link to="/clients">
              <Users className="h-4 w-4" />
              Nový klient
            </Link>
          </Button>
          <Button asChild variant="outline" size="sm">
            <Link to="/projects">
              <FolderKanban className="h-4 w-4" />
              Nový projekt
            </Link>
          </Button>
          <Button asChild variant="outline" size="sm">
            <Link to="/finance">
              <DollarSign className="h-4 w-4" />
              Vystavit fakturu
            </Link>
          </Button>
        </div>
      </div>

      {isLoading && (
        <Card>
          <CardContent className="py-10 text-center text-muted-foreground">Načítání nástěnky...</CardContent>
        </Card>
      )}
      {isError && (
        <Card>
          <CardContent className="py-10 text-center text-destructive">
            Chyba při načítání nástěnky: {error instanceof Error ? error.message : 'Neznámá chyba'}
          </CardContent>
        </Card>
      )}

      {data && (
        <>
          {/* Metriky */}
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <MetricCard
              icon={Users}
              label="Klienti"
              value={String(data.kpis.clients)}
              badge={`${data.clients.filter((c) => c.projects_count > 0).length} s projekty`}
              footer1="Evidence klientů s vazbami"
              footer2="Faktury, projekty, poznámky, příjmy"
            />
            <MetricCard
              icon={FolderKanban}
              label="Aktivní projekty"
              value={String(data.kpis.active_projects)}
              badge={`z ${data.kpis.projects} celkem`}
              footer1="Rozpočty, termíny a faktury"
              footer2="Interní i klientské projekty"
            />
            <MetricCard
              icon={FileText}
              label="Otevřené faktury"
              value={fmtMoney(data.kpis.open_cents)}
              badge={`${data.kpis.open_invoices_count} ks`}
              footer1="Faktury k úhradě"
              footer2="Odeslané a po splatnosti"
            />
            <MetricCard
              icon={AlertTriangle}
              label="Po splatnosti"
              value={fmtMoney(data.kpis.overdue_cents)}
              badge={`${data.kpis.overdue_count} ks`}
              badgeTone="destructive"
              footer1="Vyžaduje pozornost"
              footer2="Faktury po termínu splatnosti"
              danger={data.kpis.overdue_count > 0}
            />
          </div>

          {/* Graf */}
          <FinanceChart series={data.finance_series} />

          {/* Klienti a projekty — pod sebou */}
          <div className="grid grid-cols-1 gap-4">
            <Card className="min-w-0">
              <CardHeader>
                <CardTitle>Klienti</CardTitle>
              </CardHeader>
              <CardContent className="min-w-0 pt-2">
                <DataTable
                  columns={clientColumns}
                  data={data.clients.slice(0, 5)}
                  sorting={clientSorting}
                  onRowClick={(c) => setViewClientId(c.id)}
                />
                <Button asChild variant="ghost" size="sm" className="mt-2 w-full justify-center text-muted-foreground">
                  <Link to="/clients">
                    Zobrazit všechny klienty
                    <ArrowRight className="h-4 w-4" />
                  </Link>
                </Button>
              </CardContent>
            </Card>

            <Card className="min-w-0">
              <CardHeader>
                <CardTitle>Aktivní projekty</CardTitle>
              </CardHeader>
              <CardContent className="min-w-0 pt-2">
                <DataTable
                  columns={projectColumns}
                  data={data.projects.slice(0, 5)}
                  sorting={projectSorting}
                  onRowClick={(p) => setViewProjectId(p.id)}
                />
                <Button asChild variant="ghost" size="sm" className="mt-2 w-full justify-center text-muted-foreground">
                  <Link to="/projects">
                    Zobrazit všechny projekty
                    <ArrowRight className="h-4 w-4" />
                  </Link>
                </Button>
              </CardContent>
            </Card>
          </div>

          {/* Co řešit */}
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <Card>
              <CardHeader className="flex flex-row items-center justify-between space-y-0">
                <CardTitle>Faktury po splatnosti</CardTitle>
                <Button asChild variant="ghost" size="sm">
                  <Link to="/finance">
                    Vše
                    <ArrowRight className="h-4 w-4" />
                  </Link>
                </Button>
              </CardHeader>
              <CardContent className="pt-2">
                {data.attention.overdue_invoices.length === 0 ? (
                  <p className="py-6 text-center text-sm text-muted-foreground">Vše zaplaceno, žádná po splatnosti.</p>
                ) : (
                  <ul className="divide-y divide-border">
                    {data.attention.overdue_invoices.map((inv) => (
                      <li key={inv.id} className="flex items-center justify-between gap-2 py-3">
                        <div className="min-w-0">
                          <p className="truncate font-medium">{inv.invoice_number}</p>
                          <p className="truncate text-xs text-muted-foreground">
                            {inv.client_name ?? '—'} · {fmtDate(inv.due_date)}
                          </p>
                        </div>
                        <div className="shrink-0 text-right">
                          <p className="font-semibold text-destructive">{fmtMoney(inv.amount_cents)}</p>
                          <p className="text-xs text-destructive">{inv.days_overdue} dní</p>
                        </div>
                      </li>
                    ))}
                  </ul>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader className="flex flex-row items-center justify-between space-y-0">
                <CardTitle>Termíny projektů</CardTitle>
                <Button asChild variant="ghost" size="sm">
                  <Link to="/projects">
                    Vše
                    <ArrowRight className="h-4 w-4" />
                  </Link>
                </Button>
              </CardHeader>
              <CardContent className="pt-2">
                {data.attention.upcoming_deadlines.length === 0 ? (
                  <p className="py-6 text-center text-sm text-muted-foreground">Žádné termíny do 14 dní.</p>
                ) : (
                  <ul className="divide-y divide-border">
                    {data.attention.upcoming_deadlines.map((d) => (
                      <li key={d.id}>
                        <button
                          type="button"
                          onClick={() => setViewProjectId(d.id)}
                          className="flex w-full items-center justify-between gap-2 py-3 text-left transition-colors hover:bg-accent/40"
                        >
                          <div className="min-w-0">
                            <p className="truncate font-medium">{d.name}</p>
                            <p className="truncate text-xs text-muted-foreground">{d.client_name ?? '—'}</p>
                          </div>
                          <span
                            className={cn(
                              'flex shrink-0 items-center gap-1 text-sm font-medium tabular-nums',
                              d.days_left < 0 ? 'text-destructive' : d.days_left <= 7 ? 'text-orange-500' : 'text-muted-foreground'
                            )}
                          >
                            <CalendarClock className="h-3.5 w-3.5" />
                            {d.days_left < 0 ? `po termínu ${-d.days_left} dní` : `za ${d.days_left} dní`}
                          </span>
                        </button>
                      </li>
                    ))}
                  </ul>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader className="flex flex-row items-center justify-between space-y-0">
                <CardTitle>Poslední poznámky</CardTitle>
                <Button asChild variant="ghost" size="sm">
                  <Link to="/notes">
                    Vše
                    <ArrowRight className="h-4 w-4" />
                  </Link>
                </Button>
              </CardHeader>
              <CardContent className="pt-2">
                {data.attention.recent_notes.length === 0 ? (
                  <p className="py-6 text-center text-sm text-muted-foreground">Žádné poznámky.</p>
                ) : (
                  <ul className="divide-y divide-border">
                    {data.attention.recent_notes.map((n) => (
                      <li key={n.id} className="py-3">
                        <div className="flex items-center gap-2 text-xs text-muted-foreground">
                          <MessageSquare className="h-3.5 w-3.5 shrink-0" />
                          <span className="truncate">{n.entity_label}</span>
                          <span className="shrink-0 tabular-nums">{fmtDate(n.created_at)}</span>
                        </div>
                        {n.title && <p className="truncate text-sm font-medium">{n.title}</p>}
                        {n.snippet && !n.title && <p className="truncate text-sm text-muted-foreground">{n.snippet}</p>}
                      </li>
                    ))}
                  </ul>
                )}
              </CardContent>
            </Card>
          </div>
        </>
      )}

      <ClientDetailModal open={viewClientId !== null} onClose={() => setViewClientId(null)} clientId={viewClientId} />
      <ProjectDetailModal open={viewProjectId !== null} onClose={() => setViewProjectId(null)} projectId={viewProjectId} />
    </div>
  );
}
