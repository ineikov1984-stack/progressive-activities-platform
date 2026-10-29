'use client';

import Link from 'next/link';
import { useCallback, useEffect, useState } from 'react';
import { useDashboardContext } from '@/features/dashboard/dashboard-context';
import { type ActivityGroup, loadCatalogWorkspace, loadGroups } from '@/features/catalog/admin/api';
import { type ApplicationPage, type GroupRoster, loadApplications, loadRoster, reviewApplication, type ReviewDecision, type StaffApplication } from './api';
import { dateText, dateTimeText, placesText, priceText, scopeLabels } from './presentation';
import styles from './enrollments.module.css';

type Tab = 'submitted' | 'waitlisted' | 'groups';
type GroupEntry = { group: ActivityGroup; activityTitle: string };

function ApplicationCard({ application, busy, canManage, onDecide }: { application: StaffApplication; busy: boolean; canManage: boolean; onDecide: (decision: ReviewDecision, reason?: string) => void }) {
  const [rejecting, setRejecting] = useState(false);
  const [reason, setReason] = useState('');
  const waiting = application.status === 'waitlisted';
  return (
    <article className={styles.card} aria-labelledby={`app-${application.id}`}>
      <div className={styles.cardMain}>
        <h3 id={`app-${application.id}`} className={styles.child}>{application.child.name ?? 'Дете'}</h3>
        <p className={styles.meta}>{application.activity.title} · {application.group.name}</p>
        <p className={styles.meta}>
          {application.visit_offer_title ?? scopeLabels[application.scope] ?? 'Записване'}
          {application.session_starts_at ? ` · ${dateTimeText(application.session_starts_at)}` : ''}
          {' · '}{priceText(application.price)}
        </p>
        <p className={styles.small}>Заявка от {dateText(application.submitted_at)}{application.child.class_label ? ` · ${application.child.class_label}` : ''}</p>
        {waiting && application.public_reason ? <p className={styles.small}>Казахме на родителя: „{application.public_reason}“</p> : null}
      </div>
      {!canManage ? null : rejecting ? (
        <form className={styles.reject} onSubmit={(event) => { event.preventDefault(); onDecide('reject', reason); }}>
          <label htmlFor={`reason-${application.id}`}>Обяснение за родителя</label>
          <textarea id={`reason-${application.id}`} value={reason} required maxLength={1000} placeholder="Например: групата е за по-големи деца. Предлагаме ви групата в четвъртък." onChange={(event) => setReason(event.target.value)} />
          <div className={styles.actions}>
            <button type="submit" className={styles.danger} disabled={busy || !reason.trim()}>Откажи заявката</button>
            <button type="button" className={styles.quiet} onClick={() => setRejecting(false)}>Назад</button>
          </div>
        </form>
      ) : (
        <div className={styles.actions}>
          <button type="button" className={styles.primary} disabled={busy} onClick={() => onDecide('approve')}>{busy ? 'Запазване…' : 'Одобри'}</button>
          {!waiting ? <button type="button" className={styles.secondary} disabled={busy} onClick={() => onDecide('waitlist')}>Към чакащите</button> : null}
          <button type="button" className={styles.quiet} disabled={busy} onClick={() => setRejecting(true)}>Откажи</button>
        </div>
      )}
    </article>
  );
}

function RosterPanel({ organizationId, entry }: { organizationId: string; entry: GroupEntry }) {
  const [roster, setRoster] = useState<GroupRoster>();
  const [error, setError] = useState('');
  useEffect(() => {
    let active = true;
    loadRoster(organizationId, entry.group.id)
      .then((value) => { if (active) setRoster(value); })
      .catch((reason: unknown) => { if (active) setError(reason instanceof Error ? reason.message : 'Списъкът не се зареди.'); });
    return () => { active = false; };
  }, [organizationId, entry.group.id]);

  if (error) return <p className={styles.error} role="alert">{error}</p>;
  if (!roster) return <span className={styles.skeleton} aria-busy="true" />;
  return (
    <div className={styles.roster}>
      <p className={styles.places}>
        <strong>{roster.group.enrolled_for_period}</strong> записани за периода{roster.group.capacity_max ? ` от ${roster.group.capacity_max}` : ''} · {placesText(roster.group.remaining)}
        {roster.enrollments.length > roster.group.enrolled_for_period ? ` · ${roster.enrollments.length - roster.group.enrolled_for_period} с еднократно или пробно посещение` : ''}
      </p>
      {roster.enrollments.length === 0 ? <p className={styles.muted}>Все още няма записани деца.</p> : (
        <ul className={styles.list}>
          {roster.enrollments.map((item) => (
            <li key={item.id}>
              <span className={styles.listName}>{item.child.name}</span>
              <span className={styles.small}>{scopeLabels[item.scope] ?? 'Записване'} · от {dateText(item.starts_on)}{item.ends_on ? ` до ${dateText(item.ends_on)}` : ''}</span>
              {item.status === 'paused' ? <span className={styles.badgePaused}>Пауза</span> : null}
            </li>
          ))}
        </ul>
      )}
      {roster.waitlist.length > 0 ? (
        <>
          <h4 className={styles.subTitle}>Чакащи</h4>
          <ol className={styles.list}>
            {roster.waitlist.map((item) => <li key={item.id}><span className={styles.listName}>{item.child.name}</span><span className={styles.small}>от {dateText(item.submitted_at)}</span></li>)}
          </ol>
        </>
      ) : null}
      <p className={styles.small}>Скоро оттук: ръчно добавяне на дете, преместване, пауза и прекратяване.</p>
    </div>
  );
}

export function EnrollmentsPage() {
  const { organization } = useDashboardContext();
  const [tab, setTab] = useState<Tab>('submitted');
  const [submitted, setSubmitted] = useState<ApplicationPage>();
  const [waitlisted, setWaitlisted] = useState<ApplicationPage>();
  const [groups, setGroups] = useState<GroupEntry[]>();
  const [groupsError, setGroupsError] = useState('');
  const canManage = organization.permissions.includes('enrollment.manage');
  const canView = organization.permissions.includes('enrollment.view');
  const [openGroup, setOpenGroup] = useState<string>();
  const [busyId, setBusyId] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const refresh = useCallback(async () => {
    const [newOnes, waiting] = await Promise.all([loadApplications(organization.id, 'submitted'), loadApplications(organization.id, 'waitlisted')]);
    setSubmitted(newOnes);
    setWaitlisted(waiting);
  }, [organization.id]);

  useEffect(() => {
    let active = true;
    Promise.all([loadApplications(organization.id, 'submitted'), loadApplications(organization.id, 'waitlisted')])
      .then(([newOnes, waiting]) => { if (active) { setSubmitted(newOnes); setWaitlisted(waiting); } })
      .catch((reason: unknown) => { if (active) setError(reason instanceof Error ? reason.message : 'Заявките не се заредиха.'); });
    return () => { active = false; };
  }, [organization.id]);

  useEffect(() => {
    if (tab !== 'groups' || groups) return;
    let active = true;
    loadCatalogWorkspace(organization.id)
      .then(async (workspace) => {
        const activities = workspace.activities.filter((activity) => activity.status !== 'archived');
        const lists = await Promise.all(activities.map((activity) => loadGroups(organization.id, activity.id).then((items) => items.map((group) => ({ group, activityTitle: activity.title })))));
        if (active) setGroups(lists.flat());
      })
      .catch(() => { if (active) { setGroupsError('Групите не се заредиха. Проверете връзката и опитайте отново.'); setGroups([]); } });
    return () => { active = false; };
  }, [tab, groups, organization.id]);

  async function decide(application: StaffApplication, decision: ReviewDecision, reason?: string) {
    setBusyId(application.id); setError(''); setNotice('');
    try {
      const result = await reviewApplication(organization.id, application, decision, reason);
      const name = application.child.name ?? 'Детето';
      setNotice(result.status === 'approved' ? `${name} е записано в „${application.group.name}“. Родителят ще види решението в профила си.`
        : result.status === 'waitlisted' ? `${name} е в списъка на чакащите за „${application.group.name}“.`
          : `Заявката за ${name} е отказана. Родителят ще види обяснението.`);
      await refresh();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Решението не беше запазено.');
      await refresh().catch(() => undefined);
    } finally { setBusyId(''); }
  }

  const tabs: { id: Tab; label: string; count?: number }[] = [
    { id: 'submitted', label: 'Чакат решение', count: submitted?.total },
    { id: 'waitlisted', label: 'Списък на чакащите', count: waitlisted?.total },
    { id: 'groups', label: 'Групи' },
  ];
  const page = tab === 'submitted' ? submitted : waitlisted;
  const list = page?.applications;

  return (
    <div className={styles.page}>
      <header className={styles.header}>
        <p className={styles.eyebrow}>Управление</p>
        <h1>Записвания</h1>
        <p className={styles.muted}>Решете заявките на родителите и вижте кой е във всяка група.</p>
      </header>

      <div className={styles.tabs} role="group" aria-label="Изглед">
        {tabs.map((item) => (
          <button key={item.id} type="button" aria-pressed={tab === item.id} className={`${styles.tab} ${tab === item.id ? styles.tabOn : ''}`} onClick={() => setTab(item.id)}>
            {item.label}{item.count !== undefined ? <span className={styles.count}>{item.count}</span> : null}
          </button>
        ))}
      </div>

      {notice ? <p className={styles.success} role="status">{notice}</p> : null}
      {error ? <p className={styles.error} role="alert">{error}</p> : null}

      {canView && !canManage ? <p className={styles.muted}>Вашата роля позволява само преглед. Решенията се вземат от собственика, администратора или координатора.</p> : null}

      <section aria-label={tabs.find((item) => item.id === tab)?.label} aria-busy={tab === 'groups' ? groups === undefined : list === undefined} className={styles.panel}>
        {tab !== 'groups' ? (
          list === undefined ? <span className={styles.skeleton} aria-busy="true" /> : list.length === 0 ? (
            <div className={styles.empty}>
              <strong>{tab === 'submitted' ? 'Няма нови заявки' : 'Никой не чака място'}</strong>
              <p>{tab === 'submitted' ? 'Когато родител подаде заявка, тя ще се появи тук и в „Какво чака вас“.' : 'Тук ще са децата, които чакат свободно място в пълна група.'}</p>
            </div>
          ) : (
            <div className={styles.cards}>
              {list.map((application) => <ApplicationCard key={application.id} application={application} canManage={canManage} busy={busyId === application.id} onDecide={(decision, reason) => decide(application, decision, reason)} />)}
              {page?.has_more ? <p className={styles.muted}>Показани са първите {list.length} от {page.total}. Решете тези и списъкът ще продължи.</p> : null}
            </div>
          )
        ) : groups === undefined ? <span className={styles.skeleton} aria-busy="true" /> : groupsError ? (
          <div className={styles.empty} role="alert">
            <strong>{groupsError}</strong>
            <p><button type="button" className={styles.secondary} onClick={() => { setGroupsError(''); setGroups(undefined); }}>Опитай отново</button></p>
          </div>
        ) : groups.length === 0 ? (
          <div className={styles.empty}>
            <strong>Все още няма групи</strong>
            <p>Групите се създават в стъпка 7 на всяка дейност. <Link href="/dashboard/activities">Към дейностите</Link></p>
          </div>
        ) : (
          <ul className={styles.groupList}>
            {groups.map((entry) => {
              const open = openGroup === entry.group.id;
              return (
                <li key={entry.group.id} className={styles.groupItem}>
                  <button type="button" className={styles.groupButton} aria-expanded={open} onClick={() => setOpenGroup(open ? undefined : entry.group.id)}>
                    <span><strong>{entry.group.name}</strong><span className={styles.small}>{entry.activityTitle}{entry.group.capacity_max ? ` · до ${entry.group.capacity_max} деца` : ''}</span></span>
                    <span aria-hidden="true">{open ? '−' : '+'}</span>
                  </button>
                  {open ? <RosterPanel organizationId={organization.id} entry={entry} /> : null}
                </li>
              );
            })}
          </ul>
        )}
      </section>
    </div>
  );
}
