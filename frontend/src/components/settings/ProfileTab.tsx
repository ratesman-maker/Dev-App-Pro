import { useState, useEffect } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useAuth } from '@/hooks/useAuth';
import { useTheme } from '@/hooks/useTheme';
import { useSidebar } from '@/hooks/useSidebar';
import {
  useUpdatePreferences,
  useUpdatePasswordHint,
  useUpdateProfile,
  useChangePassword,
} from '@/hooks/useSettings';
import { ApiError } from '@/lib/api';

export function ProfileTab() {
  const { user } = useAuth();
  const { theme, setTheme } = useTheme();
  const { collapsed, toggle } = useSidebar();
  const updatePreferences = useUpdatePreferences();
  const updatePasswordHint = useUpdatePasswordHint();
  const updateProfile = useUpdateProfile();
  const changePassword = useChangePassword();

  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [profileSaved, setProfileSaved] = useState(false);
  const [profileError, setProfileError] = useState<string | null>(null);

  const [passwordHint, setPasswordHint] = useState('');
  const [perPage, setPerPage] = useState(50);
  const [hintSaved, setHintSaved] = useState(false);
  const [hintError, setHintError] = useState<string | null>(null);

  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [newPasswordConfirm, setNewPasswordConfirm] = useState('');
  const [passwordSaved, setPasswordSaved] = useState(false);
  const [passwordError, setPasswordError] = useState<string | null>(null);

  useEffect(() => {
    if (user) {
      setName(user.name ?? '');
      setEmail(user.email ?? '');
      setPerPage(user.per_page);
    }
    setPasswordHint('');
  }, [user]);

  const handleSaveProfile = async () => {
    setProfileSaved(false);
    setProfileError(null);
    try {
      await updateProfile.mutateAsync({ name, email });
      setProfileSaved(true);
    } catch (err) {
      if (err instanceof ApiError) {
        setProfileError(err.body.error || 'Chyba při ukládání');
      } else if (err instanceof Error) {
        setProfileError(err.message);
      } else {
        setProfileError('Neznámá chyba');
      }
    }
  };

  const handleSaveHint = async () => {
    setHintSaved(false);
    setHintError(null);
    try {
      await updatePasswordHint.mutateAsync({ password_hint: passwordHint });
      setHintSaved(true);
    } catch (err) {
      if (err instanceof ApiError) {
        setHintError(err.body.error || 'Chyba při ukládání');
      } else if (err instanceof Error) {
        setHintError(err.message);
      } else {
        setHintError('Neznámá chyba');
      }
    }
  };

  const handleChangePassword = async () => {
    setPasswordSaved(false);
    setPasswordError(null);
    try {
      await changePassword.mutateAsync({
        current_password: currentPassword,
        new_password: newPassword,
        new_password_confirm: newPasswordConfirm,
      });
      setPasswordSaved(true);
      setCurrentPassword('');
      setNewPassword('');
      setNewPasswordConfirm('');
    } catch (err) {
      if (err instanceof ApiError) {
        setPasswordError(err.body.error || 'Chyba při změně hesla');
      } else if (err instanceof Error) {
        setPasswordError(err.message);
      } else {
        setPasswordError('Neznámá chyba');
      }
    }
  };

  const handleThemeChange = (value: 'light' | 'dark') => {
    setTheme(value);
    if (user) {
      updatePreferences.mutate({ theme: value });
    }
  };

  const handleSidebarToggle = () => {
    toggle();
  };

  const handlePerPageChange = (value: number) => {
    setPerPage(value);
    if (user) {
      updatePreferences.mutate({ per_page: value });
    }
  };

  return (
    <div className="space-y-6">
      {/* Údaje uživatele - editovatelné */}
      <Card>
        <CardHeader>
          <CardTitle>Údaje uživatele</CardTitle>
        </CardHeader>
        <CardContent className="space-y-3">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="profile-name">Jméno</Label>
              <Input
                id="profile-name"
                value={name}
                onChange={(e) => {
                  setName(e.target.value);
                  setProfileSaved(false);
                }}
                placeholder="Vaše jméno"
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="profile-email">E-mail</Label>
              <Input
                id="profile-email"
                type="email"
                value={email}
                onChange={(e) => {
                  setEmail(e.target.value);
                  setProfileSaved(false);
                }}
                placeholder="vas@email.com"
              />
            </div>
          </div>
          <div className="flex items-center gap-3">
            <Button onClick={handleSaveProfile} disabled={updateProfile.isPending}>
              {updateProfile.isPending ? 'Ukládání...' : 'Uložit profil'}
            </Button>
            {profileSaved && <p className="text-sm text-green-600">Profil byl uložen.</p>}
            {profileError && <p className="text-sm text-destructive">{profileError}</p>}
          </div>
          <div className="space-y-1">
            <Label>Uživatelské jméno</Label>
            <div className="rounded-md border px-3 py-2 text-sm text-muted-foreground">{user?.username ?? '—'}</div>
          </div>
        </CardContent>
      </Card>

      {/* Změna hesla */}
      <Card>
        <CardHeader>
          <CardTitle>Změna hesla</CardTitle>
        </CardHeader>
        <CardContent className="space-y-3">
          <div className="space-y-1.5">
            <Label htmlFor="current-password">Aktuální heslo</Label>
            <Input
              id="current-password"
              type="password"
              value={currentPassword}
              onChange={(e) => {
                setCurrentPassword(e.target.value);
                setPasswordSaved(false);
              }}
              placeholder="Aktuální heslo"
            />
          </div>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="new-password">Nové heslo</Label>
              <Input
                id="new-password"
                type="password"
                value={newPassword}
                onChange={(e) => {
                  setNewPassword(e.target.value);
                  setPasswordSaved(false);
                }}
                placeholder="Min. 8 znaků"
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="new-password-confirm">Potvrzení nového hesla</Label>
              <Input
                id="new-password-confirm"
                type="password"
                value={newPasswordConfirm}
                onChange={(e) => {
                  setNewPasswordConfirm(e.target.value);
                  setPasswordSaved(false);
                }}
                placeholder="Zopakujte nové heslo"
              />
            </div>
          </div>
          <div className="flex items-center gap-3">
            <Button
              onClick={handleChangePassword}
              disabled={changePassword.isPending || !currentPassword || !newPassword || !newPasswordConfirm}
            >
              {changePassword.isPending ? 'Měním...' : 'Změnit heslo'}
            </Button>
            {passwordSaved && <p className="text-sm text-green-600">Heslo bylo změněno.</p>}
            {passwordError && <p className="text-sm text-destructive">{passwordError}</p>}
          </div>
        </CardContent>
      </Card>

      {/* Nápověda pro reset hesla */}
      <Card>
        <CardHeader>
          <CardTitle>Nápověda pro reset hesla</CardTitle>
        </CardHeader>
        <CardContent className="space-y-3">
          <div className="space-y-1.5">
            <Label htmlFor="password-hint">Nápověda</Label>
            <Input
              id="password-hint"
              value={passwordHint}
              onChange={(e) => {
                setPasswordHint(e.target.value);
                setHintSaved(false);
              }}
              placeholder="Nápověda, která vám pomůže zapamatovat si heslo"
            />
          </div>
          {hintSaved && <p className="text-sm text-green-600">Nápověda byla uložena.</p>}
          {hintError && <p className="text-sm text-destructive">{hintError}</p>}
          <Button onClick={handleSaveHint} disabled={updatePasswordHint.isPending}>
            {updatePasswordHint.isPending ? 'Ukládám...' : 'Uložit nápovědu'}
          </Button>
        </CardContent>
      </Card>

      {/* Preference */}
      <Card>
        <CardHeader>
          <CardTitle>Preference</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="space-y-1.5">
            <Label>Téma</Label>
            <Select
              value={theme}
              onChange={(e) => handleThemeChange(e.target.value as 'light' | 'dark')}
              className="max-w-xs"
            >
              <option value="light">Světlý</option>
              <option value="dark">Tmavý</option>
            </Select>
          </div>

          <div className="space-y-1.5">
            <Label>Sidebar</Label>
            <div className="flex items-center gap-2">
              <label className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  checked={!collapsed}
                  onChange={handleSidebarToggle}
                  className="h-4 w-4 rounded border-input"
                />
                Rozbalený sidebar
              </label>
            </div>
          </div>

          <div className="space-y-1.5">
            <Label>Počet položek na stránku</Label>
            <Select
              value={String(perPage)}
              onChange={(e) => handlePerPageChange(Number(e.target.value))}
              className="max-w-xs"
            >
              <option value="10">10</option>
              <option value="20">20</option>
              <option value="50">50</option>
              <option value="100">100</option>
            </Select>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
