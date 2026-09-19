import { useState, useEffect } from 'react';
import { Dialog } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { AttachmentSelect, type AttachmentValue } from '@/components/shared/AttachmentSelect';
import { useUpdateFile, type FileItem } from '@/hooks/useFiles';
import { ApiError } from '@/lib/api';

interface FileFormDialogProps {
  open: boolean;
  onClose: () => void;
  file?: FileItem | null;
}

export function FileFormDialog({ open, onClose, file }: FileFormDialogProps) {
  const updateMutation = useUpdateFile(file?.id ?? 0);

  const [attachments, setAttachments] = useState<AttachmentValue[]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (open) {
      setAttachments(file?.attachments?.map((a) => ({ entity_type: a.entity_type, entity_id: a.entity_id })) ?? []);
      setError(null);
    }
  }, [open, file]);

  const handleSubmit = async (ev: React.FormEvent) => {
    ev.preventDefault();
    try {
      await updateMutation.mutateAsync({
        attachments: attachments.map((a) => ({ entity_type: a.entity_type, entity_id: a.entity_id })),
      });
      onClose();
    } catch (err) {
      if (err instanceof ApiError && err.body.fields) {
        setError(err.body.error || 'Chyba při ukládání');
      } else if (err instanceof Error) {
        setError(err.message);
      } else {
        setError('Neznámá chyba');
      }
    }
  };

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title="Upravit vazby souboru"
      description={file ? `Soubor: ${file.original_name}` : undefined}
      className="max-w-xl"
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        <AttachmentSelect value={attachments} onChange={setAttachments} />

        {error && <p className="text-sm text-destructive">{error}</p>}

        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="outline" onClick={onClose}>Zrušit</Button>
          <Button type="submit" disabled={updateMutation.isPending}>
            {updateMutation.isPending ? 'Ukládám...' : 'Uložit změny'}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
