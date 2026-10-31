import { describe, expect, it } from 'vitest';
import type { ActivityDraft } from '../src/features/catalog/admin/api';
import type { DashboardInvitation } from '../src/features/dashboard/api';
import { buildTasks, freedPlaces, greeting, teachingCard } from '../src/features/dashboard/tasks';

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
    expect(tasks[1]).toMatchObject({ title: '2 неприети покани', href: '/dashboard/team?state=invited' });
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
      { id: 'g1', name: 'Вторник', capacity_max: 10, enrolled_for_period: 9, waitlist_count: 2 },
      { id: 'g2', name: 'Четвъртък', capacity_max: 10, enrolled_for_period: 10, waitlist_count: 3 },
      { id: 'g3', name: 'Събота', capacity_max: null, enrolled_for_period: 4, waitlist_count: 0 },
    ]);
    expect(freed).toEqual([{ id: 'g1', name: 'Вторник', waiting: 2 }]);
    const tasks = buildTasks([draft('a', 'Шахмат', { status: 'published' })], [], 0, freed);
    expect(tasks[0]).toMatchObject({ id: 'freed-places', title: 'Свободно място в „Вторник“ — 2 деца чакат', href: '/dashboard/enrollments?view=groups&group=g1' });
  });

  // DECISIONS 31.10.2026: a teacher has "Начало" and their own groups.
  it('never asks a teacher or an accountant to create a first activity', () => {
    expect(buildTasks([], [], 0, [], false)).toEqual([]);
    expect(buildTasks([], [], 2, [], false).map((task) => task.id)).toEqual(['applications']);
  });

  it('shows each own group with the part in it and the next session, linked to its children', () => {
    const group = {
      group_id: 'g-1', group_name: 'Вторник', activity_id: 'a-1', activity_title: 'Шах', label: 'Шах · Вторник',
      basis: 'assignment', role: 'lead', starts_on: null, ends_on: null, next_session_starts_at: '2026-11-03T15:00:00Z',
    };
    const card = teachingCard(group);
    expect(card).toMatchObject({ id: 'g-1', title: 'Шах · Вторник', href: '/dashboard/enrollments?view=groups&group=g-1' });
    // 17:00 in Sofia; the words around the date follow the browser's calendar data.
    expect(card.detail).toMatch(/^Водещ · следващо: вторник, 3 ноември.*17:00/);
    expect(teachingCard({ ...group, role: 'assistant', next_session_starts_at: null }).detail).toBe('Помощник · няма предстоящи занимания');
    expect(teachingCard({ ...group, basis: 'substitute', role: 'lead' }).detail).toMatch(/^Заместник · следващо: /);
  });
});
