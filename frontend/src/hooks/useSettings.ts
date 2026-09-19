import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

export type Settings = Record<string, string>;

export interface CompanyProfile {
  type: 'individual' | 'company' | 'nonprofit' | 'government';
  first_name: string | null;
  last_name: string | null;
  company_name: string | null;
  ico: string | null;
  dic: string | null;
  email: string | null;
  phone: string | null;
  address: string | null;
  bank_account: string | null;
  iban: string | null;
  swift: string | null;
}

export interface PreferencesInput {
  theme?: 'light' | 'dark';
  sidebar_collapsed?: boolean;
  per_page?: number;
}

export function useSettings() {
  return useQuery<Settings>({
    queryKey: ['settings'],
    queryFn: () => api.get<Settings>('/settings'),
  });
}

export function useUpdateSettings() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: Settings) => api.put<Settings>('/settings', data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['settings'] }),
  });
}

export function useCompanyProfile() {
  return useQuery<CompanyProfile>({
    queryKey: ['company-profile'],
    queryFn: () => api.get<CompanyProfile>('/company-profile'),
  });
}

export function useUpdateCompanyProfile() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: CompanyProfile) => api.put<CompanyProfile>('/company-profile', data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['company-profile'] }),
  });
}

export function useUpdatePreferences() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: PreferencesInput) => api.put('/users/me/preferences', data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['auth', 'me'] }),
  });
}

export function useUpdatePasswordHint() {
  return useMutation({
    mutationFn: (data: { password_hint: string }) => api.put('/users/me/password-hint', data),
  });
}

export interface ProfileInput {
  name?: string;
  email?: string;
}

export function useUpdateProfile() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: ProfileInput) => api.put('/users/me/profile', data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['auth', 'me'] }),
  });
}

export interface ChangePasswordInput {
  current_password: string;
  new_password: string;
  new_password_confirm: string;
}

export function useChangePassword() {
  return useMutation({
    mutationFn: (data: ChangePasswordInput) => api.put('/users/me/password', data),
  });
}
