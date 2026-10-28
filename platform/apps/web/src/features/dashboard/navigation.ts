export type DashboardIconName =
  | 'home'
  | 'activities'
  | 'calendar'
  | 'enrollments'
  | 'open-days'
  | 'finance'
  | 'analytics'
  | 'integrations'
  | 'locations'
  | 'team'
  | 'media'
  | 'settings';

export type DashboardNavItem = {
  id: string;
  label: string;
  icon: DashboardIconName;
  href?: string;
  isAvailable: boolean;
  isActive: boolean;
};

export type DashboardNavGroup = {
  id: 'management' | 'business' | 'organization';
  label: string;
  items: DashboardNavItem[];
};

type NavigationDefinition = Omit<DashboardNavItem, 'isActive'>;

const navigationGroups: Array<Omit<DashboardNavGroup, 'items'> & { items: NavigationDefinition[] }> = [
  {
    id: 'management',
    label: 'Управление',
    items: [
      { id: 'home', label: 'Начало', icon: 'home', href: '/dashboard', isAvailable: true },
      { id: 'activities', label: 'Дейности', icon: 'activities', href: '/dashboard/activities', isAvailable: true },
      { id: 'groups', label: 'Групи и графици', icon: 'calendar', isAvailable: false },
      { id: 'enrollments', label: 'Записвания', icon: 'enrollments', isAvailable: false },
      { id: 'open-days', label: 'Отворени врати', icon: 'open-days', href: '/dashboard/open-days', isAvailable: true },
    ],
  },
  {
    id: 'business',
    label: 'Бизнес',
    items: [
      { id: 'finance', label: 'Финанси', icon: 'finance', isAvailable: false },
      { id: 'analytics', label: 'Анализи', icon: 'analytics', isAvailable: false },
      { id: 'site-integrations', label: 'Сайт и интеграции', icon: 'integrations', isAvailable: false },
    ],
  },
  {
    id: 'organization',
    label: 'Организация',
    items: [
      { id: 'locations', label: 'Места', icon: 'locations', isAvailable: false },
      { id: 'team', label: 'Екип', icon: 'team', href: '/dashboard#team', isAvailable: true },
      { id: 'media', label: 'Медия', icon: 'media', isAvailable: false },
      { id: 'settings', label: 'Настройки', icon: 'settings', isAvailable: false },
    ],
  },
];

function isActivePath(pathname: string, href?: string): boolean {
  if (!href) return false;
  const hrefPath = href.split('#')[0];
  if (hrefPath === '/dashboard') return pathname === hrefPath;
  return pathname === hrefPath || pathname.startsWith(`${hrefPath}/`);
}

export function dashboardNavigation(pathname: string): DashboardNavGroup[] {
  return navigationGroups.map((group) => ({
    ...group,
    items: group.items.map((item) => ({
      ...item,
      isActive: isActivePath(pathname, item.href),
    })),
  }));
}

export function dashboardNavigationStorageKey(userId: string, organizationId: string): string {
  return `progressive:dashboard-nav:${userId}:${organizationId}`;
}

export function parseDashboardNavigationPreference(value: string | null): boolean | undefined {
  if (value === 'collapsed') return true;
  if (value === 'expanded') return false;
  return undefined;
}

export function defaultDashboardNavigationCollapsed(viewportWidth: number): boolean {
  return viewportWidth >= 720 && viewportWidth < 1180;
}
