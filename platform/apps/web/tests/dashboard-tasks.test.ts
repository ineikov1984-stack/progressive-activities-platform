import { describe, expect, it } from 'vitest';
import type { ActivityDraft } from '../src/features/catalog/admin/api';
import type { DashboardInvitation } from '../src/features/dashboard/api';
import { buildTasks, freedPlaces, greeting } from '../src/features/dashboard/tasks';

const draft = (id: string, title: string, extra: Partial<ActivityDraft> = {}): ActivityDraft => ({
  id, slug: id, status: 'draft', version: 1, title, category: 'Друго',
  summary: 'Добавете кратко описание на дейността.', age_label: 'Добавете възраст',
  hero_image_path: null, sections: {}, offering: null, ...extra,
});

const invitation = (status: string): DashboardInvitation => ({
  id: status, organization_id: 'org-1', email: `${status}@example.test`, role: 'instructor', status, expires_at: '2026-11-01T00:00:00Z',
});

describe('dashboard tasks', () => {
  it('asks for a first activity when the catalog is empty', () => {
    expect(buildTasks([], []).map((task) => task.id)).toEqual(['first-activity']);
  });

  it('points to the draft closest to completion and counts pending invitations only', () => {
    const tasks = buildTasks(
      [draft('a', 'Баскетбол'), draft('b', 'Шахмат', { category: 'Логика и игри', summary: 'Логическо мислене.', age_label: '6–8 г.' })],
      [invitation('pending'), invitation('accepted'), invitation('pending')],
    );

    expect(tasks.map((task) => task.id)).toEqual(['drafts', 'invitations']);
    expect(tasks[0]).toMatchObject({ title: '2 чернови не са довършени', href: '/dashboard/activities/b' });
    expect(tasks[0].detail).toContain('„Шахмат“');
    expect(tasks[1].title).toBe('2 покани очакват приемане');
  });

  it('stays quiet when nothing waits for the user', () => {
    expect(buildTasks([draft('p', 'Плуване', { status: 'published' })], [invitation('accepted')])).toEqual([]);
  });
});

describe('greeting', () => {
  it('uses the hour in Sofia, not the browser format', () => {
    // 12:35 UTC on 29 Oct 2026 is 14:35 in Sofia (EET, UTC+2).
    expect(greeting(new Date('2026-10-29T12:35:00Z'))).toBe('Добър ден');
    expect(greeting(new Date('2026-10-29T06:00:00Z'))).toBe('Добро утро');
    expect(greeting(new Date('2026-10-29T18:30:00Z'))).toBe('Добър вечер');
  });
});

describe('pending applications', () => {
  it('puts new applications first', () => {
    const tasks = buildTasks([], [], 3);
    expect(tasks[0]).toMatchObject({ id: 'applications', title: '3 нови заявки чакат решение', href: '/dashboard/enrollments' });
  });

  it('points to waiting children when a group has a free place', () => {
    const freed = freedPlaces([
      { name: 'Вторник', capacity_max: 10, enrolled_for_period: 9, waitlist_count: 2 },
      { name: 'Четвъртък', capacity_max: 10, enrolled_for_period: 10, waitlist_count: 3 },
      { name: 'Събота', capacity_max: null, enrolled_for_period: 4, waitlist_count: 0 },
    ]);
    expect(freed).toEqual([{ name: 'Вторник', waiting: 2 }]);
    const tasks = buildTasks([draft('a', 'Шахмат', { status: 'published' })], [], 0, freed);
    expect(tasks[0]).toMatchObject({ id: 'freed-places', title: 'Свободно място в „Вторник“ — 2 деца чакат', href: '/dashboard/enrollments?view=waitlisted' });
  });
});
