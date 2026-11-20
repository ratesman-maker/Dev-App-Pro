import { createContext, useContext } from 'react';

export type ToastType = 'success' | 'warning' | 'error';

export interface Toast {
  id: number;
  type: ToastType;
  title: string;
  message?: string;
}

export interface ToastContextValue {
  toast: (type: ToastType, title: string, message?: string) => void;
  success: (title: string, message?: string) => void;
  warning: (title: string, message?: string) => void;
  error: (title: string, message?: string) => void;
}

export const ToastContext = createContext<ToastContextValue | null>(null);

export function useToast() {
  const ctx = useContext(ToastContext);
  if (!ctx) throw new Error('useToast musí být použit uvnitř ToastProvider');
  return ctx;
}
