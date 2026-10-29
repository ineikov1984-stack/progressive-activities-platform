import { describe, expect, it } from 'vitest';
import {
  dashboardNavigation,
  defaultDashboardNavigationCollapsed,
  dashboardNavigationStorageKey,
  parseDashboardNavigationPreference,
} from '../src/features/dashboard/navigation';

describe('dashboard navigation', () => {
  it('shows only working destinations, grouped in a stable order', () => {
    const groups = dashboardNavigation('/dashboard/activities');

    expect(groups.map((group) => group.label)).toEqual(['Управление', 'Организация']);
    expect(groups.map((group) => group.items.map((item) => item.label))).toEqual([
      ['Начало', 'Дейности', 'Записвания', 'Отворени врати'],
      ['Екип'],
    ]);
    expect(groups.flatMap((group) => group.items).every((item) => item.isAvailable && item.href)).toBe(true);
  });

  it('marks exactly one destination active, including nested routes', () => {
    const active = (pathname: string) => dashboardNavigation(pathname)
      .flatMap((group) => group.items)
      .filter((item) => item.isActive)
      .map((item) => item.label);

    expect(active('/dashboard/activities/activity-1/visit-offers')).toEqual(['Дейности']);
    expect(active('/dashboard')).toEqual(['Начало']);
    expect(active('/dashboard/open-days')).toEqual(['Отворени врати']);
    expect(active('/dashboard/enrollments')).toEqual(['Записвания']);
  });

  it('starts expanded on desktop, compact on tablet and leaves mobile to the drawer', () => {
    expect(defaultDashboardNavigationCollapsed(1440)).toBe(false);
    expect(defaultDashboardNavigationCollapsed(1024)).toBe(true);
    expect(defaultDashboardNavigationCollapsed(768)).toBe(true);
    expect(defaultDashboardNavigationCollapsed(390)).toBe(false);
  });

  it('scopes a saved preference to the user and organization', () => {
    expect(dashboardNavigationStorageKey('user-1', 'org-1')).toBe('progressive:dashboard-nav:user-1:org-1');
    expect(dashboardNavigationStorageKey('user-1', 'org-2')).not.toBe(dashboardNavigationStorageKey('user-1', 'org-1'));
  });

  it('accepts only known stored preferences and falls back safely', () => {
    expect(parseDashboardNavigationPreference('collapsed')).toBe(true);
    expect(parseDashboardNavigationPreference('expanded')).toBe(false);
    expect(parseDashboardNavigationPreference('false')).toBeUndefined();
    expect(parseDashboardNavigationPreference('{broken')).toBeUndefined();
    expect(parseDashboardNavigationPreference(null)).toBeUndefined();
  });
});
