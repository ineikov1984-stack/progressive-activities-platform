import { describe, expect, it } from 'vitest';
import {
  dashboardNavigation,
  defaultDashboardNavigationCollapsed,
  dashboardNavigationStorageKey,
  parseDashboardNavigationPreference,
} from '../src/features/dashboard/navigation';

describe('dashboard navigation', () => {
  it('keeps the approved groups and items in a stable order', () => {
    const groups = dashboardNavigation('/dashboard/activities');

    expect(groups.map((group) => group.label)).toEqual(['Управление', 'Бизнес', 'Организация']);
    expect(groups.map((group) => group.items.map((item) => item.label))).toEqual([
      ['Начало', 'Дейности', 'Групи и графици', 'Записвания', 'Отворени врати'],
      ['Финанси', 'Анализи', 'Сайт и интеграции'],
      ['Места', 'Екип', 'Медия', 'Настройки'],
    ]);
  });

  it('marks nested routes active and never gives inactive destinations a link', () => {
    const groups = dashboardNavigation('/dashboard/activities/activity-1/visit-offers');
    const items = groups.flatMap((group) => group.items);

    expect(items.find((item) => item.label === 'Дейности')).toMatchObject({
      href: '/dashboard/activities',
      isActive: true,
      isAvailable: true,
    });
    const finance = items.find((item) => item.label === 'Финанси');
    expect(finance).toMatchObject({
      isActive: false,
      isAvailable: false,
    });
    expect(finance?.href).toBeUndefined();
    const siteIntegrations = items.find((item) => item.label === 'Сайт и интеграции');
    expect(siteIntegrations).toMatchObject({
      isAvailable: false,
    });
    expect(siteIntegrations?.href).toBeUndefined();
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
