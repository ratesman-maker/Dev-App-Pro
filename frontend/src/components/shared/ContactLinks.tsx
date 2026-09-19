/**
 * Klikatelný e-mail - otevře mailto: odkaz.
 * Pokud hodnota chybí, vrátí '—'.
 */
export function EmailLink({ email, className }: { email?: string | null; className?: string }) {
  if (!email) return <span className="text-muted-foreground">—</span>;
  return (
    <a
      href={`mailto:${email}`}
      className={`text-primary hover:underline ${className ?? ''}`}
    >
      {email}
    </a>
  );
}

/**
 * Klikatelný telefon - otevře tel: odkaz.
 * Pokud hodnota chybí, vrátí '—'.
 */
export function PhoneLink({ phone, className }: { phone?: string | null; className?: string }) {
  if (!phone) return <span className="text-muted-foreground">—</span>;
  // Odstranit mezery pro tel: odkaz (např. +420 123 456 789 → +420123456789)
  const telHref = `tel:${phone.replace(/\s+/g, '')}`;
  return (
    <a
      href={telHref}
      className={`text-primary hover:underline ${className ?? ''}`}
    >
      {phone}
    </a>
  );
}
