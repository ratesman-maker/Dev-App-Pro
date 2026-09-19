import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

export interface SystemModule {
  [key: string]: boolean;
}

export interface SystemInfoItem {
  name: string;
  running: boolean;
  version: string | null;
  details: Record<string, string>;
  modules?: string[] | SystemModule;
}

export interface SystemInfo {
  php: SystemInfoItem;
  apache: SystemInfoItem;
  mariadb: SystemInfoItem;
  node: SystemInfoItem;
  wpcli: SystemInfoItem;
  mkcert: SystemInfoItem;
  composer: SystemInfoItem;
  ssl_cert: SystemInfoItem;
  disk: SystemInfoItem;
  cron: SystemInfoItem;
}

export function useSystemInfo() {
  return useQuery<SystemInfo>({
    queryKey: ['system-info'],
    queryFn: () => api.get<SystemInfo>('/tools/system-info'),
    refetchInterval: 30000,
  });
}
