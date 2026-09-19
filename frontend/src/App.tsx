import { lazy, Suspense } from 'react';
import { Routes, Route } from 'react-router-dom';
import { ProtectedRoute } from '@/components/shared/ProtectedRoute';
import LoginPage from '@/pages/LoginPage';
import { PageSkeleton } from '@/components/ui/PageSkeleton';

// Lazy load všechny stránky kromě Login (ten se načítá hned)
const DashboardPage = lazy(() => import('@/pages/DashboardPage'));
const ClientsPage = lazy(() => import('@/pages/ClientsPage'));
const ProjectsPage = lazy(() => import('@/pages/ProjectsPage'));
const BackupsPage = lazy(() => import('@/pages/BackupsPage'));
const TasksPage = lazy(() => import('@/pages/TasksPage'));
const FinancePage = lazy(() => import('@/pages/FinancePage'));
const NotesPage = lazy(() => import('@/pages/NotesPage'));
const WorklogPage = lazy(() => import('@/pages/WorklogPage'));
const FilesPage = lazy(() => import('@/pages/FilesPage'));
const SettingsPage = lazy(() => import('@/pages/SettingsPage'));
const ToolsPage = lazy(() => import('@/pages/ToolsPage'));
const NotFoundPage = lazy(() => import('@/pages/NotFoundPage'));

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route element={<ProtectedRoute />}>
        <Route path="/" element={<Suspense fallback={<PageSkeleton />}><DashboardPage /></Suspense>} />
        <Route path="/clients" element={<Suspense fallback={<PageSkeleton />}><ClientsPage /></Suspense>} />
        <Route path="/projects" element={<Suspense fallback={<PageSkeleton />}><ProjectsPage /></Suspense>} />
        <Route path="/projects/backups" element={<Suspense fallback={<PageSkeleton />}><BackupsPage /></Suspense>} />
        <Route path="/tasks" element={<Suspense fallback={<PageSkeleton />}><TasksPage /></Suspense>} />
        <Route path="/finance" element={<Suspense fallback={<PageSkeleton />}><FinancePage /></Suspense>} />
        <Route path="/notes" element={<Suspense fallback={<PageSkeleton />}><NotesPage /></Suspense>} />
        <Route path="/worklog" element={<Suspense fallback={<PageSkeleton />}><WorklogPage /></Suspense>} />
        <Route path="/files" element={<Suspense fallback={<PageSkeleton />}><FilesPage /></Suspense>} />
        <Route path="/tools" element={<Suspense fallback={<PageSkeleton />}><ToolsPage /></Suspense>} />
        <Route path="/settings" element={<Suspense fallback={<PageSkeleton />}><SettingsPage /></Suspense>} />
      </Route>
      <Route path="*" element={<Suspense fallback={<PageSkeleton />}><NotFoundPage /></Suspense>} />
    </Routes>
  );
}
