import { useEffect, useCallback } from 'react';
import { X, ChevronLeft, ChevronRight } from 'lucide-react';

interface LightboxImage {
  src: string;
  alt: string;
}

interface LightboxProps {
  images: LightboxImage[];
  index: number;
  onClose: () => void;
  onIndexChange: (index: number) => void;
}

export function Lightbox({ images, index, onClose, onIndexChange }: LightboxProps) {
  const total = images.length;
  const current = images[index];

  const goPrev = useCallback(() => {
    onIndexChange((index - 1 + total) % total);
  }, [index, total, onIndexChange]);

  const goNext = useCallback(() => {
    onIndexChange((index + 1) % total);
  }, [index, total, onIndexChange]);

  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
      else if (e.key === 'ArrowLeft') goPrev();
      else if (e.key === 'ArrowRight') goNext();
    };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, [onClose, goPrev, goNext]);

  if (!current) return null;

  return (
    <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/90 p-4">
      {/* Zavřít */}
      <button
        onClick={onClose}
        className="absolute right-4 top-4 z-10 rounded-full bg-white/10 p-2 text-white hover:bg-white/20"
        aria-label="Zavřít"
      >
        <X className="h-6 w-6" />
      </button>

      {/* Počítadlo */}
      <div className="absolute top-4 left-1/2 -translate-x-1/2 z-10 text-white text-sm">
        {index + 1} / {total}
      </div>

      {/* Předchozí */}
      {total > 1 && (
        <button
          onClick={goPrev}
          className="absolute left-4 top-1/2 -translate-y-1/2 z-10 rounded-full bg-white/10 p-2 text-white hover:bg-white/20"
          aria-label="Předchozí"
        >
          <ChevronLeft className="h-8 w-8" />
        </button>
      )}

      {/* Obrázek */}
      <img
        src={current.src}
        alt={current.alt}
        className="max-h-[90vh] max-w-[90vw] object-contain"
      />

      {/* Další */}
      {total > 1 && (
        <button
          onClick={goNext}
          className="absolute right-4 top-1/2 -translate-y-1/2 z-10 rounded-full bg-white/10 p-2 text-white hover:bg-white/20"
          aria-label="Další"
        >
          <ChevronRight className="h-8 w-8" />
        </button>
      )}

      {/* Název */}
      <div className="absolute bottom-4 left-1/2 -translate-x-1/2 z-10 text-white text-sm max-w-[80%] truncate">
        {current.alt}
      </div>
    </div>
  );
}
