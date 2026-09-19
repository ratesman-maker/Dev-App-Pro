import { useState, useEffect } from 'react';

/**
 * Hook pro debounce hodnoty (typicky vyhledávací dotaz).
 * Vrátí debounced hodnotu, která se aktualizuje až po zpoždění.
 *
 * @param value Vstupní hodnota
 * @param delay Zpoždění v ms (default 300)
 */
export function useDebounce<T>(value: T, delay = 300): T {
  const [debounced, setDebounced] = useState<T>(value);

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delay);
    return () => clearTimeout(timer);
  }, [value, delay]);

  return debounced;
}
