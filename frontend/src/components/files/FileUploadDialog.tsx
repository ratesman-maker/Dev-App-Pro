import { useState, useRef } from 'react';
import { Dialog } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { AttachmentSelect, type AttachmentValue } from '@/components/shared/AttachmentSelect';
import { useCreateFile } from '@/hooks/useFiles';
import { ApiError } from '@/lib/api';
import { Upload, X } from 'lucide-react';

interface FileUploadDialogProps {
  open: boolean;
  onClose: () => void;
}

export function FileUploadDialog({ open, onClose }: FileUploadDialogProps) {
  const createMutation = useCreateFile();
  const [files, setFiles] = useState<File[]>([]);
  const [attachments, setAttachments] = useState<AttachmentValue[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [dragOver, setDragOver] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);

  const reset = () => {
    setFiles([]);
    setAttachments([]);
    setError(null);
    setDragOver(false);
  };

  const handleClose = () => {
    reset();
    onClose();
  };

  const handleFiles = (selected: FileList | null) => {
    if (!selected) return;
    setFiles((prev) => [...prev, ...Array.from(selected)]);
  };

  const removeFile = (index: number) => {
    setFiles((prev) => prev.filter((_, i) => i !== index));
  };

  const handleSubmit = async (ev: React.FormEvent) => {
    ev.preventDefault();
    if (files.length === 0) {
      setError('Vyberte alespoň jeden soubor');
      return;
    }
    setError(null);
    try {
      for (const file of files) {
        await createMutation.mutateAsync({
          file,
          attachments: attachments.map((a) => ({ entity_type: a.entity_type, entity_id: a.entity_id })),
        });
      }
      reset();
      onClose();
    } catch (err) {
      if (err instanceof ApiError) {
        setError(err.body.error || 'Chyba při nahrávání');
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
      onClose={handleClose}
      title="Nahrát soubor"
      description="Vyberte soubory k nahrání a případně je přiřaďte k entitám."
      className="max-w-xl"
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        {/* Drag & drop area */}
        <div
          onDragOver={(e) => {
            e.preventDefault();
            setDragOver(true);
          }}
          onDragLeave={() => setDragOver(false)}
          onDrop={(e) => {
            e.preventDefault();
            setDragOver(false);
            handleFiles(e.dataTransfer.files);
          }}
          onClick={() => inputRef.current?.click()}
          className={`flex cursor-pointer flex-col items-center justify-center rounded-md border-2 border-dashed p-8 text-center transition-colors ${
            dragOver ? 'border-primary bg-primary/5' : 'border-input hover:border-primary/50'
          }`}
        >
          <Upload className="h-8 w-8 text-muted-foreground" />
          <p className="mt-2 text-sm text-muted-foreground">
            Přetáhněte soubory sem nebo klikněte pro výběr
          </p>
          <input
            ref={inputRef}
            type="file"
            multiple
            className="hidden"
            onChange={(e) => handleFiles(e.target.files)}
          />
        </div>

        {/* Selected files list */}
        {files.length > 0 && (
          <div className="space-y-1.5">
            <Label>Vybrané soubory ({files.length})</Label>
            <div className="space-y-1">
              {files.map((file, index) => (
                <div key={index} className="flex items-center justify-between rounded-md border px-3 py-2 text-sm">
                  <span className="truncate">{file.name}</span>
                  <button
                    type="button"
                    onClick={() => removeFile(index)}
                    className="ml-2 shrink-0 rounded-sm opacity-70 hover:opacity-100"
                  >
                    <X className="h-4 w-4" />
                  </button>
                </div>
              ))}
            </div>
          </div>
        )}

        <AttachmentSelect value={attachments} onChange={setAttachments} />

        {error && <p className="text-sm text-destructive">{error}</p>}

        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="outline" onClick={handleClose}>Zrušit</Button>
          <Button type="submit" disabled={createMutation.isPending || files.length === 0}>
            {createMutation.isPending ? 'Nahrávám...' : 'Nahrát'}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
