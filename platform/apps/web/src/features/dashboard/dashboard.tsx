'use client';

import Link from 'next/link';
import { FormEvent, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import {
  AuthenticationRequiredError,
  createInvitation,
  type DashboardAuditEvent,
  type DashboardInvitation,
  loadWorkspaceOverview,
} from './api';
import { useDashboardContext } from './dashboard-context';
import { type ActivityDraft, loadCatalogWorkspace } from '@/features/catalog/admin/api';
import { buildTasks, greeting, plural, type Task } from './tasks';
import { loadApplications } from '@/features/enrollment/api';
import { OrganizationStatusCard } from '@/features/organizations/application/status-card';
import styles from './home.module.css';

const roleLabels: Record<string, string> = {
  platform_admin: 'Платформен администратор',
  organization_owner: 'Собственик',
  organization_admin: 'Администратор',
  coordinator: 'Координатор',
  instructor: 'Преподавател / треньор',
  finance: 'Финанси',
  viewer: 'Само преглед',
};

const invitationStatusLabels: Record<string, string> = {
  pending: 'Очаква приемане',
  accepted: 'Приета',
  expired: 'Изтекла',
};

const auditActionLabels: Record<string, string> = {
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
  'enrollment.session_excluded': 'Пропуснато занимание по заявка на родител',
  'enrollment.transferred': 'Дете, преместено в друга група',
  'enrollment.ended': 'Прекратено записване',
};

const visibleInvitations = 5;

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
  const [invitations, setInvitations] = useState<DashboardInvitation[]>();
  const [auditEvents, setAuditEvents] = useState<DashboardAuditEvent[]>();
  const [loadError, setLoadError] = useState<string>();
  const [formError, setFormError] = useState<string>();
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [oneTimeToken, setOneTimeToken] = useState<string>();

  useEffect(() => {
    let active = true;
    Promise.all([
      loadWorkspaceOverview(organization.id),
      loadCatalogWorkspace(organization.id).then((workspace) => workspace.activities).catch(() => [] as ActivityDraft[]),
      // Roles without access to enrollments simply see no such task.
      loadApplications(organization.id).then((items) => items.length).catch(() => 0),
    ]).then(([overview, catalogActivities, pending]) => {
      if (!active) return;
      setPendingApplications(pending);
      setInvitations(overview.invitations);
      setAuditEvents(overview.auditEvents);
      setActivities(catalogActivities);
    }).catch((reason: unknown) => {
      if (!active) return;
      if (reason instanceof AuthenticationRequiredError) router.replace('/login');
      else setLoadError('Част от таблото не се зареди. Опитайте да презаредите страницата.');
    });
    return () => { active = false; };
  }, [organization.id, router]);

  async function handleInvitation(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const invitationForm = event.currentTarget;
    const form = new FormData(invitationForm);
    setFormError(undefined);
    setOneTimeToken(undefined);
    setIsSubmitting(true);
    try {
      const result = await createInvitation(organization.id, String(form.get('email') ?? ''), String(form.get('role') ?? 'instructor'));
      setInvitations((current) => [result.invitation, ...(current ?? [])]);
      setOneTimeToken(result.token);
      invitationForm.reset();
    } catch (reason) {
      setFormError(reason instanceof Error ? reason.message : 'Поканата не беше изпратена.');
    } finally {
      setIsSubmitting(false);
    }
  }

  const isLoaded = activities !== undefined && invitations !== undefined;
  const tasks = isLoaded ? buildTasks(activities, invitations, pendingApplications) : [];
  const published = activities?.filter((activity) => activity.status === 'published').length ?? 0;
  const firstName = user.name.split(/\s+/)[0];

  return (
    <div className={styles.page}>
      <header className={styles.header}>
        <div className={styles.heading}>
          <p className={styles.eyebrow}>{greeting(new Date())}, {firstName}</p>
          <h1>{organization.name}</h1>
          <p className={styles.muted}>{roleLabels[organization.role] ?? 'Член на екипа'}{activities ? ` · ${plural(published, 'публикувана дейност', 'публикувани дейности')}` : ''}</p>
        </div>
        <Link className={styles.primary} href="/dashboard/activities?new=1">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
          Създай нова дейност
        </Link>
      </header>

      {organization.status !== 'active' ? <OrganizationStatusCard organizationId={organization.id} /> : null}
      {loadError ? <p className={styles.error} role="alert">{loadError}</p> : null}

      <section aria-labelledby="tasks-title" className={styles.section}>
        <h2 id="tasks-title" className={styles.sectionTitle}>Какво чака вас</h2>
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
      </section>

      <section aria-labelledby="shortcuts-title" className={styles.section}>
        <h2 id="shortcuts-title" className={styles.sectionTitle}>Бърз достъп</h2>
        <div className={styles.shortcuts}>
          <Link href="/dashboard/activities" className={styles.shortcut}><strong>Дейности</strong><span>{activities ? plural(activities.length, 'дейност', 'дейности') : '…'}</span></Link>
          <Link href="/dashboard/open-days" className={styles.shortcut}><strong>Отворени врати</strong><span>Кампании и пробни занимания</span></Link>
          <Link href={`/o/${organization.slug}`} className={styles.shortcut}><strong>Публичен профил</strong><span>Как ви виждат родителите</span></Link>
        </div>
      </section>

      <section id="team" aria-labelledby="team-title" className={styles.twoColumns}>
        <article className={styles.panel}>
          <h2 id="team-title">Покани колега</h2>
          <form className={styles.form} onSubmit={handleInvitation}>
            <label htmlFor="invitation-email">Имейл</label>
            <input id="invitation-email" name="email" type="email" autoComplete="email" required />
            <label htmlFor="invitation-role">Роля</label>
            <select id="invitation-role" name="role" defaultValue="instructor">
              <option value="organization_admin">Администратор</option>
              <option value="coordinator">Координатор</option>
              <option value="instructor">Преподавател / треньор</option>
              <option value="finance">Финанси</option>
              <option value="viewer">Само преглед</option>
            </select>
            <button type="submit" disabled={isSubmitting} aria-busy={isSubmitting}>{isSubmitting ? 'Изпращане…' : 'Изпрати покана'}</button>
          </form>
          {oneTimeToken ? (
            <div className={styles.notice} role="status">
              <strong>Поканата е създадена</strong>
              <p>Кодът за приемане е показан само в тестовата среда. В реална работа колегата ще го получи по имейл.</p>
              <code>{oneTimeToken}</code>
            </div>
          ) : null}
          {formError ? <p className={styles.error} role="alert">{formError}</p> : null}
        </article>

        <article className={styles.panel}>
          <h2>Екип и покани</h2>
          {invitations === undefined ? <span className={styles.skeleton} /> : invitations.length === 0 ? (
            <p className={styles.muted}>Все още не сте поканили колеги.</p>
          ) : (
            <ul className={styles.list}>
              {invitations.slice(0, visibleInvitations).map((invitation) => (
                <li key={invitation.id}>
                  <span className={styles.listMain}><strong>{invitation.email}</strong><span>{roleLabels[invitation.role] ?? 'Член на екипа'}</span></span>
                  <span className={`${styles.badge} ${styles[`badge_${invitation.status}`] ?? ''}`}>{invitationStatusLabels[invitation.status] ?? 'Неизвестен статус'}</span>
                </li>
              ))}
            </ul>
          )}
          {invitations && invitations.length > visibleInvitations
            ? <p className={styles.muted}>и още {plural(invitations.length - visibleInvitations, 'покана', 'покани')}</p>
            : null}
        </article>
      </section>

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
    </div>
  );
}
