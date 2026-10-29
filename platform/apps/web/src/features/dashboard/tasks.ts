import type { DashboardInvitation } from './api';
import type { ActivityDraft } from '../catalog/admin/api';
import { activityCompleteness } from '../catalog/admin/presentation';

export type Task = { id: string; tone: 'limited' | 'action' | 'info'; title: string; detail: string; href: string };

export function plural(count: number, one: string, many: string): string {
  return `${count} ${count === 1 ? one : many}`;
}

export function buildTasks(activities: ActivityDraft[], invitations: DashboardInvitation[]): Task[] {
  const tasks: Task[] = [];
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
  const pending = invitations.filter((invitation) => invitation.status === 'pending');
  if (pending.length > 0) {
    tasks.push({
      id: 'invitations',
      tone: 'limited',
      title: `${plural(pending.length, 'покана очаква', 'покани очакват')} приемане`,
      detail: 'Напомнете на колегите да проверят имейла си',
      href: '/dashboard#team',
    });
  }
  if (activities.length === 0) {
    tasks.push({
      id: 'first-activity',
      tone: 'info',
      title: 'Създайте първата си дейност',
      detail: 'Ще ви преведем стъпка по стъпка',
      href: '/dashboard/activities',
    });
  }
  return tasks;
}

