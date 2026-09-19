import { useState, useEffect, FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { Sun, Moon, Code, ArrowLeft, KeyRound } from 'lucide-react';
import { api, ApiError, fetchCsrfToken } from '@/lib/api';
import { useTheme } from '@/hooks/useTheme';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';

type ViewState = 'login' | 'reset-username' | 'reset-password' | 'reset-success';

export default function LoginPage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { theme, toggle } = useTheme();

  const [view, setView] = useState<ViewState>('login');

  // Login state
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [loginError, setLoginError] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);

  // Reset state
  const [resetUsername, setResetUsername] = useState('');
  const [hint, setHint] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [newPasswordConfirm, setNewPasswordConfirm] = useState('');
  const [resetError, setResetError] = useState('');

  useEffect(() => {
    fetchCsrfToken().catch(() => {});
  }, []);

  const handleLogin = async (e: FormEvent) => {
    e.preventDefault();
    setLoginError('');
    setIsSubmitting(true);
    try {
      await api.post('/auth/login', { username, password });
      await queryClient.invalidateQueries({ queryKey: ['auth', 'me'] });
      navigate('/', { replace: true });
    } catch (err) {
      if (err instanceof ApiError) {
        setLoginError(err.body.error || 'Přihlášení selhalo.');
      } else {
        setLoginError('Přihlášení selhalo.');
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleResetUsername = async (e: FormEvent) => {
    e.preventDefault();
    setResetError('');
    setIsSubmitting(true);
    try {
      const data = await api.post<{ hint: string }>('/auth/password-hint', { username: resetUsername });
      setHint(data.hint || 'Bez hintu');
      setView('reset-password');
    } catch (err) {
      if (err instanceof ApiError) {
        setResetError(err.body.error || 'Uživatel nenalezen.');
      } else {
        setResetError('Uživatel nenalezen.');
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleResetPassword = async (e: FormEvent) => {
    e.preventDefault();
    setResetError('');
    if (newPassword !== newPasswordConfirm) {
      setResetError('Hesla se neshodují.');
      return;
    }
    if (newPassword.length < 1) {
      setResetError('Heslo nesmí být prázdné.');
      return;
    }
    setIsSubmitting(true);
    try {
      await api.post('/auth/reset-password', {
        username: resetUsername,
        new_password: newPassword,
        new_password_confirm: newPasswordConfirm,
      });
      setView('reset-success');
    } catch (err) {
      if (err instanceof ApiError) {
        setResetError(err.body.error || 'Změna hesla selhala.');
      } else {
        setResetError('Změna hesla selhala.');
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  const resetResetState = () => {
    setResetUsername('');
    setHint('');
    setNewPassword('');
    setNewPasswordConfirm('');
    setResetError('');
  };

  return (
    <div className="relative flex min-h-screen items-center justify-center bg-background px-4 text-foreground">
      <div className="w-full max-w-md">
        {/* Logo + nadpis */}
        <div className="mb-6 flex flex-col items-center gap-2">
          <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-primary text-primary-foreground">
            <Code className="h-6 w-6" />
          </div>
          <h1 className="text-2xl font-semibold">Dev App Pro</h1>
        </div>

        {view === 'login' && (
          <Card>
            <CardHeader>
              <CardTitle>Přihlášení</CardTitle>
              <CardDescription>Přihlaste se ke svému účtu</CardDescription>
            </CardHeader>
            <CardContent>
              <form onSubmit={handleLogin} className="space-y-4">
                {loginError && (
                  <div className="rounded-md border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm text-destructive">
                    {loginError}
                  </div>
                )}
                <div className="space-y-2">
                  <Label htmlFor="username">Uživatelské jméno</Label>
                  <Input
                    id="username"
                    value={username}
                    onChange={(e) => setUsername(e.target.value)}
                    autoComplete="username"
                    autoFocus
                    required
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="password">Heslo</Label>
                  <Input
                    id="password"
                    type="password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    autoComplete="current-password"
                    required
                  />
                </div>
                <Button type="submit" className="w-full" disabled={isSubmitting}>
                  {isSubmitting ? 'Přihlašuji…' : 'Přihlásit se'}
                </Button>
              </form>
              <button
                onClick={() => {
                  resetResetState();
                  setView('reset-username');
                }}
                className="mt-4 flex w-full items-center justify-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
              >
                <KeyRound className="h-3.5 w-3.5" />
                Zapomněli jste heslo?
              </button>
            </CardContent>
          </Card>
        )}

        {view === 'reset-username' && (
          <Card>
            <CardHeader>
              <CardTitle>Obnovení hesla</CardTitle>
              <CardDescription>Zadejte uživatelské jméno pro zobrazení hintu</CardDescription>
            </CardHeader>
            <CardContent>
              <form onSubmit={handleResetUsername} className="space-y-4">
                {resetError && (
                  <div className="rounded-md border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm text-destructive">
                    {resetError}
                  </div>
                )}
                <div className="space-y-2">
                  <Label htmlFor="reset-username">Uživatelské jméno</Label>
                  <Input
                    id="reset-username"
                    value={resetUsername}
                    onChange={(e) => setResetUsername(e.target.value)}
                    autoComplete="username"
                    autoFocus
                    required
                  />
                </div>
                <Button type="submit" className="w-full" disabled={isSubmitting}>
                  {isSubmitting ? 'Načítám…' : 'Zobrazit hint'}
                </Button>
              </form>
              <button
                onClick={() => {
                  resetResetState();
                  setView('login');
                }}
                className="mt-4 flex w-full items-center justify-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
              >
                <ArrowLeft className="h-3.5 w-3.5" />
                Zpět na přihlášení
              </button>
            </CardContent>
          </Card>
        )}

        {view === 'reset-password' && (
          <Card>
            <CardHeader>
              <CardTitle>Nové heslo</CardTitle>
              <CardDescription>
                Hint: <span className="font-medium text-foreground">{hint}</span>
              </CardDescription>
            </CardHeader>
            <CardContent>
              <form onSubmit={handleResetPassword} className="space-y-4">
                {resetError && (
                  <div className="rounded-md border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm text-destructive">
                    {resetError}
                  </div>
                )}
                <div className="space-y-2">
                  <Label htmlFor="new-password">Nové heslo</Label>
                  <Input
                    id="new-password"
                    type="password"
                    value={newPassword}
                    onChange={(e) => setNewPassword(e.target.value)}
                    autoComplete="new-password"
                    autoFocus
                    required
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="new-password-confirm">Potvrzení hesla</Label>
                  <Input
                    id="new-password-confirm"
                    type="password"
                    value={newPasswordConfirm}
                    onChange={(e) => setNewPasswordConfirm(e.target.value)}
                    autoComplete="new-password"
                    required
                  />
                </div>
                <Button type="submit" className="w-full" disabled={isSubmitting}>
                  {isSubmitting ? 'Ukládám…' : 'Změnit heslo'}
                </Button>
              </form>
              <button
                onClick={() => {
                  resetResetState();
                  setView('login');
                }}
                className="mt-4 flex w-full items-center justify-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
              >
                <ArrowLeft className="h-3.5 w-3.5" />
                Zpět na přihlášení
              </button>
            </CardContent>
          </Card>
        )}

        {view === 'reset-success' && (
          <Card>
            <CardHeader>
              <CardTitle>Heslo změněno</CardTitle>
              <CardDescription>Heslo bylo úspěšně změněno</CardDescription>
            </CardHeader>
            <CardContent>
              <Button
                className="w-full"
                onClick={() => {
                  resetResetState();
                  setView('login');
                }}
              >
                Zpět na přihlášení
              </Button>
            </CardContent>
          </Card>
        )}

        {/* Theme toggle */}
        <div className="mt-6 flex justify-center">
          <button
            onClick={toggle}
            className="flex items-center gap-2 rounded-md px-3 py-1.5 text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
            aria-label="Přepnout motiv"
          >
            {theme === 'dark' ? <Sun className="h-4 w-4" /> : <Moon className="h-4 w-4" />}
            {theme === 'dark' ? 'Světlý režim' : 'Tmavý režim'}
          </button>
        </div>
      </div>
    </div>
  );
}
