import { createContext, useContext, useState, useCallback, type ReactNode } from 'react';
import { CheckCircle2, AlertTriangle, XCircle, X } from 'lucide-react';

type ToastType = 'success' | 'warning' | 'error';

interface Toast {
  id: number;
  type: ToastType;
  title: string;
  message?: string;
}

interface ToastContextValue {
  toast: (type: ToastType, title: string, message?: string) => void;
  success: (title: string, message?: string) => void;
  warning: (title: string, message?: string) => void;
  error: (title: string, message?: string) => void;
}

const ToastContext = createContext<ToastContextValue | null>(null);

export function useToast() {
  const ctx = useContext(ToastContext);
  if (!ctx) throw new Error('useToast musí být použit uvnitř ToastProvider');
  return ctx;
}

const toastConfig: Record<ToastType, {
  icon: typeof CheckCircle2;
  containerClass: string;
  iconClass: string;
  titleClass: string;
  messageClass: string;
}> = {
  success: {
    icon: CheckCircle2,
    containerClass: 'border-green-600 bg-white dark:bg-zinc-900',
    iconClass: 'text-green-600',
    titleClass: 'text-zinc-900 dark:text-zinc-100',
    messageClass: 'text-zinc-600 dark:text-zinc-400',
  },
  warning: {
    icon: AlertTriangle,
    containerClass: 'border-yellow-600 bg-white dark:bg-zinc-900',
    iconClass: 'text-yellow-600',
    titleClass: 'text-zinc-900 dark:text-zinc-100',
    messageClass: 'text-zinc-600 dark:text-zinc-400',
  },
  error: {
    icon: XCircle,
    containerClass: 'border-red-600 bg-white dark:bg-zinc-900',
    iconClass: 'text-red-600',
    titleClass: 'text-zinc-900 dark:text-zinc-100',
    messageClass: 'text-zinc-600 dark:text-zinc-400',
  },
};

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([]);

  const remove = useCallback((id: number) => {
    setToasts((prev) => prev.filter((t) => t.id !== id));
  }, []);

  const toast = useCallback((type: ToastType, title: string, message?: string) => {
    const id = Date.now() + Math.random();
    setToasts((prev) => [...prev, { id, type, title, message }]);
    setTimeout(() => remove(id), 10000);
  }, [remove]);

  const value: ToastContextValue = {
    toast,
    success: (title, message) => toast('success', title, message),
    warning: (title, message) => toast('warning', title, message),
    error: (title, message) => toast('error', title, message),
  };

  return (
    <ToastContext.Provider value={value}>
      {children}
      {/* Toast kontejner - fixed v pravém horním rohu, pod topbarem (h-14 = 56px) */}
      <div className="fixed right-4 top-16 z-[200] flex w-full max-w-sm flex-col gap-2">
        {toasts.map((t) => {
          const config = toastConfig[t.type];
          const Icon = config.icon;
          return (
            <div
              key={t.id}
              className={`flex items-start gap-3 rounded-lg border-2 p-4 animate-in slide-in-from-right-5 fade-in duration-300 ${config.containerClass}`}
            >
              <Icon className={`mt-0.5 h-5 w-5 shrink-0 ${config.iconClass}`} />
              <div className="flex-1 space-y-0.5 min-w-0">
                <div className={`text-sm font-semibold break-words ${config.titleClass}`}>{t.title}</div>
                {t.message && <div className={`text-sm break-words ${config.messageClass}`}>{t.message}</div>}
              </div>
              <button
                onClick={() => remove(t.id)}
                className="shrink-0 rounded-sm text-zinc-400 transition-colors hover:text-zinc-900 dark:hover:text-zinc-100"
              >
                <X className="h-4 w-4" />
              </button>
            </div>
          );
        })}
      </div>
    </ToastContext.Provider>
  );
}
