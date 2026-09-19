import { useState, useEffect, useCallback } from 'react';
import { api } from '@/lib/api';
import { useAuth } from './useAuth';

export function useTheme() {
  const [theme, setTheme] = useState<'light' | 'dark'>(() => {
    const stored = localStorage.getItem('devapppro-theme') as 'light' | 'dark' | null;
    if (stored) return stored;
    return 'dark';
  });

  useEffect(() => {
    document.documentElement.classList.toggle('dark', theme === 'dark');
    localStorage.setItem('devapppro-theme', theme);
  }, [theme]);

  const { user } = useAuth();
  useEffect(() => {
    if (user?.theme && user.theme !== theme) {
      setTheme(user.theme);
    }
  }, [user?.theme]); // eslint-disable-line react-hooks/exhaustive-deps

  const toggle = useCallback(async () => {
    const next = theme === 'dark' ? 'light' : 'dark';
    setTheme(next);
    if (user) {
      api.put('/users/me/preferences', { theme: next }).catch(() => {});
    }
  }, [theme, user]);

  return { theme, setTheme, toggle };
}
