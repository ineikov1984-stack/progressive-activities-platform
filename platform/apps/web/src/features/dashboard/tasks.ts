import type { DashboardInvitation } from './api';
import type { ActivityDraft } from '../catalog/admin/api';
import { activityCompleteness } from '../catalog/admin/presentation';

export type Task = { id: string; tone: 'limited' | 'action' | 'info'; title: string; detail: string; href: string };

export function plural(count: number, one: string, many: string): string {
  return `${count} ${count === 1 ? one : many}`;
}

/** A group with a free place and children on its waiting list. */
export type FreedPlace = { id: string; name: string; waiting: number };

export function freedPlaces(groups: { id: string; name: string; capacity_max: number | null; enrolled_for_period: number; waitlist_count: number }[]): FreedPlace[] {
  return groups
    .filter((group) => group.waitlist_count > 0 && (group.capacity_max === null || group.enrolled_for_period < group.capacity_max))
    .map((group) => ({ id: group.id, name: group.name, waiting: group.waitlist_count }));
}

export function buildTasks(activities: ActivityDraft[], invitations: DashboardInvitation[], pendingApplications = 0, freed: FreedPlace[] = []): Task[] {
  const tasks: Task[] = [];
  if (pendingApplications > 0) {
    tasks.push({
      id: 'applications',
      tone: 'action',
      title: `${plural(pendingApplications, 'нова заявка чака', 'нови заявки чакат')} решение`,
      detail: 'Одобрете, поставете в чакащи или откажете',
      href: '/dashboard/enrollments',
    });
  }
  if (freed.length > 0) {
    tasks.push({
      id: 'freed-places',
      tone: 'action',
      title: freed.length === 1
        ? `Свободно място в „${freed[0].name}“ — ${plural(freed[0].waiting, 'дете чака', 'деца чакат')}`
        : `Свободни места в ${freed.length} групи с чакащи деца`,
      detail: 'Отворете групата и одобрете дете от чакащите',
      href: freed.length === 1 ? `/dashboard/enrollments?view=groups&group=${encodeURIComponent(freed[0].id)}` : '/dashboard/enrollments?view=groups',
    });
  }
  const drafts = activities
    .filter((activity) => activity.status === 'draft')
    .map((activity) => ({ activity, completeness: activityCompleteness(activity) }))
    .sort((a, b) => b.completeness.done - a.completeness.done);
  if (drafts.length > 0) {
    const closest = drafts[0];
    tasks.push({
      id: 'drafts',
      tone: 'action',
      title: `${plural(drafts.length, 'чернова не е довършена', 'чернови не са довършени')}`,
      detail: closest.completeness.next
        ? `Най-близо: „${closest.activity.title}“ — следва ${closest.completeness.next.label.toLowerCase()}`
        : `„${closest.activity.title}“ е готова за публикуване`,
      href: `/dashboard/activities/${closest.activity.id}`,
    });
  }
  // "Неприети покани" (docs/product/team.md, т. 10): the people are in "Екип", where an invitation is sent again.
  const pending = invitations.filter((invitation) => invitation.status === 'pending');
  if (pending.length > 0) {
    tasks.push({
      id: 'invitations',
      tone: 'limited',
      title: plural(pending.length, 'неприета покана', 'неприети покани'),
      detail: 'Напомнете на колегите да проверят имейла си или изпратете поканата отново',
      href: '/dashboard/team?state=invited',
    });
  }
  if (activities.length === 0) {
    tasks.push({
      id: 'first-activity',
      tone: 'info',
      title: 'Създайте първата си дейност',
      detail: 'Ще ви преведем стъпка по стъпка',
      href: '/dashboard/activities?new=1',
    });
  }
  return tasks;
}

/** Greeting by the hour in Bulgaria, independent of the browser time zone. */
export function greeting(now: Date): string {
  const hourPart = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', hourCycle: 'h23', timeZone: 'Europe/Sofia' })
    .formatToParts(now)
    .find((part) => part.type === 'hour');
  const hour = Number(hourPart?.value ?? 12);
  if (hour >= 4 && hour < 11) return 'Добро утро';
  if (hour >= 11 && hour < 18) return 'Добър ден';
  return 'Добър вечер';
}
