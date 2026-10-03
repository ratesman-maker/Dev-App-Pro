---
name: shadcn
description: "Práce se shadcn/ui v Dev App Pro — přidávání komponent přes CLI, pravidla kompozice, sémantické barvy, Tailwind konvence. Použij při práci s components/ui/, přidávání nových komponent nebo shadcn úpravách."
---

# shadcn/ui v Dev App Pro

Komponenty se přidávají jako zdrojový kód přes CLI — ne jako dependency.

## Kontext projektu

```bash
cd frontend && npx shadcn@latest info --json   # config + nainstalované komponenty
npx shadcn@latest docs <komponenta>            # dokumentace + příklady
npx shadcn@latest add <komponenta>             # přidat komponentu
npx shadcn@latest search <dotaz>               # hledat v registrech
```

`components.json`: style **new-york**, baseColor **zinc**, `rsc: false` (SPA),
alias `@/` = `src/` (`@/components`, `@/lib/utils`).

## Instalovaná sada (minimální — rozšiřovat přes CLI)

`badge, button, card, chart, dialog, input, label, select, table`
+ vlastní: `toast.tsx` (ToastContext — `useToast`, NENÍ sonner/shadcn toast),
`PageSkeleton.tsx`
+ shared: `DataTable, DetailModal, ConfirmDialog, AttachmentSelect, EmptyState`

**Novější shadcn primitivy nemáme** (FieldGroup, InputGroup, Empty, Spinner,
ToggleGroup, Tabs, Sonner…) — když je potřeba, nejdřív `npx shadcn add`, pak
použít. Vlastní `EmptyState`/`PageSkeleton`/`toast` mají přednost před novými
ekvivalenty (konzistence).

## Principy

1. **Reuse před vlastním markup** — nejdřív `shared/` komponenty, pak
   `components/ui/`, až potom vlastní kód. Empty state = náš `EmptyState`,
   loading = `PageSkeleton`/skeleton řádky, confirm = `ConfirmDialog`.
2. **Kompozice, ne reinvent** — settings = Card + formulářové prvky;
   detail = DetailModal; seznam = DataTable.
3. **Varianty před custom styly** — `variant="outline"`, `size="sm"`.
4. **Sémantické barvy vždy** — `bg-primary`, `text-muted-foreground`,
   `bg-background` — **nikdy** raw `bg-blue-500`, `text-emerald-600`.
   Dark mode je default — sémantické tokeny se přepnou samy.

## Tvrdá pravidla

- **`className` pro layout, ne styling** — nepřepisovat barvy/typografii komponent
- **`gap-*`, ne `space-x-*`/`space-y-*`** — `flex flex-col gap-4`
- **`size-*`** když width=height — `size-10`, ne `w-10 h-10`
- **`truncate`** — ne `overflow-hidden text-ellipsis whitespace-nowrap`
- **`cn()`** pro podmíněné třídy — ne ruční template literal ternáře
- **`Dialog` vždy s `DialogTitle`** (a11y) — `className="sr-only"` pokud skrytý
- **Ikona v `Button` = `data-icon`** (`data-icon="inline-start"`/`"inline-end"`),
  bez size tříd na ikoně
- **Items vždy ve své Group** — `SelectItem` → `SelectGroup` apod.
- **`asChild`** pro custom triggery místo obalování divem
- **Plná Card kompozice** — `CardHeader/CardTitle/CardDescription/CardContent/CardFooter`
- Žádný vlastní `z-index` na overlay komponentách (Dialog/Select řeší sám)
- Toast: `useToast()` z našeho `toast.tsx` — `toast.success(title, msg)` česky

## Klíčové vzory

```tsx
// Formulář: Label + Input spárované, chyby inline s aria-invalid
<div className="flex flex-col gap-2">
  <Label htmlFor="email">E-mail</Label>
  <Input id="email" aria-invalid={!!errors.email} />
  {errors.email && <p role="alert" className="text-sm text-destructive">{errors.email}</p>}
</div>

// Ikony v buttonu
<Button><SearchIcon data-icon="inline-start" />Hledat</Button>

// Stav barvy: Badge variant nebo sémantický token
<Badge variant="secondary">+20,1 %</Badge>
```

## Interakce s projektovými pravidly

Projektová pravidla mají přednost před shadcn defaulty:
- **Žádné `shadow-*`/`box-shadow`** i když shadcn komponenta je má —
  při `npx shadcn add` po instalaci shadcn utility třídy `shadow` odebrat
- Žádné inline styly (CSP) — dynamické hodnoty přes CSS proměnné
- Texty česky, formáty přes `fmt*` helpery

Zdroj: adaptováno z oficiálního `shadcn-ui/ui` skillu.
