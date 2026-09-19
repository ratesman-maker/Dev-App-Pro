import { useState, useEffect } from 'react';
import { Dialog } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { AlertTriangle, ShieldAlert } from 'lucide-react';

interface ConfirmDeleteDialogProps {
  open: boolean;
  onClose: () => void;
  onConfirm: () => void;
  title: string;
  description: string;
  entityName: string;
  confirmPhrase?: string;
}

export function ConfirmDeleteDialog({
  open,
  onClose,
  onConfirm,
  title,
  description,
  entityName,
  confirmPhrase = 'SMAZAT',
}: ConfirmDeleteDialogProps) {
  const [input, setInput] = useState('');

  useEffect(() => {
    if (open) setInput('');
  }, [open]);

  const matches = input.trim().toUpperCase() === confirmPhrase.toUpperCase();

  return (
    <Dialog open={open} onClose={onClose} title={title}>
      {/* Důrazné varování */}
      <div className="mt-4 flex items-start gap-3 rounded-md border border-destructive/50 bg-destructive/10 p-4">
        <ShieldAlert className="h-6 w-6 text-destructive shrink-0" />
        <div className="space-y-1 min-w-0">
          <p className="text-sm font-semibold text-destructive">Tato akce je nevratná!</p>
          <p className="text-sm text-muted-foreground break-words">{description}</p>
        </div>
      </div>

      {/* Ověřovací fráze */}
      <div className="mt-5 space-y-2">
        <p className="text-sm text-muted-foreground">
          Pro potvrzení smazání entity <span className="font-semibold text-foreground break-all">„{entityName}"</span> napište:
        </p>
        <div className="flex items-center gap-2">
          <code className="rounded bg-muted px-2 py-1 text-sm font-mono font-semibold tracking-wider text-destructive">
            {confirmPhrase}
          </code>
        </div>
        <Input
          value={input}
          onChange={(e) => setInput(e.target.value)}
          placeholder={confirmPhrase}
          autoFocus
          className="mt-2"
        />
      </div>

      <div className="mt-6 flex justify-end gap-2">
        <Button variant="outline" onClick={onClose}>Zrušit</Button>
        <Button
          variant="destructive"
          onClick={onConfirm}
          disabled={!matches}
        >
          <AlertTriangle className="h-4 w-4" />
          Smazat natrvalo
        </Button>
      </div>
    </Dialog>
  );
}
