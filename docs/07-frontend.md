# 07 - Frontend

React 19 + Vite 7 + shadcn/ui + Tailwind CSS 4 + Linear theme. Vše lokální, žádné CDN.

---

## 1. Stack

| Nástroj | Verze | Účel |
|---|---|---|
| React | 19 | UI knihovna |
| React Router | 7 | Client-side routing |
| Vite | 7 | Build tool + dev server |
| TypeScript | 5.7 | Typování |
| Tailwind CSS | 4 | Styling |
| shadcn/ui | latest | Komponenty (lokální, copy-paste) |
| Lucide React | latest | Ikony (tree-shaking) |
| TanStack Table | 8 | Data tabulky |
| TanStack Query | 5 | Data fetching / cache |

---

## 2. shadcn/ui integrace

### 2.1 Princip

shadcn/ui komponenty nejsou npm balíček. Jsou to **kopírované zdrojové kódy** do `frontend/src/components/ui/`. Každou komponentu lze upravit.

### 2.2 Instalace

```bash
cd frontend
npx shadcn@latest init
# Theme: Linear
# CSS variables: yes
# Base color: Zinc
# React Server Components: no (SPA)
```

### 2.3 components.json

```json
{
  "$schema": "https://ui.shadcn.com/schema.json",
  "style": "new-york",
  "rsc": false,
  "tsx": true,
  "tailwind": {
    "config": "tailwind.config.ts",
    "css": "src/styles/globals.css",
    "baseColor": "zinc",
    "cssVariables": true,
    "prefix": ""
  },
  "aliases": {
    "components": "@/components",
    "utils": "@/lib/utils",
    "ui": "@/components/ui",
    "lib": "@/lib",
    "hooks": "@/hooks"
  }
}
```

### 2.4 Instalace komponent

```bash
npx shadcn@latest add button card dialog dropdown-menu input label table
npx shadcn@latest add badge avatar separator sheet tabs toast tooltip
npx shadcn@latest add select checkbox textarea popover command
```

---

## 3. Linear theme

### 3.1 CSS proměnné

```css
/* src/styles/globals.css */
@import "tailwindcss";

:root {
  --background: 0 0% 100%;
  --foreground: 240 10% 4%;
  --card: 0 0% 100%;
  --card-foreground: 240 10% 4%;
  --popover: 0 0% 100%;
  --popover-foreground: 240 10% 4%;
  --primary: 231 84% 64%;            /* #6e78d5 - violet-indigo */
  --primary-foreground: 0 0% 100%;
  --secondary: 240 5% 96%;
  --secondary-foreground: 240 6% 10%;
  --muted: 240 5% 96%;
  --muted-foreground: 240 4% 46%;
  --accent: 240 5% 96%;
  --accent-foreground: 240 6% 10%;
  --destructive: 0 72% 51%;
  --destructive-foreground: 0 0% 100%;
  --border: 240 6% 90%;
  --input: 240 6% 90%;
  --ring: 231 84% 64%;
  --radius: 0.5rem;
}

.dark {
  --background: 240 10% 4%;        /* #08090a */
  --foreground: 0 0% 95%;
  --card: 240 10% 6%;
  --card-foreground: 0 0% 95%;
  --popover: 240 10% 6%;
  --popover-foreground: 0 0% 95%;
  --primary: 0 0% 98%;               /* near-white v dark mode */
  --primary-foreground: 240 10% 4%;
  --secondary: 240 6% 14%;
  --secondary-foreground: 0 0% 95%;
  --muted: 240 6% 14%;
  --muted-foreground: 240 5% 65%;
  --accent: 240 6% 14%;
  --accent-foreground: 0 0% 95%;
  --destructive: 0 72% 51%;
  --destructive-foreground: 0 0% 95%;
  --border: 240 6% 18%;
  --input: 240 6% 18%;
  --ring: 231 84% 64%;
}
```

### 3.2 Fonty

```css
@font-face {
  font-family: 'Inter';
  font-weight: 400 500 600 700;
  font-display: swap;
  src: url('/fonts/Inter.woff2') format('woff2');
  unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+2074, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
}
```

- Inter (variable font) - body, UI
- JetBrains Mono - kód, monospace
- **Lokální WOFF2** v `frontend/public/fonts/`
- **font-display: swap**
- **Subset** latin + latin-ext (čeština)

---

## 4. App shell

### 4.1 Layout

```
┌─────────────────────────────────────────────────┐
│ ┌────────┐ ┌──────────────────────────────────┐ │
│ │        │ │  Topbar (h-14)                    │ │
│ │        │ │  [☰] [Breadcrumbs]    [☀] [User] │ │
│ │ Sidebar│ ├──────────────────────────────────┤ │
│ │ (w-64) │ │                                    │ │
│ │        │ │  Content (scrollable)              │ │
│ │ [Dash] │ │                                    │ │
│ │ [Clie] │ │  ┌──────────────────────────┐     │ │
│ │ [Proj] │ │  │  Page content             │     │ │
│ │ [Task] │ │  │                           │     │ │
│ │ [Fin]  │ │  └──────────────────────────┘     │ │
│ │ [Set]  │ │                                    │ │
│ │        │ │                                    │ │
│ └────────┘ └──────────────────────────────────┘ │
└─────────────────────────────────────────────────┘
```

### 4.2 Sidebar

```tsx
// src/components/layout/Sidebar.tsx
import { NavLink } from 'react-router-dom';
import {
  LayoutDashboard, Users, FolderKanban, ListTodo, DollarSign,
  StickyNote, Paperclip, Settings,
} from 'lucide-react';

const navItems = [
  { to: '/', label: 'Dashboard', icon: LayoutDashboard },
  { to: '/clients', label: 'Klienti', icon: Users },
  { to: '/projects', label: 'Projekty', icon: FolderKanban },
  { to: '/tasks', label: 'Úkoly', icon: ListTodo },
  { to: '/finance', label: 'Finance', icon: DollarSign },
  { to: '/notes', label: 'Poznámky', icon: StickyNote },
  { to: '/files', label: 'Soubory', icon: Paperclip },
  { to: '/settings', label: 'Nastavení', icon: Settings },
];

export function Sidebar() {
  return (
    <aside className="w-64 shrink-0 border-r bg-sidebar">
      <div className="flex h-14 items-center gap-2 border-b px-4">
        <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary text-primary-foreground">
          <Code className="h-5 w-5" />
        </span>
        <span className="font-semibold tracking-tight">Dev App Pro</span>
      </div>
      <nav className="space-y-1 p-2">
        {navItems.map(({ to, label, icon: Icon }) => (
          <NavLink
            key={to}
            to={to}
            end={to === '/'}
            className={({ isActive }) =>
              cn(
                'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition',
                isActive
                  ? 'bg-sidebar-accent text-sidebar-accent-foreground'
                  : 'text-sidebar-foreground hover:bg-sidebar-accent'
              )
            }
          >
            <Icon className="h-5 w-5" />
            {label}
          </NavLink>
        ))}
      </nav>
    </aside>
  );
}
```

### 4.3 Topbar

```tsx
// src/components/layout/Topbar.tsx
import { PanelLeft, Sun, Moon } from 'lucide-react';
import { useTheme } from '@/hooks/useTheme';
import { UserMenu } from './UserMenu';

export function Topbar() {
  const { theme, toggle } = useTheme();
  return (
    <header className="flex h-14 items-center justify-between border-b px-6">
      <Button variant="ghost" size="icon" onClick={toggleSidebar}>
        <PanelLeft className="h-5 w-5" />
      </Button>
      <div className="flex items-center gap-2">
        <Button variant="ghost" size="icon" onClick={toggle}>
          {theme === 'dark' ? <Sun className="h-5 w-5" /> : <Moon className="h-5 w-5" />}
        </Button>
        <UserMenu />
      </div>
    </header>
  );
}
```

---

## 5. Stránky

### 5.0 Lokalizace a formátování

Veškeré zobrazení dat v českém formátu:

**Datum:** `26.3.2026` (den.měsíc.rok, bez úvodních nul)
```ts
// src/lib/utils.ts
function fmtDate(date: string | Date): string {
  const d = new Date(date);
  return `${d.getDate()}.${d.getMonth() + 1}.${d.getFullYear()}`;
}

// Datum + čas: 26.3.2026 14:30
function fmtDateTime(date: string | Date): string {
  const d = new Date(date);
  return `${fmtDate(d)} ${d.getHours()}:${String(d.getMinutes()).padStart(2, '0')}`;
}
```

**Měna:** `1 500 Kč` (číslo, mezera, Kč - bez desetinných míst)
```ts
function fmtMoney(cents: number): string {
  const crowns = Math.round(cents / 100); // zaokrouhlení na celé Kč
  return crowns.toLocaleString('cs-CZ') + ' Kč';
  // 150000 → "1 500 Kč"
  // 150045 → "1 500 Kč" (bez haléřů)
}
```

**Čas (minuty):** `4h 0m` nebo `3h 35m` nebo `0m`
```ts
function fmtMinutes(minutes: number): string {
  if (minutes === 0) return '0m';
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  if (h === 0) return `${m}m`;
  if (m === 0) return `${h}h`;
  return `${h}h ${m}m`;
}
```

**Jméno klienta:** vždy `first_name last_name` (např. "Jan Novák")
```ts
function fmtClientName(firstName: string, lastName: string): string {
  return `${firstName} ${lastName}`;
}
```

**Všechna data v UI:**
- Tabulky, detaily, formuláře, exporty
- Input type="date" zůstává ISO (HTML standard), zobrazení je české
- API vrací ISO 8601 (`2026-03-26T14:30:00Z`), frontend formátuje

### 5.1 Dashboard

- 4 KPI karty (Klienti, Projekty, Aktivní projekty, Úkoly)
- Finanční přehled (zaplaceno, otevřené, po splatnosti)
- Stav úkolů (progress bary)
- Projekty dle statusu
- Rychlé akce
- Poslední projekty (seznam)
- Nadcházející úkoly (seznam)

### 5.2 Klienti

- DataTable (řazení, vyhledávání, paginace)
- Sloupce: Jméno (first_name last_name nebo company_name), Typ (osoba/firma), IČO, Email, Telefon, Projekty, Faktury, Akce
- Řazení primárně podle příjmení (osoby) nebo názvu firmy (firmy)
- Vytvořit/Upravit dialog:
  - Přepínač typu: Osoba / Firma
  - Osoba: first_name, last_name
  - Firma: company_name, ico, dic, bank_account
  - Společné: email, phone, address, note
- Detail klienta (projekty, faktury, transakce)
- Akce v dropdown menu (MoreHorizontal):
  - Zobrazit (odkaz na detail)
  - Upravit (dialog)
  - Smazat (confirm dialog, červené tlačítko) - trvalé, varování

### 5.3 Projekty

- DataTable nebo karty
- Filtr: klient, status (včetně archived)
- Sloupce: Název, Klient, Status, Rozpočet, Termín, Úkoly, Zdroj (složka/ruční), Akce
- Detail projektu (úkoly, faktury, transakce)
- Badge "Ze složky" pro projekty s `folder_path`
- Archivované projekty (složka smazána) zobrazené odlišně (šedý badge)
- Akce v dropdown menu (MoreHorizontal):
  - Zobrazit (odkaz na detail)
  - Upravit (dialog)
  - Archivovat (confirm dialog) - pouze pro ne-archivované
  - Obnovit (confirm dialog) - pouze pro archivované
  - Smazat (confirm dialog, červené tlačítko) - trvalé, varování

### 5.4 Úkoly

- DataTable
- Filtr: projekt, status, priorita, po termínu
- Sloupce: Název, Projekt, Status, Priorita, Termín, Odhad, Stráveno, Akce
- Inline editace statusu
- Zobrazení času: `4h 0m` (odhad), `3h 35m` (stráveno), nebo `0m` pokud 0
- Akce v dropdown menu (MoreHorizontal):
  - Zobrazit (odkaz na detail nebo modal)
  - Upravit (dialog)
  - Smazat (confirm dialog) - trvalé

### 5.5 Finance

- Taby: Faktury, Platby, Transakce
- Faktury: DataTable s status baret
  - Sloupce: Číslo, Klient, Vystavena, Splatnost, Bez DPH, DPH, Celkem, Zaplaceno, Status, Akce
  - Zobrazení částek: `1 240 Kč` (bez DPH), `260 Kč` (DPH), `1 500 Kč` (celkem)
  - VS a KS zobrazeny v detailu
  - Akce: Zobrazit, Upravit, Smazat (confirm, kaskáda s platbami)
- Platby: seznam plateb s odkazem na fakturu
  - Sloupce: Faktura, Datum, Částka, Metoda, Poznámka, Akce
  - Akce: Upravit, Smazat (confirm, přepočet faktury)
- Transakce: příjmy vs výdaje, filtr podle data a kategorie
  - Sloupce: Datum, Typ (příjem/výdaj), Kategorie, Popis, Částka, Projekt, Klient, Akce
  - Filtr kategorie: kancelář, software, cestovné, marketing, hardware, služby, příjem-projekt, konzultace
  - Akce: Zobrazit, Upravit, Smazat (confirm)
- Finanční souhrn: celkem zaplaceno, otevřené, po splatnosti

### 5.6 Nastavení

Taby: Profil, Firma, Aplikace, Vzhled

**Profil (uživatel):**
- Jméno, email, změna hesla
- Nápověda pro reset hesla (password_hint) - textové pole
- Preference: téma (světlý/tmavý), sidebar (rozbalený/sbalený), počet na stránku

**Firma (prodávající pro faktury):**
- Typ: Osoba / Firma
- Osoba: first_name, last_name
- Firma: company_name, ico, dic
- Společné: email, phone, address, bank_account, iban, swift

**Aplikace:**
- Výchozí sazba DPH (21%)
- Výchozí splatnost (14 dní)
- Formát číslování faktur
- Měna (CZK), desetinná místa (0)
- Timezone (Europe/Prague)
- První den týdne (pondělí)
- Fiskální rok (01-01)

**Vzhled:**
- Přepínač světlý/tmavý režim (uloženo v users.theme)
- Sidebar rozbalený/sbalený (uloženo v users.sidebar_collapsed)
- Počet položek na stránku (uloženo v users.per_page)
- Živý preview změn

**Informace o aplikaci:**
- Verze, build datum
- O aplikaci

### 5.7 Poznámky

- DataTable (řazení, vyhledávání, paginace)
- Sloupce: Nadpis, Obsah (zkrácený), Vazby, Vytvořeno, Akce
- Filtr: podle entity_type (klient, projekt, úkol, faktura)
- Vytvořit/Upravit dialog s polem pro vazby (multi-select entit)
- Zobrazení vazeb jako badge (ikona + název entity)
- Akce v dropdown menu (MoreHorizontal):
  - Zobrazit (modal s plným obsahem)
  - Upravit (dialog)
  - Smazat (confirm dialog) - trvalé

### 5.8 Soubory

- DataTable nebo grid náhledů
- Sloupce: Název, Typ (ikona), Velikost, Vazby, Nahráno, Akce
- Grid náhledů: obrázky s thumbnail_path, ostatní s ikonou
- Filtr: podle entity_type (klient, projekt, úkol, faktura), is_image
- Upload dialog (drag & drop, multi-upload)
- Náhled obrázků (PNG/WebP) přes thumbnail_path nebo medium_path
- Stahování přes PHP endpoint
- Zobrazení vazeb jako badge (ikona + název entity)
- Akce v dropdown menu (MoreHorizontal):
  - Stáhnout (přes PHP endpoint)
  - Upravit (dialog - název, vazby)
  - Smazat (confirm dialog, červené tlačítko) - trvalé, smazán i fyzický soubor

### 5.9 Login

- Centrovaný formulář
- Logo + nadpis
- Uživatelské jméno + heslo
- Chybová zpráva (alert)
- Po úspěchu: redirect na Dashboard
- **Přepínač světlý/tmavý režim** (ikona slunce/měsíce v rohu)
  - Uloženo v `localStorage('theme')` (před přihlášením neznáme user_id)
  - Po přihlášení se synchronizuje s `users.theme` z DB
  - Výchozí: tmavý (pokud localStorage je prázdné)
- **Odkaz "Zapomněli jste heslo?"** pod formulářem
  - Klik → přepne na reset formulář (stejná stránka, jiný stav)
  - Reset formulář:
    1. Krok 1: Zadat username → zobrazit hint (nebo "Bez hintu")
    2. Krok 2: Nové heslo + potvrzení → odeslat → úspěch → zpět na login
  - Tlačítko "Zpět na přihlášení"

### 5.10 404

- Jednoduchá stránka
- Ikona + "Stránka nenalezena"
- Tlačítko zpět na Dashboard

---

## 6. Hooks

### 6.1 useAuth

```typescript
function useAuth() {
  const { data: user, isLoading } = useQuery({
    queryKey: ['auth', 'me'],
    queryFn: () => api.get('/auth/me'),
    retry: false,
  });
  return { user, isLoading };
}
```

### 6.2 useApi

```typescript
// src/lib/api.ts
const api = {
  async get<T>(path: string): Promise<T> {
    const res = await fetch(`/api${path}`, { credentials: 'same-origin' });
    if (!res.ok) throw new ApiError(res.status, await res.json());
    return res.json();
  },
  async post<T>(path: string, body: unknown): Promise<T> {
    const res = await fetch(`/api${path}`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': getCsrfToken(),
      },
      credentials: 'same-origin',
      body: JSON.stringify(body),
    });
    if (!res.ok) throw new ApiError(res.status, await res.json());
    return res.json();
  },
  // put, delete podobně
};
```

### 6.3 useClients

```typescript
function useClients(params?: { search?: string; page?: number }) {
  return useQuery({
    queryKey: ['clients', params],
    queryFn: () => api.get('/clients?' + new URLSearchParams(params)),
  });
}

function useCreateClient() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: ClientInput) => api.post('/clients', data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['clients'] }),
  });
}
```

---

## 7. Theme

### 7.1 useTheme hook

```typescript
function useTheme() {
  const [theme, setTheme] = useState<'light' | 'dark'>(() => {
    // 1. Zkusit localStorage (funguje před i po přihlášení)
    const stored = localStorage.getItem('devapppro-theme') as 'light' | 'dark' | null;
    if (stored) return stored;
    // 2. Výchozí: tmavý
    return 'dark';
  });

  // Aplikovat na <html> a uložit do localStorage
  useEffect(() => {
    document.documentElement.classList.toggle('dark', theme === 'dark');
    localStorage.setItem('devapppro-theme', theme);
  }, [theme]);

  // Po přihlášení: synchronizovat s DB (users.theme)
  const { user } = useAuth();
  useEffect(() => {
    if (user?.theme && user.theme !== theme) {
      setTheme(user.theme);
    }
  }, [user?.theme]);

  // Přepnutí: aktualizovat UI + localStorage + DB
  const toggle = useCallback(async () => {
    const next = theme === 'dark' ? 'light' : 'dark';
    setTheme(next);
    // Persist do DB (best-effort, neblokuje UI)
    if (user) {
      api.put('/api/users/me/preferences', { theme: next }).catch(() => {});
    }
  }, [theme, user]);

  return { theme, setTheme, toggle };
}
```

### 7.2 Chování tématu

**Před přihlášením (Login stránka):**
- Načte z `localStorage('devapppro-theme')`
- Pokud prázdné → výchozí **tmavý**
- Přepínač v rohu přihlašovací stránky (ikona slunce/měsíce)
- Uloží se do localStorage (ne do DB - neznáme user_id)

**Po přihlášení:**
- Načte `users.theme` z DB (přes `/api/users/me/preferences`)
- Synchronizuje localStorage s DB hodnotou
- Přepínač v Topbaru
- Při přepnutí: aktualizuje UI okamžitě, localStorage okamžitě, DB asynchronně

**Po odhlášení:**
- Téma zůstává v localStorage (příští přihlášení začne s poslední volbou)

**Výchozí hodnoty:**
- Nový uživatel: `users.theme = 'dark'` (DB default)
- První návštěva bez localStorage: **tmavý**
- Žádné sledování systémové preference (preferujeme explicitní volbu)

---

## 8. Ikony

```typescript
// Import pouze potřebných ikon (tree-shaking)
import {
  LayoutDashboard, Users, FolderKanban, ListTodo, DollarSign, Settings,
  Code, PanelLeft, Sun, Moon, ChevronDown, Plus, Pencil, Trash2,
  Search, Filter, MoreHorizontal, X, Check, AlertTriangle, Calendar,
} from 'lucide-react';
```

- **Lokální balíček** `lucide-react` (npm)
- **Tree-shaking** - pouze použité ikony v bundle
- **Velikost:** `className="h-5 w-5"` (Tailwind)
- **Stroke:** 1.5 (jemnější)

---

## 9. Build

### 9.1 Vývoj

```bash
cd frontend
npm install
npm run dev    # Vite dev server na :5173
```

### 9.2 Produkce

```bash
cd frontend
npm run build  # Vite build → assets/dist/
```

### 9.3 Vite config

```typescript
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: { '@': path.resolve(__dirname, './src') },
  },
  build: {
    outDir: '../assets/dist',
    emptyOutDir: true,
    sourcemap: false,
    minify: 'esbuild',
    rollupOptions: {
      output: {
        manualChunks: {
          'react-vendor': ['react', 'react-dom', 'react-router-dom'],
        },
      },
    },
  },
  server: {
    port: 5173,
    proxy: {
      '/api': 'http://localhost',
      '/fonts': 'http://localhost',
    },
  },
});
```
