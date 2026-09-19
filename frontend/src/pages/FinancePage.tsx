import { useState } from 'react';
import {
  DollarSign,
  Clock,
  AlertTriangle,
  TrendingUp,
  TrendingDown,
} from 'lucide-react';
import { cn, fmtMoney } from '@/lib/utils';
import { Card, CardContent } from '@/components/ui/card';
import {
  useDashboardFinance,
  TABS,
  type TabKey,
} from '@/components/finance/financeHelpers';
import InvoicesTab from '@/components/finance/InvoicesTab';
import PaymentsTab from '@/components/finance/PaymentsTab';
import TransactionsTab from '@/components/finance/TransactionsTab';
import OverviewTab from '@/components/finance/OverviewTab';

export default function FinancePage() {
  const [tab, setTab] = useState<TabKey>('overview');
  const { data: dashboard } = useDashboardFinance();
  const finance = dashboard?.finance;

  return (
    <div className="space-y-6 p-6">
      {/* Finanční souhrn */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <Card>
          <CardContent className="flex items-center gap-4 p-5">
            <div className="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10">
              <DollarSign className="h-6 w-6 text-primary" />
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Celkem zaplaceno</p>
              <p className="text-xl font-semibold">{fmtMoney(finance?.total_paid_cents ?? 0)}</p>
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="flex items-center gap-4 p-5">
            <div className="flex h-12 w-12 items-center justify-center rounded-full bg-green-500/10">
              <TrendingUp className="h-6 w-6 text-green-600" />
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Příjmy</p>
              <p className="text-xl font-semibold">{fmtMoney(finance?.total_income_cents ?? 0)}</p>
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="flex items-center gap-4 p-5">
            <div className="flex h-12 w-12 items-center justify-center rounded-full bg-red-500/10">
              <TrendingDown className="h-6 w-6 text-red-600" />
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Výdaje</p>
              <p className="text-xl font-semibold">{fmtMoney(finance?.total_expense_cents ?? 0)}</p>
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="flex items-center gap-4 p-5">
            <div className="flex h-12 w-12 items-center justify-center rounded-full bg-secondary/50">
              <Clock className="h-6 w-6 text-muted-foreground" />
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Otevřené</p>
              <p className="text-xl font-semibold">{fmtMoney(finance?.total_open_cents ?? 0)}</p>
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="flex items-center gap-4 p-5">
            <div className="flex h-12 w-12 items-center justify-center rounded-full bg-destructive/10">
              <AlertTriangle className="h-6 w-6 text-destructive" />
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Po splatnosti</p>
              <p className="text-xl font-semibold">
                {fmtMoney(finance?.overdue_cents ?? 0)}
                {finance && finance.overdue_count > 0 && (
                  <span className="ml-2 text-sm font-normal text-muted-foreground">
                    ({finance.overdue_count})
                  </span>
                )}
              </p>
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Taby */}
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

      {/* Obsah tabů */}
      {tab === 'overview' && <OverviewTab />}
      {tab === 'invoices' && <InvoicesTab />}
      {tab === 'payments' && <PaymentsTab />}
      {tab === 'transactions' && <TransactionsTab />}
    </div>
  );
}
