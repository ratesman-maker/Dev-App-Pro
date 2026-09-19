import { useState, useCallback } from 'react';
import { ApiError } from '@/lib/api';

/**
 * Sdílený hook pro formulářové dialogy.
 * Zapouzdřuje společný vzor: errors, submitting, handleSubmit, error handling.
 *
 * Použití:
 *   const { errors, submitting, handleSubmit, setErrors } = useFormDialog({
 *     onSubmit: async (data) => { await mutation.mutateAsync(data); },
 *     onSuccess: () => { setOpen(false); },
 *   });
 */
export function useFormDialog<T>(opts: {
  onSubmit: (data: T) => Promise<void>;
  onSuccess?: () => void;
  onError?: (err: unknown) => void;
}) {
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);

  const handleSubmit = useCallback(
    async (ev: React.FormEvent, data: T) => {
      ev.preventDefault();
      setErrors({});
      setSubmitting(true);
      try {
        await opts.onSubmit(data);
        opts.onSuccess?.();
      } catch (err) {
        if (err instanceof ApiError) {
          if (err.body.fields) {
            setErrors(err.body.fields);
          } else {
            setErrors({ form: err.body.error || 'Chyba při ukládání.' });
          }
        } else if (err instanceof Error) {
          setErrors({ form: err.message });
        } else {
          setErrors({ form: 'Neznámá chyba.' });
        }
        opts.onError?.(err);
      } finally {
        setSubmitting(false);
      }
    },
    [opts],
  );

  return { errors, submitting, handleSubmit, setErrors };
}
