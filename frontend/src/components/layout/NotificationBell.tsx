import { useState, useRef, useEffect } from 'react';
import { Bell, Check, Trash2, CheckCheck } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
  useNotifications,
  useUnreadCount,
  useMarkAsRead,
  useMarkAllAsRead,
  useDeleteNotification,
  type Notification,
} from '@/hooks/useNotifications';
import { cn } from '@/lib/utils';

function fmtRelative(dateStr: string): string {
  const date = new Date(dateStr + 'Z');
  const now = new Date();
  const diffMs = now.getTime() - date.getTime();
  const diffMin = Math.floor(diffMs / 60000);
  const diffH = Math.floor(diffMin / 60);
  const diffD = Math.floor(diffH / 24);

  if (diffMin < 1) return 'právě teď';
  if (diffMin < 60) return `před ${diffMin} min`;
  if (diffH < 24) return `před ${diffH} h`;
  if (diffD < 7) return `před ${diffD} d`;
  return date.toLocaleDateString('cs-CZ');
}

function notifIcon(type: string): string {
  if (type.startsWith('task')) return 'T';
  if (type.startsWith('invoice') || type.startsWith('payment')) return 'F';
  if (type.startsWith('transaction')) return '$';
  return '•';
}

function notifColor(type: string): string {
  if (type.includes('overdue') || type.includes('deleted')) return 'text-destructive';
  if (type.includes('soon') || type.includes('due')) return 'text-yellow-600 dark:text-yellow-500';
  if (type.includes('created')) return 'text-green-600 dark:text-green-500';
  return 'text-muted-foreground';
}

export function NotificationBell() {
  const [open, setOpen] = useState(false);
  const panelRef = useRef<HTMLDivElement>(null);
  const bellRef = useRef<HTMLButtonElement>(null);

  const { data } = useNotifications({ page: 1, per_page: 20 });
  const { data: unreadData } = useUnreadCount();
  const markAsRead = useMarkAsRead();
  const markAllAsRead = useMarkAllAsRead();
  const deleteNotif = useDeleteNotification();

  const unread = unreadData?.unread_count ?? 0;
  const notifications = data?.data ?? [];

  // Zavřít panel při kliknutí mimo
  useEffect(() => {
    function handleClick(e: MouseEvent) {
      if (
        panelRef.current &&
        !panelRef.current.contains(e.target as Node) &&
        bellRef.current &&
        !bellRef.current.contains(e.target as Node)
      ) {
        setOpen(false);
      }
    }
    if (open) {
      document.addEventListener('mousedown', handleClick);
      return () => document.removeEventListener('mousedown', handleClick);
    }
  }, [open]);

  const handleMarkAsRead = (id: number) => {
    markAsRead.mutate(id);
  };

  const handleMarkAll = () => {
    markAllAsRead.mutate();
  };

  const handleDelete = (id: number) => {
    deleteNotif.mutate(id);
  };

  return (
    <div className="relative">
      <Button
        ref={bellRef}
        variant="ghost"
        size="icon"
        onClick={() => setOpen((v) => !v)}
        aria-label="Notifikace"
        className="relative"
      >
        <Bell className="h-4 w-4" />
        {unread > 0 && (
          <span className="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[10px] font-bold text-destructive-foreground">
            {unread > 9 ? '9+' : unread}
          </span>
        )}
      </Button>

      {open && (
        <div
          ref={panelRef}
          className="absolute right-0 top-full z-50 mt-2 w-80 rounded-md border bg-popover p-0 text-popover-foreground"
        >
          {/* Header */}
          <div className="flex items-center justify-between border-b px-3 py-2">
            <span className="text-sm font-semibold">Notifikace</span>
            {unread > 0 && (
              <Button
                variant="ghost"
                size="sm"
                onClick={handleMarkAll}
                className="h-7 text-xs"
              >
                <CheckCheck className="mr-1 h-3 w-3" />
                Označit vše
              </Button>
            )}
          </div>

          {/* List */}
          <div className="max-h-96 overflow-y-auto">
            {notifications.length === 0 ? (
              <div className="py-8 text-center text-sm text-muted-foreground">
                Žádné notifikace
              </div>
            ) : (
              notifications.map((n: Notification) => (
                <div
                  key={n.id}
                  className={cn(
                    'flex gap-2 border-b px-3 py-2.5 last:border-b-0',
                    !n.is_read && 'bg-primary/5'
                  )}
                >
                  <div className={cn('mt-0.5 text-lg font-bold', notifColor(n.type))}>
                    {notifIcon(n.type)}
                  </div>
                  <div className="flex-1 space-y-0.5">
                    <p className="text-sm font-medium leading-tight">{n.title}</p>
                    {n.message && (
                      <p className="text-xs text-muted-foreground">{n.message}</p>
                    )}
                    <p className="text-[10px] text-muted-foreground">
                      {fmtRelative(n.created_at)}
                    </p>
                  </div>
                  <div className="flex flex-col gap-1">
                    {!n.is_read && (
                      <button
                        onClick={() => handleMarkAsRead(n.id)}
                        className="rounded p-1 text-muted-foreground hover:bg-accent hover:text-foreground"
                        aria-label="Označit jako přečtené"
                      >
                        <Check className="h-3.5 w-3.5" />
                      </button>
                    )}
                    <button
                      onClick={() => handleDelete(n.id)}
                      className="rounded p-1 text-muted-foreground hover:bg-accent hover:text-destructive"
                      aria-label="Smazat"
                    >
                      <Trash2 className="h-3.5 w-3.5" />
                    </button>
                  </div>
                </div>
              ))
            )}
          </div>
        </div>
      )}
    </div>
  );
}
