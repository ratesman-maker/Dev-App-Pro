import { useNavigate } from 'react-router-dom';
import { Button } from '@/components/ui/button';

export default function NotFoundPage() {
  const navigate = useNavigate();
  return (
    <div className="flex min-h-screen items-center justify-center bg-background text-foreground">
      <div className="text-center">
        <h1 className="text-4xl font-semibold">404</h1>
        <p className="mt-2 text-muted-foreground">Stránka nenalezena</p>
        <Button className="mt-4" onClick={() => navigate('/')}>
          Zpět na Dashboard
        </Button>
      </div>
    </div>
  );
}
