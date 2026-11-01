'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import {
  AuthenticationRequiredError,
  type DashboardAuditEvent,
  type DashboardInvitation,
  loadTeaching,
  loadWorkspaceOverview,
  type TeachingGroup,
} from './api';
import { useDashboardContext } from './dashboard-context';
import { type ActivityDraft, loadCatalogWorkspace } from '@/features/catalog/admin/api';
import { buildTasks, type FreedPlace, freedPlaces, greeting, plural, type Task, teachingCard } from './tasks';
import { loadApplications, loadStaffGroups } from '@/features/enrollment/api';
import { OrganizationStatusCard } from '@/features/organizations/application/status-card';
import { roleLabel } from '@/features/team/presentation';
import styles from './home.module.css';
import { canSeePending, loadPending } from '../attendance/api';
import { TodaySection, hasMonth } from '../attendance/today';

const auditActionLabels: Record<string, string> = {
  'schedule.substitute_request_declined': 'Отказана заявка за заместник',
  'schedule.substitute_requested': 'Поискан заместник',
  'schedule.substitute_request_withdrawn': 'Оттеглена заявка за заместник',
  'identity.login_succeeded': 'Вход в системата',
  'organization.context_selected': 'Отворена администрацията на организацията',
  'organization.applicant_created': 'Създаден профил на организация',
  'organization.application_submitted': 'Изпратена заявка за регистрация',
  'organization.application_reviewed': 'Прегледана заявка за регистрация',
  'organization.invitation_created': 'Изпратена покана',
  'organization.role_changed': 'Променена роля',
  'catalog.activity_submitted': 'Дейност, изпратена за преглед',
  'catalog.activity_published': 'Публикувана дейност',
  'catalog.open_day_campaign_created': 'Създадена кампания „Отворени врати“',
  'catalog.open_day_campaign_updated': 'Променена кампания „Отворени врати“',
  'catalog.open_day_campaign_published': 'Публикувана кампания „Отворени врати“',
  'catalog.open_day_campaign_closed': 'Затворена кампания „Отворени врати“',
  'catalog.open_day_campaign_cancelled': 'Отменена кампания „Отворени врати“',
  'catalog.visit_offer_created': 'Създадено пробно занимание',
  'catalog.visit_offer_updated': 'Променено пробно занимание',
  'catalog.visit_offer_published': 'Публикувано пробно занимание',
  'catalog.visit_offer_closed': 'Затворено пробно занимание',
  'catalog.visit_offer_cancelled': 'Отменено пробно занимание',
  'catalog.visit_offer_capacity_changed': 'Променени места за пробно занимание',
  'catalog.visit_offer_deadline_changed': 'Променен срок за пробно занимание',
  'catalog.visit_offer_policy_changed': 'Променени условия за пробно занимание',
  'catalog.visit_offer_price_changed': 'Променена цена за пробно занимание',
  'enrollment.application_submitted': 'Нова заявка за записване',
  'enrollment.application_approved': 'Одобрена заявка за записване',
  'enrollment.application_rejected': 'Отказана заявка за записване',
  'enrollment.application_waitlisted': 'Заявка в списък на чакащите',
  'enrollment.visit_outcome_recorded': 'Отбелязан резултат от пробно занимание',
  'enrollment.application_reviewed': 'Решение по заявка за записване',
  'enrollment.session_excluded': 'Пропуснато занимание',
  'enrollment.session_restored': 'Върнато пропуснато занимание',
  'enrollment.transferred': 'Дете, преместено в друга група',
  'enrollment.paused': 'Пауза в посещенията',
  'enrollment.pause_ended': 'Пауза, приключена по-рано',
  'enrollment.ended': 'Прекратено записване',
  'enrollment.reopened': 'Отменено прекратяване на записване',
  'payment_terms.created': 'Създадени правила за такси, отсъствия и отказ',
  'payment_terms.updated': 'Променени правила за такси, отсъствия и отказ',
  'team.member_added': 'Добавяне в екипа',
  'team.member_updated': 'Промяна на данни в екипа',
  'team.member_archived': 'Преместване в архива',
  'team.role_changed': 'Смяна на роля в екипа',
  'team.invitation_sent': 'Изпращане на покана',
  'team.invitation_accepted': 'Приемане на покана',
  'team.profile_linked': 'Разрешение да води групи',
  'catalog.assignment_added': 'Добавяне в група',
  'catalog.assignment_ended': 'Край на работата в група',
  'catalog.assignment_removed': 'Премахване от група',
};

const taskIconPaths: Record<Task['tone'], string[]> = {
  action: ['M4 20h4L19 9l-4-4L4 16v4z', 'M13.5 6.5l4 4'],
  limited: ['M4 6h16v12H4z', 'M4 7l8 6 8-6'],
  info: ['M12 5v14', 'M5 12h14'],
};

function TaskIcon({ tone }: { tone: Task['tone'] }) {
  return <svg viewBox="0 0 24 24" aria-hidden="true">{taskIconPaths[tone].map((path) => <path key={path} d={path} />)}</svg>;
}

export function Dashboard() {
  const router = useRouter();
  const { user, organization } = useDashboardContext();
  const [activities, setActivities] = useState<ActivityDraft[]>();
  const [pendingApplications, setPendingApplications] = useState(0);
  const [attendancePending, setAttendancePending] = useState<{org:string;total:number}>();
  const canSeeAttendancePending = canSeePending(organization.access?.abilities);
  useEffect(() => {
    if (!canSeeAttendancePending) return;
    const abort = new AbortController();
    const read = () => loadPending(organization.id, abort.signal).then((data) => { if (!abort.signal.aborted) setAttendancePending({org:organization.id,total:data.total}); }).catch(() => {});
    read(); const timer = setInterval(read, 30000); return () => {abort.abort();clearInterval(timer);};
  }, [organization.id, canSeeAttendancePending]);
  const [freed, setFreed] = useState<FreedPlace[]>([]);
  const canManageEnrollments = organization.permissions.includes('enrollment.manage');
  const canSeeEnrollments = organization.permissions.includes('enrollment.view');
  const canInvite = organization.permissions.includes('invite');
  const canSeeTeam = organization.permissions.includes('team.view');
  // A teacher has "Начало" and their own groups: no activities or open days unless a right is added (DECISIONS 31.10.2026).
  const canSeeCatalog = organization.permissions.includes('catalog.view');
  const canCreateActivity = organization.permissions.includes('catalog.manage');
  const [teaching, setTeaching] = useState<TeachingGroup[]>();
  const [invitations, setInvitations] = useState<DashboardInvitation[]>();
  const [auditEvents, setAuditEvents] = useState<DashboardAuditEvent[]>();
  const [loadError, setLoadError] = useState<string>();

  useEffect(() => {
    let active = true;
    Promise.all([
      loadWorkspaceOverview(organization.id, canInvite, canSeeTeam),
      canSeeCatalog
        ? loadCatalogWorkspace(organization.id).then((workspace) => workspace.activities).catch(() => [] as ActivityDraft[])
        : Promise.resolve([] as ActivityDraft[]),
      // Roles without access to enrollments simply see no such task.
      canSeeEnrollments ? loadApplications(organization.id).then((page) => page.total).catch(() => 0) : Promise.resolve(0),
      // Only those who decide on enrollments get the "free place" task.
      canManageEnrollments
        ? loadStaffGroups(organization.id).then(freedPlaces).catch(() => [] as FreedPlace[])
        : Promise.resolve([] as FreedPlace[]),
      // "Моите групи": anyone who teaches — the teacher, also an owner who teaches.
      loadTeaching(organization.id).catch(() => [] as TeachingGroup[]),
    ]).then(([overview, catalogActivities, pending, freedGroups, ownGroups]) => {
      if (!active) return;
      setTeaching(ownGroups);
      setPendingApplications(pending);
      setFreed(freedGroups);
      setInvitations(overview.invitations);
      setAuditEvents(overview.auditEvents);
      setActivities(catalogActivities);
    }).catch((reason: unknown) => {
      if (!active) return;
      if (reason instanceof AuthenticationRequiredError) router.replace('/login');
      else setLoadError('Част от таблото не се зареди. Опитайте да презаредите страницата.');
    });
    return () => { active = false; };
  }, [organization.id, router, canManageEnrollments, canSeeEnrollments, canInvite, canSeeTeam, canSeeCatalog]);

  const isLoaded = activities !== undefined && invitations !== undefined;
  const tasks: Task[] = isLoaded ? buildTasks(activities, invitations, pendingApplications, freed, canCreateActivity) : [];
  if (canSeeAttendancePending && attendancePending?.org === organization.id && attendancePending.total > 0) tasks.unshift({id:'attendance',tone:'action',title:attendancePending.total === 1 ? '1 занимание чака отбелязване' : `${attendancePending.total} занимания чакат отбелязване`,detail:'Отворете заниманията за отбелязване',href:'/dashboard/attendance'});
  const published = activities?.filter((activity) => activity.status === 'published').length ?? 0;
  const firstName = user.name.split(/\s+/)[0];
  // For a teacher their groups are the page; for the others they come after "Какво ви чака".
  const groupsFirst = !canSeeEnrollments;
  // Applications, drafts and invitations are the tasks: without any of them "Какво ви чака" has nothing to say.
  const mayHaveTasks = canSeeEnrollments || canSeeCatalog || canInvite || canSeeAttendancePending;
  const ownGroups = teaching === undefined ? null : teaching.length > 0 || groupsFirst ? (
    <section aria-labelledby="teaching-title" className={styles.section}>
      <h2 id="teaching-title" className={styles.sectionTitle}>Моите групи</h2>
      {hasMonth(teaching) ? <Link className={styles.shortcut} href="/dashboard/attendance?view=month">Присъствия за месеца →</Link> : null}
      {teaching.length === 0 ? (
        <p className={styles.muted}>Все още нямате групи. Когато ви добавят в група, тя ще се появи тук.</p>
      ) : (
        <div className={styles.shortcuts}>
          {teaching.map(teachingCard).map((card) => (
            <Link key={card.id} href={card.href} className={styles.shortcut}><strong>{card.title}</strong><span>{card.detail}</span></Link>
          ))}
        </div>
      )}
    </section>
  ) : null;

  return (
    <div className={styles.page}>
      <header className={styles.header}>
        <div className={styles.heading}>
          {/* The name opens "Моят профил" — for a teacher the way to their details and rights. */}
          <p className={styles.eyebrow}>{greeting(new Date())}, <Link className={styles.nameLink} href="/dashboard/me">{firstName}<span className="sr-only"> — Моят профил</span></Link></p>
          <h1>{organization.name}</h1>
          <p className={styles.muted}>{roleLabel(organization.role)}{activities && canSeeCatalog ? ` · ${plural(published, 'публикувана дейност', 'публикувани дейности')}` : ''}</p>
        </div>
        {canCreateActivity ? (
          <Link className={styles.primary} href="/dashboard/activities?new=1">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
            Създай нова дейност
          </Link>
        ) : null}
      </header>

      {organization.status !== 'active' ? <OrganizationStatusCard organizationId={organization.id} /> : null}
      {loadError ? <p className={styles.error} role="alert">{loadError}</p> : null}

      <TodaySection key={organization.id} organizationId={organization.id} groups={teaching ?? []} />
      {groupsFirst ? ownGroups : null}

      {mayHaveTasks ? <section aria-labelledby="tasks-title" className={styles.section}>
        <h2 id="tasks-title" className={styles.sectionTitle}>Какво ви чака</h2>
        {!isLoaded ? (
          <div className={styles.taskGrid} aria-busy="true">{[0, 1, 2].map((item) => <span className={styles.skeleton} key={item} />)}</div>
        ) : tasks.length === 0 ? (
          <div className={styles.allClear}>
            <span className={styles.allClearIcon} aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 12l5 5 9-10" /></svg></span>
            <div><strong>Всичко е наред</strong><p>Няма чернови или покани, които чакат действие.</p></div>
          </div>
        ) : (
          <div className={styles.taskGrid}>
            {tasks.map((task) => (
              <Link className={styles.task} href={task.href} key={task.id}>
                <span className={`${styles.taskIcon} ${styles[task.tone]}`}><TaskIcon tone={task.tone} /></span>
                <span className={styles.taskText}><strong>{task.title}</strong><span>{task.detail}</span></span>
              </Link>
            ))}
          </div>
        )}
      </section> : null}

      {groupsFirst ? null : ownGroups}

      <section aria-labelledby="shortcuts-title" className={styles.section}>
        <h2 id="shortcuts-title" className={styles.sectionTitle}>Бърз достъп</h2>
        <div className={styles.shortcuts}>
          {canSeeCatalog ? (
            <>
              <Link href="/dashboard/activities" className={styles.shortcut}><strong>Дейности</strong><span>{activities ? plural(activities.length, 'дейност', 'дейности') : '…'}</span></Link>
              <Link href="/dashboard/open-days" className={styles.shortcut}><strong>Отворени врати</strong><span>Кампании и пробни занимания</span></Link>
            </>
          ) : null}
          <Link href={`/o/${organization.slug}`} className={styles.shortcut}><strong>Публичен профил</strong><span>Как ви виждат родителите</span></Link>
          {/* "Покани колега" moved to "Екип" (docs/product/team.md, т. 10): people, their groups and invitations. */}
          {canSeeTeam ? <Link href="/dashboard/team" className={styles.shortcut}><strong>Екип</strong><span>Хора, групи и покани за вход</span></Link> : null}
        </div>
      </section>

      {/* Only the roles that see the team get the last actions; a teacher has no such panel. */}
      {canSeeTeam ? (
        <section aria-labelledby="audit-title" className={styles.panel}>
          <h2 id="audit-title">Последни действия</h2>
          {auditEvents === undefined ? <span className={styles.skeleton} /> : auditEvents.length === 0 ? (
            <p className={styles.muted}>Все още няма записани действия.</p>
          ) : (
            <ul className={styles.list}>
              {auditEvents.slice(0, 8).map((event) => (
                <li key={event.id}>
                  <span className={styles.listMain}><strong>{auditActionLabels[event.action] ?? 'Действие в системата'}</strong></span>
                  <time className={styles.muted} dateTime={event.created_at}>{new Intl.DateTimeFormat('bg-BG', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Europe/Sofia' }).format(new Date(event.created_at))}</time>
                </li>
              ))}
            </ul>
          )}
        </section>
      ) : null}
    </div>
  );
}
