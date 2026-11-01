import { describe, expect, it } from 'vitest';
import {
  dashboardNavigation,
  mobileDashboardNavigation,
  defaultDashboardNavigationCollapsed,
  dashboardNavigationStorageKey,
  ownScheduleOnly,
  parseDashboardNavigationPreference,
} from '../src/features/dashboard/navigation';

describe('dashboard navigation', () => {
  it('shows only working destinations, grouped in a stable order', () => {
    const groups = dashboardNavigation('/dashboard/activities');

    expect(groups.map((group) => group.label)).toEqual(['Управление', 'Организация']);
    expect(groups.map((group) => group.items.map((item) => item.label))).toEqual([
      ['Начало', 'График', 'Дейности', 'Записвания', 'Присъствия', 'Отворени врати'],
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

  // DECISIONS 31.10.2026 „Учителят — само своите групи; кой одобрява и кой вижда таксите“: the rights as the API gives
  // them (OrganizationRole::abilities, and the added ones with what they bring along).
  it('follows the rights: a teacher has only "Начало" and "Моят график" until a right is added', () => {
    const labels = (permissions: string[], ownSchedule = false) => dashboardNavigation('/dashboard', permissions, { ownSchedule }).flatMap((group) => group.items).map((item) => item.label);
    const teacher = ['view', 'enrollment.view_own'];
    expect(labels(teacher, true)).toEqual(['Начало', 'Моят график']);
    // "Записва деца" brings "Вижда децата и записванията".
    expect(labels([...teacher, 'enrollment.enroll', 'enrollment.view', 'enrollment.manage'], true)).toEqual(['Начало', 'Моят график', 'Записвания']);
    // "Създава и променя дейности" brings the catalog and the payment terms its editor chooses from.
    expect(labels([...teacher, 'catalog.manage', 'catalog.view', 'terms.view'], true)).toEqual(['Начало', 'Моят график', 'Дейности', 'Отворени врати', 'Такси, отсъствия и отказ']);

    expect(labels(['view', 'catalog.view', 'enrollment.view', 'team.view', 'attendance.view_month'])).toEqual(['Начало', 'График', 'Дейности', 'Записвания', 'Присъствия', 'Отворени врати', 'Екип']);
    expect(labels(['finance', 'view', 'enrollment.view', 'team.view', 'attendance.view_month', 'finance.view', 'terms.view']))
      .toEqual(['Начало', 'График', 'Записвания', 'Присъствия', 'Екип', 'Такси, отсъствия и отказ']);
    expect(labels(['view', 'catalog.view', 'catalog.manage', 'enrollment.view', 'team.view', 'terms.view']))
      .toEqual(['Начало', 'График', 'Дейности', 'Записвания', 'Отворени врати', 'Екип', 'Такси, отсъствия и отказ']);
  });

  // docs/product/schedule.md, т. 2: the schedule needs no right of its own; who sees only their own groups has "Моят график".
  it('gives "Моят график" to who sees only their own groups, and "График" to the others', () => {
    const access = (scope: string, abilities: string[] = []) => ({ role: 'instructor', scope, abilities, groups: [], activities: [] });
    expect(ownScheduleOnly({ role: 'instructor', access: access('own', ['enrollment.view_own', 'view']) })).toBe(true);
    // "Вижда целия график" as an added right, or chosen groups, show colleagues' sessions too.
    expect(ownScheduleOnly({ role: 'instructor', access: access('own', ['schedule.view_all']) })).toBe(false);
    expect(ownScheduleOnly({ role: 'instructor', access: access('groups') })).toBe(false);
    expect(ownScheduleOnly({ role: 'organization_owner', access: { ...access('all'), role: 'organization_owner' } })).toBe(false);
    expect(ownScheduleOnly({ role: 'viewer', access: { ...access('groups'), role: 'viewer' } })).toBe(false);
    // Without the rights read, the role decides.
    expect(ownScheduleOnly({ role: 'instructor' })).toBe(true);
    expect(ownScheduleOnly({ role: 'coordinator' })).toBe(false);
    // Same page, same place in the menu.
    const item = (ownSchedule: boolean) => dashboardNavigation('/dashboard/schedule', ['view'], { ownSchedule }).flatMap((group) => group.items).find((entry) => entry.id === 'schedule');
    expect(item(true)).toMatchObject({ label: 'Моят график', href: '/dashboard/schedule', isActive: true });
    expect(item(false)).toMatchObject({ label: 'График', href: '/dashboard/schedule', isActive: true });
  });

  it('shows "Такси, отсъствия и отказ" only with the right to see them', () => {
    const settings = (permissions: string[]) => dashboardNavigation('/dashboard', permissions).flatMap((group) => group.items).some((item) => item.id === 'settings');
    expect(settings(['view', 'catalog.view', 'enrollment.view', 'team.view'])).toBe(false);
    expect(settings(['view', 'terms.view'])).toBe(true);
    expect(settings(['view', 'terms.manage'])).toBe(false);
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
    expect(active('/dashboard/attendance/session')).toEqual(['Присъствия']);
    expect(active('/dashboard/team')).toEqual(['Екип']);
    expect(active('/dashboard/team/member-1')).toEqual(['Екип']);
    expect(active('/dashboard/schedule')).toEqual(['График']);
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

describe('phone navigation', () => {
  it('uses only authorized destinations and preserves the current nested section', () => {
    const nav = mobileDashboardNavigation(dashboardNavigation('/dashboard/attendance/session', ['view', 'enrollment.view', 'attendance.view_month', 'team.view']));
    expect(nav.map(item => item.id)).toEqual(['home', 'schedule', 'enrollments', 'attendance']);
    expect(nav.filter(item => item.isActive).map(item => item.id)).toEqual(['attendance']);
    expect(mobileDashboardNavigation(dashboardNavigation('/dashboard', ['view', 'enrollment.view_own'], { ownSchedule: true })).map(item => item.label)).toEqual(['Начало', 'Моят график']);
  });
});
