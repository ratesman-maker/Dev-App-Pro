# 04 - Architektura

PHP REST API + React SPA + Vite. Dvě nezávislé části komunikující přes HTTP JSON API.

---

## 1. Přehled

```
┌─────────────────────────────────────────────────────┐
│  Prohlížeč                                            │
│                                                       │
│  ┌─────────────────────────────────────────────────┐  │
│  │  React SPA (Vite build → assets/dist/)          │  │
│  │                                                  │  │
│  │  ┌──────────┐  ┌──────────┐  ┌──────────┐      │  │
│  │  │ Dashboard │  │ Clients   │  │ Projects │ ... │  │
│  │  └──────────┘  └──────────┘  └──────────┘      │  │
│  │       │             │             │             │  │
│  │       └─────────────┴─────────────┘             │  │
│  │                     │                            │  │
│  │              ┌──────▼──────┐                    │  │
│  │              │  API klient  │  fetch() + CSRF    │  │
│  │              └──────┬──────┘                    │  │
│  └─────────────────────┼───────────────────────────┘  │
└────────────────────────┼──────────────────────────────┘
                         │
                         │ HTTP (JSON)
                         ▼
┌─────────────────────────────────────────────────────┐
│  Apache (port 80)                                     │
│                                                       │
│  ┌─────────────────┐  ┌──────────────────────────────┐ │
│  │  Statické       │  │  PHP (mod_php / PHP-FPM)     │ │
│  │  assety         │  │                              │ │
│  │  assets/dist/*  │  │  ┌────────────────────────┐  │ │
│  │  (JS, CSS,      │  │  │  index.php (router)     │  │ │
│  │  fonty, ikony)  │  │  │  api/*.php (endpointy)  │  │ │
│  └─────────────────┘  │  └───────────┬────────────┘  │ │
│                       │              │                │ │
│                       │  ┌───────────▼────────────┐  │ │
│                       │  │  Controllers (PSR-4)    │  │ │
│                       │  └───────────┬────────────┘  │ │
│                       │              │                │ │
│                       │  ┌───────────▼────────────┐  │ │
│                       │  │  Repositories (PSR-4)  │  │ │
│                       │  └───────────┬────────────┘  │ │
│                       └──────────────┼────────────────┘ │
│                                      │                  │
│                       ┌──────────────▼────────────────┐ │
│                       │  MariaDB / MySQL               │ │
│                       └────────────────────────────────┘ │
└─────────────────────────────────────────────────────┘
```

---

## 2. Dva režimy běhu

### 2.1 Vývoj

```
Prohlížeč → Vite dev server (port 5173)
                │
                ├── /api/* → proxy → Apache (port 80) → PHP
                │
                └── /*     → React HMR (Hot Module Replacement)
```

```typescript
// vite.config.ts
export default defineConfig({
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://localhost',
        changeOrigin: true,
      },
      '/fonts': {
        target: 'http://localhost',
        changeOrigin: true,
      },
    },
  },
});
```

- Vite dev server běží na `http://localhost:5173`
- React HMR - změny se projeví okamžitě bez refresh
- API požadavky proxy na Apache (port 80)
- Fonty proxy na Apache (port 80)

### 2.2 Produkce

```
Prohlížeč → Apache (port 80)
                │
                ├── /api/*      → PHP (mod_php / PHP-FPM)
                │
                ├── /assets/*   → statické soubory (JS, CSS, fonty)
                │
                └── /*          → index.html (React SPA)
```

- Vite build produkuje `assets/dist/` (JS, CSS)
- Apache servíruje statické assety přímo
- Apache servíruje `index.html` pro všechny ostatní routy (React Router)
- PHP API endpointy na `/api/*`

---

## 3. Apache konfigurace

### 3.1 Document root

```apache
<VirtualHost *:80>
    ServerName localhost
    DocumentRoot /var/www/devapppro

    # PHP API endpointy
    <Directory /var/www/devapppro/api>
        Require all granted
        Options -Indexes
    </Directory>

    # Statické assety
    <Directory /var/www/devapppro/assets>
        Require all granted
        Options -Indexes
    </Directory>

    # SPA fallback - všechny ostatní routy → index.html
    <Directory /var/www/devapppro>
        DirectoryIndex index.html
        FallbackResource /index.html
        Require all granted
        Options -Indexes
    </Directory>

    # .htaccess
    AllowOverride All
</VirtualHost>
```

### 3.2 .htaccess

```apache
# Přesměrování API
RewriteEngine On
RewriteRule ^api/([a-z-]+)/?$ api/$1.php [L,QSA]

# SPA fallback
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.html [L]

# Bezpečnostní hlavičky
Header always set X-Content-Type-Options "nosniff"
Header always set X-Frame-Options "DENY"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
Header always set Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'"

# Komprese
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE application/json text/html text/css application/javascript
</IfModule>

# Cache statických assetů
<FilesMatch "\.(js|css|woff2)$">
    Header set Cache-Control "public, max-age=31536000, immutable"
</FilesMatch>

# Zákaz přístupu ke config
<FilesMatch "^(config|database)\.php$">
    Require all denied
</FilesMatch>
```

---

## 4. PHP bootstrap

```php
// bootstrap.php
declare(strict_types=1);

// Autoloading
require_once __DIR__ . '/vendor/autoload.php';

// Konfigurace
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

// Helpers
require_once __DIR__ . '/src/helpers.php';

// Session
session_set_cookie_params([
    'lifetime' => 7200,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
```

---

## 5. React entry point

```typescript
// src/main.tsx
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { App } from './App';
import './styles/globals.css';

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <BrowserRouter>
      <App />
    </BrowserRouter>
  </StrictMode>
);
```

```typescript
// src/App.tsx
import { Routes, Route } from 'react-router-dom';
import { lazy, Suspense } from 'react';
import { AppShell } from './components/layout/AppShell';
import { LoadingSpinner } from './components/shared/LoadingSpinner';
import { useAuth } from './hooks/useAuth';

const DashboardPage = lazy(() => import('./pages/DashboardPage'));
const ClientsPage = lazy(() => import('./pages/ClientsPage'));
const ClientDetailPage = lazy(() => import('./pages/ClientDetailPage'));
const ProjectsPage = lazy(() => import('./pages/ProjectsPage'));
const ProjectDetailPage = lazy(() => import('./pages/ProjectDetailPage'));
const TasksPage = lazy(() => import('./pages/TasksPage'));
const FinancePage = lazy(() => import('./pages/FinancePage'));
const NotesPage = lazy(() => import('./pages/NotesPage'));
const FilesPage = lazy(() => import('./pages/FilesPage'));
const SettingsPage = lazy(() => import('./pages/SettingsPage'));
const LoginPage = lazy(() => import('./pages/LoginPage'));
const NotFoundPage = lazy(() => import('./pages/NotFoundPage'));

export function App() {
  const { user, loading } = useAuth();

  if (loading) return <LoadingSpinner />;
  if (!user) return <LoginPage />;

  return (
    <AppShell>
      <Suspense fallback={<LoadingSpinner />}>
        <Routes>
          <Route path="/" element={<DashboardPage />} />
          <Route path="/clients" element={<ClientsPage />} />
          <Route path="/clients/:id" element={<ClientDetailPage />} />
          <Route path="/projects" element={<ProjectsPage />} />
          <Route path="/projects/:id" element={<ProjectDetailPage />} />
          <Route path="/tasks" element={<TasksPage />} />
          <Route path="/finance" element={<FinancePage />} />
          <Route path="/notes" element={<NotesPage />} />
          <Route path="/files" element={<FilesPage />} />
          <Route path="/settings" element={<SettingsPage />} />
          <Route path="*" element={<NotFoundPage />} />
        </Routes>
      </Suspense>
    </AppShell>
  );
}
```

---

## 6. Datový tok

### 6.1 Příklad: Načtení seznamu klientů

```
1. React: ClientsPage mount → useClients() hook
2. useClients: fetch('/api/clients', { credentials: 'same-origin' })
3. Apache: /api/clients → api/clients.php
4. PHP: ClientApiController::handle() → index()
5. ClientRepository::all() → SELECT * FROM clients LIMIT 50
6. PHP: json_encode($clients) → 200 OK
7. React: setState(clients) → render tabulky
```

### 6.2 Příklad: Vytvoření klienta

```
1. React: formulář submit → createClient(data)
2. api.ts: fetch('/api/clients', {
     method: 'POST',
     headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
     credentials: 'same-origin',
     body: JSON.stringify(data)
   })
3. Apache: /api/clients → api/clients.php
4. PHP: ClientApiController::handle() → store()
5. requireCsrf() → ověřit CSRF token
6. validate(data) → ověřit vstup
7. ClientRepository::create($data) → INSERT INTO clients
8. PHP: json_encode(['id' => $id]) → 201 Created
9. React: refetch seznam nebo přidat do state
```

---

## 7. Adresářová struktura (kompletní)

```
/var/www/devapppro/
├── docs/                    - dokumentace
│   ├── 00-overview.md
│   ├── 01-security.md
│   ├── 02-modularity.md
│   ├── 03-performance.md
│   ├── 04-architecture.md
│   ├── 05-database.md
│   ├── 06-api.md
│   ├── 07-frontend.md
│   └── 08-deployment.md
├── config/                  - konfigurace (chráněno)
│   ├── config.php
│   ├── database.php
│   └── session.php
├── api/                     - API vstupní body
│   ├── auth.php
│   ├── clients.php
│   ├── projects.php
│   ├── tasks.php
│   ├── invoices.php
│   ├── invoice-payments.php
│   ├── transactions.php
│   ├── notes.php
│   ├── files.php
│   ├── settings.php
│   └── company-profile.php
├── src/                     - PHP doménový kód (PSR-4)
│   ├── Core/
│   │   ├── ApiController.php
│   │   └── Repository.php
│   ├── Controllers/
│   │   ├── AuthApiController.php
│   │   ├── ClientApiController.php
│   │   ├── ProjectApiController.php
│   │   ├── TaskApiController.php
│   │   ├── InvoiceApiController.php
│   │   ├── InvoicePaymentApiController.php
│   │   ├── TransactionApiController.php
│   │   ├── NoteApiController.php
│   │   ├── FileApiController.php
│   │   ├── SettingsApiController.php
│   │   └── CompanyProfileApiController.php
│   ├── Repositories/
│   │   ├── ClientRepository.php
│   │   ├── ProjectRepository.php
│   │   ├── TaskRepository.php
│   │   ├── InvoiceRepository.php
│   │   ├── InvoicePaymentRepository.php
│   │   ├── TransactionRepository.php
│   │   ├── NoteRepository.php
│   │   ├── FileRepository.php
│   │   ├── SettingsRepository.php
│   │   └── CompanyProfileRepository.php
│   ├── Services/
│   │   └── InvoicePdfService.php  - generování PDF přes mPDF
│   ├── Auth.php
│   └── helpers.php
├── database/                - SQL migrace
│   ├── schema.sql
│   └── seed.sql
├── cli/                     - CLI skripty (cron)
│   └── sync-projects.php    - auto-sync projektů ze složek
├── frontend/                - React SPA (Vite)
│   ├── src/
│   │   ├── main.tsx
│   │   ├── App.tsx
│   │   ├── pages/
│   │   │   ├── DashboardPage.tsx
│   │   │   ├── ClientsPage.tsx
│   │   │   ├── ClientDetailPage.tsx
│   │   │   ├── ProjectsPage.tsx
│   │   │   ├── ProjectDetailPage.tsx
│   │   │   ├── TasksPage.tsx
│   │   │   ├── FinancePage.tsx
│   │   │   ├── NotesPage.tsx
│   │   │   ├── FilesPage.tsx
│   │   │   ├── SettingsPage.tsx
│   │   │   ├── LoginPage.tsx
│   │   │   └── NotFoundPage.tsx
│   │   ├── components/
│   │   │   ├── ui/               - shadcn/ui komponenty
│   │   │   ├── layout/
│   │   │   │   ├── AppShell.tsx
│   │   │   │   ├── Sidebar.tsx
│   │   │   │   └── Topbar.tsx
│   │   │   ├── clients/
│   │   │   ├── projects/
│   │   │   ├── tasks/
│   │   │   ├── finance/
│   │   │   ├── notes/
│   │   │   ├── files/
│   │   │   └── shared/
│   │   ├── hooks/
│   │   │   ├── useAuth.ts
│   │   │   ├── useApi.ts
│   │   │   ├── useClients.ts
│   │   │   ├── useProjects.ts
│   │   │   ├── useProjectActions.ts
│   │   │   ├── useTasks.ts
│   │   │   ├── useFinance.ts
│   │   │   ├── useNotes.ts
│   │   │   └── useFiles.ts
│   │   ├── lib/
│   │   │   ├── api.ts
│   │   │   ├── utils.ts
│   │   │   └── constants.ts
│   │   ├── types/
│   │   │   ├── client.ts
│   │   │   ├── project.ts
│   │   │   ├── task.ts
│   │   │   ├── invoice.ts
│   │   │   ├── note.ts
│   │   │   ├── file.ts
│   │   │   └── api.ts
│   │   └── styles/
│   │       └── globals.css
│   ├── public/
│   │   ├── fonts/
│   │   └── favicon.ico
│   ├── package.json
│   ├── vite.config.ts
│   ├── tailwind.config.ts
│   ├── tsconfig.json
│   └── components.json
├── assets/                  - produkční build (generováno Vite)
│   └── dist/
│       ├── index.html
│       ├── assets/
│       │   ├── app.[hash].js
│       │   ├── app.[hash].css
│       │   └── vendor.[hash].js
│       └── fonts/
├── storage/                 - nahrané soubory (chráněno, mimo webroot)
│   └── {YYYY}/{MM}/
│       └── {uuid}.{ext}
├── bootstrap.php            - PHP inicializace
├── composer.json            - mpdf/mpdf (PDF faktury)
├── .htaccess
└── index.html               - SPA entry (produkce)
```
