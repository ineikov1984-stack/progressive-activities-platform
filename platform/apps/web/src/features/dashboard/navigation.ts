import type { DashboardOrganization } from './api';

export type DashboardIconName =
  | 'home'
  | 'activities'
  | 'calendar'
  | 'attendance'
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
  /** Shown only to members who have this right — by role or added (DECISIONS 31.10.2026). */
  requires?: string;
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
      // Everyone sees the schedule of their scope (docs/product/schedule.md, т. 2); a teacher's is "Моят график".
      { id: 'schedule', label: 'График', icon: 'calendar', href: '/dashboard/schedule', isAvailable: true },
      // A teacher has "Начало" and their own groups; the catalog only with an added right that needs it.
      { id: 'activities', label: 'Дейности', icon: 'activities', href: '/dashboard/activities', isAvailable: true, requires: 'catalog.view' },
      { id: 'groups', label: 'Групи и графици', icon: 'calendar', isAvailable: false },
      { id: 'enrollments', label: 'Записвания', icon: 'enrollments', href: '/dashboard/enrollments', isAvailable: true, requires: 'enrollment.view' },
      { id: 'attendance', label: 'Присъствия', icon: 'attendance', href: '/dashboard/attendance', isAvailable: true, requires: 'attendance.view_month' },
      { id: 'open-days', label: 'Отворени врати', icon: 'open-days', href: '/dashboard/open-days', isAvailable: true, requires: 'catalog.view' },
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
      { id: 'team', label: 'Екип', icon: 'team', href: '/dashboard/team', isAvailable: true, requires: 'team.view' },
      { id: 'media', label: 'Медия', icon: 'media', isAvailable: false },
      // Only "Такси, отсъствия и отказ" (the payment terms) so far; the other settings join it later. Seen by the owner,
      // the administrator, the coordinator and the accountant; changed by the first two.
      { id: 'settings', label: 'Такси, отсъствия и отказ', icon: 'settings', href: '/dashboard/settings/payment-terms', isAvailable: true, requires: 'terms.view' },
    ],
  },
];

function isActivePath(pathname: string, href?: string): boolean {
  // A link to a section of another page (`/dashboard#…`) is never the current page.
  if (!href || href.includes('#')) return false;
  if (href === '/dashboard') return pathname === href;
  return pathname === href || pathname.startsWith(`${href}/`);
}

/**
 * "Моят график" — only one's own sessions, without the filters by teacher and child (docs/product/schedule.md, т. 2,
 * решение от 31.10.2026): who sees their own groups only, without "Вижда целия график". A teacher with selected groups
 * or the whole organization sees colleagues too, so has the office's "График". Without the rights read (`access`), the
 * role decides.
 */
export function ownScheduleOnly(organization: Pick<DashboardOrganization, 'role' | 'access'>): boolean {
  const access = organization.access;
  if (access) return access.scope === 'own' && !access.abilities.includes('schedule.view_all');
  return organization.role === 'instructor';
}

/**
 * The navigation shows only destinations that work today: an unfinished area
 * appears when it ships, so the menu never leads to an empty or fake page.
 */
export function dashboardNavigation(pathname: string, permissions?: string[], options: { ownSchedule?: boolean } = {}): DashboardNavGroup[] {
  return navigationGroups
    .map((group) => ({
      ...group,
      items: group.items
        .filter((item) => item.isAvailable && item.href)
        .filter((item) => !item.requires || !permissions || permissions.includes(item.requires))
        .map((item) => ({
          ...item,
          label: item.id === 'schedule' && options.ownSchedule ? 'Моят график' : item.label,
          isActive: isActivePath(pathname, item.href),
        })),
    }))
    .filter((group) => group.items.length > 0);
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

/** The phone uses the same authorized destinations as the full menu. */
export function mobileDashboardNavigation(groups: DashboardNavGroup[]): DashboardNavItem[] {
  const order = ['home', 'schedule', 'enrollments', 'attendance'];
  const items = groups.flatMap(group => group.items);
  return order.flatMap(id => items.filter(item => item.id === id));
}
