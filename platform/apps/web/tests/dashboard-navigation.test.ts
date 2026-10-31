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
      ['Екип', 'Такси, отсъствия и отказ'],
    ]);
    expect(groups.flatMap((group) => group.items).every((item) => item.isAvailable && item.href)).toBe(true);
  });

  it('hides destinations the role cannot use', () => {
    const labels = (permissions: string[]) => dashboardNavigation('/dashboard', permissions).flatMap((group) => group.items).map((item) => item.label);
    expect(labels(['view', 'catalog.view'])).not.toContain('Записвания');
    expect(labels(['view', 'catalog.view', 'enrollment.view'])).toContain('Записвания');
    // A teacher has no "Екип"; the roles that see the team do.
    expect(labels(['view', 'catalog.view'])).not.toContain('Екип');
    expect(labels(['view', 'catalog.view', 'team.view'])).toContain('Екип');
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
    expect(active('/dashboard/settings/payment-terms')).toEqual(['Такси, отсъствия и отказ']);
    expect(active('/dashboard/team')).toEqual(['Екип']);
    expect(active('/dashboard/team/member-1')).toEqual(['Екип']);
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
