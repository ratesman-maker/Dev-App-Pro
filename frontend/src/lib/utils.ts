import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

export function fmtDate(date: string | Date): string {
  const d = new Date(date);
  return `${d.getDate()}.${d.getMonth() + 1}.${d.getFullYear()}`;
}

export function fmtDateTime(date: string | Date): string {
  const d = new Date(date);
  return `${fmtDate(d)} ${d.getHours()}:${String(d.getMinutes()).padStart(2, '0')}`;
}

export function fmtMoney(cents: number): string {
  const crowns = Math.round(cents / 100);
  return crowns.toLocaleString('cs-CZ') + ' Kč';
}

export function fmtMinutes(minutes: number): string {
  if (minutes === 0) return '0m';
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  if (h === 0) return `${m}m`;
  if (m === 0) return `${h}h`;
  return `${h}h ${m}m`;
}

export function fmtClientName(
  firstName: string | null,
  lastName: string | null,
  companyName: string | null = null,
  type: string | null = null,
): string {
  // Pro firmy/neziskovky/vládu preferovat company_name
  if (type && ['company', 'nonprofit', 'government'].includes(type) && companyName) {
    return companyName;
  }
  // Pokud je company_name a chybí first_name i last_name, použít company_name
  if (!firstName && !lastName && companyName) {
    return companyName;
  }
  if (!firstName && !lastName) return '';
  return `${firstName ?? ''} ${lastName ?? ''}`.trim();
}

export function fmtBytes(bytes: number): string {
  if (bytes === 0) return '0 B';
  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}
