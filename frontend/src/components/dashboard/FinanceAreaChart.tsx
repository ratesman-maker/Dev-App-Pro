import { Area, AreaChart, CartesianGrid, XAxis } from 'recharts';
import { ChartContainer, ChartTooltip, ChartTooltipContent, type ChartConfig } from '@/components/ui/chart';
import { fmtMoney } from '@/lib/utils';

// === Graf příjmů a výdajů (shadcn chart-area) ===
// Samostatný modul: recharts (většina bundlu nástěnky) se stáhne až při zobrazení grafu.
// Klíče configu musí odpovídat datovým položkám (income_cents / expense_cents).
// Barvy se definují přes CSS třídu .chart-finance (CSP style-src 'self').
const chartConfig = {
  income_cents: { label: 'Příjmy', color: 'var(--color-income)' },
  expense_cents: { label: 'Výdaje', color: 'var(--color-expense)' },
} satisfies ChartConfig;

interface FinanceChartPoint {
  month: string;
  income_cents: number;
  expense_cents: number;
}

export default function FinanceAreaChart({ data }: { data: FinanceChartPoint[] }) {
  return (
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
  );
}
