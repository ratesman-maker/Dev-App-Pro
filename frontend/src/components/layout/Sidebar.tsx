import { useState, useEffect } from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import {
  LayoutDashboard,
  Users,
  FolderKanban,
  ListTodo,
  DollarSign,
  StickyNote,
  Paperclip,
  Settings,
  Wrench,
  Code,
  ChevronDown,
  Archive,
  BookOpen,
} from 'lucide-react';
import { cn } from '@/lib/utils';

interface NavItem {
  to: string;
  label: string;
  icon: React.ComponentType<{ className?: string }>;
  children?: { to: string; label: string; icon: React.ComponentType<{ className?: string }> }[];
}

const navItems: NavItem[] = [
  { to: '/', label: 'Dashboard', icon: LayoutDashboard },
  { to: '/clients', label: 'Klienti', icon: Users },
  {
    to: '/projects',
    label: 'Projekty',
    icon: FolderKanban,
    children: [
      { to: '/projects', label: 'Projekty', icon: FolderKanban },
      { to: '/projects/backups', label: 'Zálohy', icon: Archive },
    ],
  },
  { to: '/tasks', label: 'Úkoly', icon: ListTodo },
  { to: '/finance', label: 'Finance', icon: DollarSign },
  { to: '/notes', label: 'Poznámky', icon: StickyNote },
  { to: '/worklog', label: 'Pracovní deník', icon: BookOpen },
  { to: '/files', label: 'Soubory', icon: Paperclip },
  { to: '/tools', label: 'Nástroje', icon: Wrench },
  { to: '/settings', label: 'Nastavení', icon: Settings },
];

interface SidebarProps {
  collapsed: boolean;
}

export function Sidebar({ collapsed }: SidebarProps) {
  const location = useLocation();
  const [expanded, setExpanded] = useState<Record<string, boolean>>({});

  // Auto-expand podmenu pokud jsme na child route, jinak sbalit
  useEffect(() => {
    setExpanded((prev) => {
      const next = { ...prev };
      for (const item of navItems) {
        if (!item.children) continue;
        const onChild = item.children.some((c) => location.pathname === c.to);
        next[item.to] = onChild;
      }
      return next;
    });
  }, [location.pathname]);

  return (
    <aside
      className={cn(
        'flex h-screen flex-col border-r bg-sidebar text-sidebar-foreground transition-[width] duration-200',
        collapsed ? 'w-16' : 'w-64'
      )}
    >
      {/* Header */}
      <div className="flex h-14 items-center gap-2 border-b px-4">
        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground">
          <Code className="h-4 w-4" />
        </div>
        {!collapsed && <span className="text-sm font-semibold">Dev App Pro</span>}
      </div>

      {/* Navigation */}
      <nav className="flex-1 space-y-1 overflow-y-auto p-2">
        {navItems.map((item) => {
          const Icon = item.icon;

          // Položka s podmenu
          if (item.children && !collapsed) {
            const isExpanded = expanded[item.to] ?? false;
            const hasActiveChild = item.children.some(
              (c) => location.pathname === c.to
            );
            return (
              <div key={item.to}>
                <button
                  type="button"
                  onClick={() => setExpanded((prev) => ({ ...prev, [item.to]: !isExpanded }))}
                  className={cn(
                    'flex w-full items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                    hasActiveChild
                      ? 'bg-sidebar-accent text-sidebar-accent-foreground'
                      : 'text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground'
                  )}
                >
                  <Icon className="h-4 w-4 shrink-0" />
                  <span className="flex-1 text-left">{item.label}</span>
                  <ChevronDown
                    className={cn(
                      'h-4 w-4 shrink-0 transition-transform',
                      isExpanded && 'rotate-180'
                    )}
                  />
                </button>
                {isExpanded && (
                  <div className="ml-4 mt-1 space-y-1 border-l pl-3">
                    {item.children.map((child) => {
                      const ChildIcon = child.icon;
                      return (
                        <NavLink
                          key={child.to}
                          to={child.to}
                          end={child.to === '/projects'}
                          prefetch="intent"
                          className={({ isActive }) =>
                            cn(
                              'flex items-center gap-3 rounded-md px-3 py-1.5 text-sm transition-colors',
                              isActive
                                ? 'bg-sidebar-accent text-sidebar-accent-foreground'
                                : 'text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground'
                            )
                          }
                        >
                          <ChildIcon className="h-3.5 w-3.5 shrink-0" />
                          <span>{child.label}</span>
                        </NavLink>
                      );
                    })}
                  </div>
                )}
              </div>
            );
          }

          // Položka s podmenu (collapsed - zobrazí jen ikonu, podmenu se rozbalí po najetí)
          if (item.children && collapsed) {
            return (
              <NavLink
                key={item.to}
                to={item.to}
                end={item.to === '/projects'}
                prefetch="intent"
                className={({ isActive }) =>
                  cn(
                    'flex items-center justify-center rounded-md px-3 py-2 text-sm font-medium transition-colors',
                    isActive
                      ? 'bg-sidebar-accent text-sidebar-accent-foreground'
                      : 'text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground'
                  )
                }
                title={item.label}
              >
                <Icon className="h-4 w-4 shrink-0" />
              </NavLink>
            );
          }

          // Běžná položka
          return (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.to === '/'}
              prefetch="intent"
              className={({ isActive }) =>
                cn(
                  'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                  collapsed && 'justify-center',
                  isActive
                    ? 'bg-sidebar-accent text-sidebar-accent-foreground'
                    : 'text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground'
                )
              }
              title={collapsed ? item.label : undefined}
            >
              <Icon className="h-4 w-4 shrink-0" />
              {!collapsed && <span>{item.label}</span>}
            </NavLink>
          );
        })}
      </nav>
    </aside>
  );
}
