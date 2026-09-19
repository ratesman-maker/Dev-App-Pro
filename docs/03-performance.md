# 03 - Rychlost

Rychlost je priorita číslo 3. Aplikace musí být rychlá v načítání, renderování i odezvě API.

---

## 1. Frontend rychlost

### 1.1 Vite build

**Produkce:** statický bundle, optimalizovaný a minifikovaný.

```typescript
// vite.config.ts
export default defineConfig({
  build: {
    outDir: 'assets/dist',         // PHP servíruje tento bundle
    sourcemap: false,              // žádné sourcemapy v produkci
    minify: 'esbuild',             // rychlejší než terser
    rollupOptions: {
      output: {
        manualChunks: {
          'react-vendor': ['react', 'react-dom', 'react-router-dom'],
          'ui-vendor': ['@radix-ui/react-dialog', '@radix-ui/react-dropdown-menu'],
        },
      },
    },
  },
});
```

### 1.2 Code splitting

- **Route-based splitting** - každá stránka je samostatný chunk
- React.lazy + Suspense pro lazy loading stránek

```typescript
const DashboardPage = lazy(() => import('./pages/DashboardPage'));
const ClientsPage = lazy(() => import('./pages/ClientsPage'));

<Suspense fallback={<LoadingSpinner />}>
  <Routes>
    <Route path="/" element={<DashboardPage />} />
    <Route path="/clients" element={<ClientsPage />} />
  </Routes>
</Suspense>
```

### 1.3 Asset optimalizace

| Asset | Optimalizace |
|---|---|
| JS | esbuild minifikace, tree-shaking, code splitting |
| CSS | Tailwind purge (pouze použité třídy), cssnano minifikace |
| Fonty | WOFF2, font-display: swap, subset (latin) |
| Ikony | Lucide jako samostatné importy (tree-shaking) |

### 1.4 Lucide ikony - tree shaking

```typescript
// SPRÁVNĚ - import pouze ikony, které potřebujeme
import { LayoutDashboard, Users, FolderKanban } from 'lucide-react';

// ŠPATNĚ - import celé knihovny
import * as Lucide from 'lucide-react';
```

Vite tree-shake odstraní nepoužité ikony z bundle.

### 1.5 Fonty

```css
/* @font-face - pouze potřebné váhy */
@font-face {
  font-family: 'Inter';
  font-weight: 400 500 600 700;
  font-display: swap;
  src: url('/fonts/Inter.woff2') format('woff2');
  /* subset: latin, latin-ext (čeština) */
}
```

- **font-display: swap** - text viditelný okamžitě (fallback font), pak swap
- **subset** - pouze latinské znaky (čeština + angličtina)
- **Jeden soubor** na rodinu (variable font) místo 4 souborů

### 1.6 Tailwind purge

```typescript
// tailwind.config.ts
content: [
  './src/**/*.{ts,tsx}',
  './index.html',
],
```

Tailwind vygeneruje pouze CSS třídy, které jsou v kódu. Výsledek: ~10-20KB CSS místo 3MB.

### 1.7 Caching

Apache cache hlavičky pro statické assety:

```apache
# assets/dist/ - dlouhá cache (hash v názvu souboru)
<FilesMatch "\.(js|css|woff2)$">
    Header set Cache-Control "public, max-age=31536000, immutable"
</FilesMatch>
```

- Vite přidává hash do názvu souboru (`app.abc123.js`)
- `immutable` - prohlížeč nikdy nevaliduje
- `max-age=31536000` - 1 rok

---

## 2. Backend rychlost

### 2.1 PHP opcache

```ini
; php.ini (produkce)
opcache.enable = 1
opcache.memory_consumption = 128
opcache.max_accelerated_files = 10000
opcache.validate_timestamps = 0   ; produkce - bez kontroly změn
opcache.revalidate_freq = 0
```

- **validate_timestamps = 0** v produkci - žádné stat() volání
- Při deploy: restart PHP-FPM nebo `opcache_reset()`

### 2.2 PDO persistent connections

```php
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_PERSISTENT => true,      // znovupoužití spojení
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,  // nativní prepared statements
]);
```

- **ATTR_EMULATE_PREPARES = false** - nativní prepared statements (rychlejší, bezpečnější)
- **PERSISTENT** - spojení se znovupoužije mezi requesty

### 2.3 Databázové indexy

```sql
-- Kritické indexy pro rychlé dotazy
CREATE INDEX idx_clients_name ON clients(name);
CREATE INDEX idx_projects_client_id ON projects(client_id);
CREATE INDEX idx_projects_status ON projects(status);
CREATE INDEX idx_tasks_project_id ON tasks(project_id);
CREATE INDEX idx_tasks_status ON tasks(status);
CREATE INDEX idx_tasks_due_date ON tasks(due_date);
CREATE INDEX idx_invoices_client_id ON invoices(client_id);
CREATE INDEX idx_invoices_status ON invoices(status);
CREATE INDEX idx_invoices_due_date ON invoices(due_date);
CREATE INDEX idx_invoice_payments_invoice_id ON invoice_payments(invoice_id);
CREATE INDEX idx_transactions_project_id ON transactions(project_id);
CREATE INDEX idx_transactions_type ON transactions(type);
CREATE INDEX idx_sessions_user_id ON sessions(user_id);
CREATE INDEX idx_login_attempts_ip_time ON login_attempts(ip_address, attempted_at);
```

### 2.4 N+1 dotazy

**Zakázáno.** Každý seznam musí načíst data jedním dotazem.

```php
// SPRÁVNĚ - JOIN
$stmt = $pdo->prepare(
    'SELECT p.*, c.name AS client_name
     FROM projects p
     LEFT JOIN clients c ON p.client_id = c.id
     ORDER BY p.created_at DESC
     LIMIT ?'
);

// ŠPATNĚ - N+1
$projects = $repo->all();
foreach ($projects as &$p) {
    $p['client'] = $clientRepo->find($p['client_id']);  // N dotazů!
}
```

### 2.5 Paginace

- Všechny seznamy paginované (default 50 na stránku)
- `LIMIT` a `OFFSET` v SQL
- React lazy-load další stránky (infinite scroll) nebo klasická paginace

```php
// API
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(100, max(1, (int)($_GET['per_page'] ?? 50)));
$offset = ($page - 1) * $perPage;
```

---

## 3. API rychlost

### 3.1 JSON response

- **JSON_UNESCAPED_UNICODE** - česká diakritika bez \uXXXX
- **JSON_UNESCAPED_SLASHES** - čistší output
- **Žádné zbytečné pole** - vracet jen potřebná data

```php
echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
```

### 3.2 HTTP caching

```apache
# API - žádná cache (dynamická data)
<FilesMatch "^api/">
    Header set Cache-Control "no-store, no-cache, must-revalidate"
</FilesMatch>
```

### 3.3 Komprese

```apache
# Gzip komprese pro textové odpovědi
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE application/json text/html text/css application/javascript
</IfModule>
```

### 3.4 Minimální payload

- Vracet jen pole, které klient potřebuje
- Nevracet `created_at`, `updated_at` pokud je klient nevyžaduje
- Pro seznamy: zkrácené pole, pro detail: plné pole

---

## 4. React render rychlost

### 4.1 Memoizace

```typescript
// useMemo pro drahé výpočty
const sortedClients = useMemo(
  () => clients.sort((a, b) => a.name.localeCompare(b.name)),
  [clients]
);

// useCallback pro handlery
const handleDelete = useCallback((id: number) => {
  deleteClient(id);
}, [deleteClient]);

// React.memo pro komponenty
const ClientRow = memo(({ client }: { client: Client }) => (
  <tr>...</tr>
));
```

### 4.2 Virtualizace dlouhých seznamů

Pro seznamy nad 100 položek:
- `@tanstack/react-virtual` pro virtualizovanou tabulku
- Renderuje pouze viditelné řádky

### 4.3 Debounce pro vyhledávání

```typescript
const debouncedSearch = useDebounce(searchQuery, 300);

useEffect(() => {
  if (debouncedSearch) {
    searchClients(debouncedSearch);
  }
}, [debouncedSearch]);
```

---

## 5. Měření rychlosti

### 5.1 Metriky

| Metrika | Cíl |
|---|---|
| First Contentful Paint | < 1s |
| Time to Interactive | < 2s |
| API response (list) | < 100ms |
| API response (detail) | < 50ms |
| Bundle size (JS) | < 200KB gzip |
| Bundle size (CSS) | < 30KB gzip |
| Font size | < 100KB total |

### 5.2 Nástroje

- **Lighthouse** - audit frontend výkonu
- **Apache Benchmark** - API propustnost
- **MySQL EXPLAIN** - analýza dotazů
- **Vite build report** - velikost bundle

```bash
# API test
ab -n 1000 -c 10 http://localhost/api/clients

# SQL analýza
EXPLAIN SELECT * FROM projects WHERE client_id = 1 ORDER BY created_at DESC;
```

---

## 6. Pravidla rychlosti

1. **Lokální assety** - žádné CDN, žádné externí fonty
2. **Minimální bundle** - tree-shaking, code splitting, purge
3. **Indexy** - každý WHERE/ORDER BY má index
4. **Žádné N+1** - JOIN místo smyčky dotazů
5. **Paginace** - žádné načítání 1000 záznamů najednou
6. **Cache** - statické assety s dlouhou cache, dynamická data bez cache
7. **Komprese** - gzip pro textové odpovědi
8. **opcache** - PHP bytecache v produkci
9. **Lazy loading** - stránky a komponenty načítány podle potřeby
10. **Debounce** - vyhledávání a API volání debouncována
11. **SELECT jen potřebných sloupců** - `SELECT *` zakázáno pro seznamy, OK pro detail
12. **Optimistic updates** - jen pro delete a update (ne create)
13. **Stale-while-revalidate** - stará data hned, nová na pozadí
14. **Skeleton loading** - šedé tvary místo prázdné obrazovky
15. **Prefetching** - jen při hover na odkaz (ne všechny routy najednou)
16. **Preload kritických resource** - fonty a CSS s prioritou
17. **Lazy obrázky** - `loading="lazy"` pro obrázky mimo viewport
18. **Stabilní reference** - keys, memo, useCallback zabraňují re-renderům

---

## 7. SELECT jen potřebných sloupců

**`SELECT *` zakázáno pro seznamy.** Pro detail je povoleno.

```php
// SPRÁVNĚ - seznam (zkrácené pole, výčet sloupců)
$stmt = $pdo->prepare(
    'SELECT id, name, email, phone, created_at
     FROM clients
     ORDER BY name LIMIT ? OFFSET ?'
);

// AKCEPTOVATELNÉ - detail (plné pole, SELECT * OK pro jeden záznam)
$stmt = $pdo->prepare('SELECT * FROM clients WHERE id = ?');

// ŠPATNĚ - SELECT * pro seznam
$stmt = $pdo->query('SELECT * FROM clients LIMIT 50');
```

Důvody:
- Menší payload přes síť (seznamy)
- Méně paměti v PHP i prohlížeči (seznamy)
- Pro detail je `SELECT *` OK - jeden záznam, vracíme vše
- Lze použít DB covering index (seznamy)

---

## 8. Optimalizace obrázků

### 8.1 Povolené formáty

**Výsledné uložené formáty:** pouze **PNG** a **WebP**.

**Na vstupu povoleny:** PNG, WebP, JPG. JPG se při uploadu **konvertuje na WebP**
(menší velikost, steřídá kvalita). Ostatní formáty (GIF, BMP, SVG jako obrázek)
se odmítnou.

```php
$inputImageTypes = ['image/png', 'image/webp', 'image/jpeg'];
$storedImageTypes = ['image/png', 'image/webp'];

if (!in_array($file['mime_type'], $inputImageTypes)) {
    // Odmítnout - nepodporovaný typ
    http_response_code(422);
    exit;
}

// Pokud JPG → konvertovat na WebP při uložení
if ($file['mime_type'] === 'image/jpeg') {
    convert_to_webp($sourcePath, $storedPath, quality: 85);
    $file['mime_type'] = 'image/webp';
} else {
    // PNG a WebP uložit jak jsou
    move_uploaded_file($sourcePath, $storedPath);
}
```

### 8.2 Thumbnail generování

Při uploadu obrázku se generuje thumbnail:

```php
function generate_thumbnail(string $sourcePath, string $thumbPath, int $maxWidth = 200, int $maxHeight = 200): bool
{
    $info = getimagesize($sourcePath);
    if (!$info) return false;

    $srcWidth = $info[0];
    $srcHeight = $info[1];
    $mime = $info['mime'];

    // Vytvořit zdroj
    if ($mime === 'image/png') {
        $src = imagecreatefrompng($sourcePath);
    } elseif ($mime === 'image/webp') {
        $src = imagecreatefromwebp($sourcePath);
    } elseif ($mime === 'image/jpeg') {
        $src = imagecreatefromjpeg($sourcePath);
    } else {
        return false;
    }

    // Vypočítat poměr
    $ratio = min($maxWidth / $srcWidth, $maxHeight / $srcHeight);
    $newWidth = (int)($srcWidth * $ratio);
    $newHeight = (int)($srcHeight * $ratio);

    // Vytvořit thumbnail
    $thumb = imagecreatetruecolor($newWidth, $newHeight);
    imagecopyresampled($thumb, $src, 0, 0, 0, 0, $newWidth, $newHeight, $srcWidth, $srcHeight);

    // Uložit jako WebP (menší)
    imagewebp($thumb, $thumbPath, 85);
    imagedestroy($src);
    imagedestroy($thumb);
    return true;
}
```

### 8.3 Struktura souborů

```
storage/
└── 2026/09/
    ├── {uuid}.png           # originál (PNG nebo WebP, JPG konvertován na WebP)
    ├── {uuid}_thumb.webp     # thumbnail 200x200
    └── {uuid}_medium.webp   # střední 800x800
```

- **Originál:** PNG nebo WebP (jak nahrál uživatel)
- **Thumbnail:** WebP 200x200 (pro seznamy)
- **Medium:** WebP 800x800 (pro náhledy)

### 8.4 Lazy loading obrázků

```tsx
<img
  src={file.thumbUrl}
  loading="lazy"        // načítat až ve viewport
  decoding="async"      // asynchronní dekódování
  alt={file.originalName}
/>
```

---

## 9. Prefetching rout

React Router prefetch při hover na odkaz - načte chunk dřív než uživatel klikne.

```tsx
import { Link, useNavigate } from 'react-router-dom';

// Prefetch při hover
function PrefetchLink({ to, children, ...props }) {
  const navigate = useNavigate();

  const handleHover = () => {
    // Načíst chunk routy předem
    import(`./pages/${to.charAt(1).toUpperCase() + to.slice(2)}Page`);
  };

  return (
    <Link to={to} onMouseEnter={handleHover} {...props}>
      {children}
    </Link>
  );
}
```

Alternativa - prefetch při hover na odkaz v sidebaru:

```tsx
// Sidebar - prefetch při hover na odkaz
function SidebarLink({ to, label, icon: Icon }) {
  const handleHover = () => {
    // Načíst chunk routy předem (jen tato routa, ne všechny)
    import(`./pages/${label}Page`);
  };

  return (
    <Link to={to} onMouseEnter={handleHover}>
      <Icon /> {label}
    </Link>
  );
}
```

**Nepoužívat** prefetch všech rout po prvním paintu - zbytečně stahuje
chunky, které uživatel možná nikdy nepoužije.

---

## 10. Skeleton loading

Místo spinneru - šedé tvary ve tvaru obsahu. Lepší perceived performance.

```tsx
// src/components/shared/Skeleton.tsx
function Skeleton({ className }: { className?: string }) {
  return <div className={cn('animate-pulse rounded-md bg-muted', className)} />;
}

// Klientská tabulka - skeleton
function ClientsTableSkeleton() {
  return (
    <div className="space-y-3">
      {Array.from({ length: 8 }).map((_, i) => (
        <div key={i} className="flex items-center gap-4">
          <Skeleton className="h-4 w-8" />      {/* ID */}
          <Skeleton className="h-4 w-40" />     {/* Jméno */}
          <Skeleton className="h-4 w-32" />     {/* Email */}
          <Skeleton className="h-4 w-24" />     {/* Telefon */}
          <Skeleton className="h-8 w-20" />     {/* Akce */}
        </div>
      ))}
    </div>
  );
}

// Použití
{isLoading ? <ClientsTableSkeleton /> : <ClientsTable data={clients} />}
```

---

## 11. Optimistic updates

React Query optimistic updates - UI reaguje okamžitě, server potvrzuje.
**Pouze pro delete a update.** Pro create ne - musíme počkat na reálné ID ze serveru.

```tsx
function useDeleteClient() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (id: number) => api.delete(`/clients/${id}`),

    // Optimistic update - okamžitě odstranit z cache
    onMutate: async (id: number) => {
      await qc.cancelQueries({ queryKey: ['clients'] });
      const previous = qc.getQueryData(['clients']);

      qc.setQueryData(['clients'], (old) => ({
        ...old,
        data: old.data.filter((c) => c.id !== id),
      }));

      return { previous };
    },

    // Při chybě - vrátit zpět
    onError: (_err, _id, context) => {
      qc.setQueryData(['clients'], context.previous);
    },

    // Po úspěchu - refetch pro jistotu
    onSettled: () => {
      qc.invalidateQueries({ queryKey: ['clients'] });
    },
  });
}
```

---

## 12. Stale-while-revalidate

TanStack Query caching - stará data hned, nová na pozadí.

```tsx
function useClients(params?: { search?: string; page?: number }) {
  return useQuery({
    queryKey: ['clients', params],
    queryFn: () => api.get('/clients?' + new URLSearchParams(params)),
    staleTime: 30 * 1000,        // 30s - data považována za čerstvá
    cacheTime: 5 * 60 * 1000,    // 5 min - cache v paměti
    refetchOnWindowFocus: true,  // refetch při návratu do okna
    refetchOnReconnect: true,    // refetch při obnovení připojení
  });
}
```

- **staleTime 30s** - do 30s se nerefetchnuje při přechodu mezi stránkami
- **Stará data se zobrazí okamžitě**, na pozadí se fetchne nová verze
- **refetchOnWindowFocus** - při návratu do tabu se data aktualizují

---

## 13. Bundle analýza

`rollup-plugin-visualizer` - vizualizace bundle, odhalení zbytečně velkých závislostí.

```bash
npm install -D rollup-plugin-visualizer
```

```typescript
// vite.config.ts
import { visualizer } from 'rollup-plugin-visualizer';

export default defineConfig({
  plugins: [
    react(),
    visualizer({
      filename: 'bundle-report.html',
      gzipSize: true,
      brotliSize: true,
    }),
  ],
});
```

Po `npm run build` se otevře `bundle-report.html` - stromový graf velikostí modulů. Odhalí:
- Zbytečně velké závislosti
- Duplikované kód
- Nepoužité exporty

---

## 14. PHP-FPM tuning

Pro lokální aplikaci - zamezení fork bomb při souběžných requestech.

```ini
; /etc/php/8.3/fpm/pool.d/www.conf
pm = dynamic
pm.max_children = 10          ; lokální - 10 stačí
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 6
pm.max_requests = 500         ; restart worker po 500 requestech (zamezí memory leak)
```

- **max_children = 10** - lokální aplikace, 10 souběžných requestů je dost
- **max_requests = 500** - restart worker pro prevenci memory leaků
- **pm = dynamic** - dynamické vytváření workerů podle potřeby

---

## 15. MySQL konfigurace

Pro lokální aplikaci s malou DB - celá se vejde do RAM.

```ini
; /etc/mysql/mariadb.conf.d/50-server.cnx
[mysqld]
innodb_buffer_pool_size = 256M    # celá DB do RAM
innodb_log_file_size = 64M
innodb_flush_log_at_trx_commit = 2 # rychlejší commit (mírně rizikové, OK lokálně)
innodb_flush_method = O_DIRECT
query_cache_type = 0               # vypnout query cache (zbytečná s InnoDB)
query_cache_size = 0
max_connections = 20                # lokální - 20 stačí
```

- **innodb_buffer_pool_size = 256M** - celá DB v RAM, extrémně rychlé čtení
- **innodb_flush_log_at_trx_commit = 2** - rychlejší zápisy (lokální kompromis)
- **max_connections = 20** - lokální aplikace, 20 je dost

---

## 16. Preload kritických resource

`<link rel="preload">` pro fonty a kritický CSS - prohlížeč začne stahovat dřív.

```html
<!-- index.html -->
<head>
  <!-- Preload fontů -->
  <link rel="preload" href="/fonts/Inter.woff2" as="font" type="font/woff2" crossorigin>

  <!-- Preload kritického CSS -->
  <link rel="preload" href="/assets/dist/assets/app.[hash].css" as="style">

  <!-- Preload API (warmup) -->
  <link rel="preload" href="/api/auth/me" as="fetch" crossorigin>
</head>
```

- **Preload fontu** - prohlížeč začne stahovat dřív než CSS ho vyžádá
- **Preload CSS** - kritický CSS se stáhne paralelně s HTML
- **Preload API** - warmup session check dřív než React hydratuje

---

## 17. Zamezení zbytečných re-renderů

### 17.1 Stabilní keys

```tsx
// SPRÁVNĚ - stabilní key (ID)
{clients.map((client) => (
  <ClientRow key={client.id} client={client} />
))}

// ŠPATNĚ - index jako key (při smazání se vše překreslí)
{clients.map((client, index) => (
  <ClientRow key={index} client={client} />
))}
```

### 17.2 State colocation

```tsx
// SPRÁVNĚ - state u komponenty, která ho používá
function ClientSearch() {
  const [query, setQuery] = useState('');
  return <input value={query} onChange={(e) => setQuery(e.target.value)} />;
}

// ŠPATNĚ - state v rodiči, který ho nepotřebuje (překreslí celý strom)
function ClientsPage() {
  const [query, setQuery] = useState('');  // nepoužívá přímo
  return (
    <>
      <ClientSearch query={query} setQuery={setQuery} />
      <ClientTable />  {/* překreslí se při každém znaku */}
    </>
  );
}
```

### 17.3 React.memo pro drahé komponenty

```tsx
// Řádek tabulky - memo, překreslí se jen při změně klienta
const ClientRow = memo(({ client }: { client: Client }) => (
  <tr>
    <td>{client.name}</td>
    <td>{client.email}</td>
  </tr>
));

// Celá tabulka - memo, překreslí se jen při změně dat
const ClientsTable = memo(({ clients }: { clients: Client[] }) => (
  <table>
    <tbody>
      {clients.map((c) => <ClientRow key={c.id} client={c} />)}
    </tbody>
  </table>
));
```

### 17.4 useCallback pro handlery

```tsx
// SPRÁVNĚ - stabilní reference
const handleDelete = useCallback((id: number) => {
  deleteClient(id);
}, [deleteClient]);

// ŠPATNĚ - nová funkce při každém renderu
function ClientsPage() {
  const handleDelete = (id: number) => {  // nová při každém renderu
    deleteClient(id);
  };
}
```
