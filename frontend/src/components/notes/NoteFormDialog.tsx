import { useState, useEffect } from 'react';
import { Dialog } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { AttachmentSelect, type AttachmentValue } from '@/components/shared/AttachmentSelect';
import { useCreateNote, useUpdateNote, type Note, type NoteInput } from '@/hooks/useNotes';
import { ApiError } from '@/lib/api';
import { cn } from '@/lib/utils';

interface NoteFormDialogProps {
  open: boolean;
  onClose: () => void;
  note?: Note | null;
}

export function NoteFormDialog({ open, onClose, note }: NoteFormDialogProps) {
  const isEdit = !!note;
  const createMutation = useCreateNote();
  const updateMutation = useUpdateNote(note?.id ?? 0);
  const mutation = isEdit ? updateMutation : createMutation;

  const [title, setTitle] = useState('');
  const [content, setContent] = useState('');
  const [attachments, setAttachments] = useState<AttachmentValue[]>([]);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (open) {
      setTitle(note?.title ?? '');
      setContent(note?.content ?? '');
      setAttachments(note?.attachments?.map((a) => ({ entity_type: a.entity_type, entity_id: a.entity_id })) ?? []);
      setErrors({});
    }
  }, [open, note]);

  const handleSubmit = async (ev: React.FormEvent) => {
    ev.preventDefault();
    const e: Record<string, string> = {};
    if (!content.trim()) e.content = 'Obsah je povinný';
    setErrors(e);
    if (Object.keys(e).length > 0) return;

    const data: NoteInput = {
      title: title.trim() || null,
      content: content.trim(),
      attachments: attachments.map((a) => ({ entity_type: a.entity_type as 'client' | 'project' | 'task' | 'invoice', entity_id: a.entity_id })),
    };

    try {
      await mutation.mutateAsync(data);
      onClose();
    } catch (err) {
      if (err instanceof ApiError && err.body.fields) {
        setErrors(err.body.fields);
      } else if (err instanceof Error) {
        setErrors({ form: err.message });
      } else {
        setErrors({ form: 'Neznámá chyba' });
      }
    }
  };

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title={isEdit ? 'Upravit poznámku' : 'Nová poznámka'}
      description={isEdit ? 'Upravte obsah poznámky a vazby.' : 'Vytvořte novou poznámku.'}
      className="max-w-xl"
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="space-y-1.5">
          <Label htmlFor="note-title">Nadpis</Label>
          <Input
            id="note-title"
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            placeholder="Volitelný nadpis"
          />
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="note-content">Obsah *</Label>
          <textarea
            id="note-content"
            value={content}
            onChange={(e) => setContent(e.target.value)}
            rows={6}
            className={cn(
              'flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50'
            )}
            placeholder="Zadejte obsah poznámky..."
          />
          {errors.content && <p className="text-xs text-destructive">{errors.content}</p>}
        </div>

        <AttachmentSelect value={attachments} onChange={setAttachments} />

        {errors.form && <p className="text-sm text-destructive">{errors.form}</p>}

        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="outline" onClick={onClose}>Zrušit</Button>
          <Button type="submit" disabled={mutation.isPending}>
            {mutation.isPending ? 'Ukládám...' : isEdit ? 'Uložit změny' : 'Vytvořit'}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
