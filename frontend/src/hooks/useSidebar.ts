import { useState, useEffect, useCallback } from 'react';
import { api } from '@/lib/api';
import { useAuth } from './useAuth';

export function useSidebar() {
  const { user } = useAuth();
  const [collapsed, setCollapsed] = useState<boolean>(false);

  useEffect(() => {
    if (user) {
      setCollapsed(user.sidebar_collapsed);
    } else {
      const stored = localStorage.getItem('devapppro-sidebar-collapsed');
      if (stored !== null) setCollapsed(stored === 'true');
    }
  }, [user]);

  const toggle = useCallback(async () => {
    const next = !collapsed;
    setCollapsed(next);
    localStorage.setItem('devapppro-sidebar-collapsed', String(next));
    if (user) {
      api.put('/users/me/preferences', { sidebar_collapsed: next }).catch(() => {});
    }
  }, [collapsed, user]);

  return { collapsed, toggle };
}
