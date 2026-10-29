'use client';

import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useDashboardContext } from '@/features/dashboard/dashboard-context';
import { type ApplicationPage, loadApplications, loadStaffGroups, reviewApplication, type ReviewDecision, type StaffApplication, type StaffGroup } from './api';
import { GroupRosterPanel } from './group-roster';
import { dateText, dateTimeText, priceText, scopeLabels } from './presentation';
import styles from './enrollments.module.css';

type Tab = 'submitted' | 'waitlisted' | 'groups';
/** A link from "Какво чака вас" can open a view directly (?view=waitlisted, ?view=groups). */
function initialTab(view: string | null): Tab {
  return view === 'waitlisted' || view === 'groups' ? view : 'submitted';
}

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

export function EnrollmentsPage() {
  const { organization } = useDashboardContext();
  const searchParams = useSearchParams();
  const [tab, setTab] = useState<Tab>(() => initialTab(searchParams.get('view')));
  const [submitted, setSubmitted] = useState<ApplicationPage>();
  const [waitlisted, setWaitlisted] = useState<ApplicationPage>();
  const [groups, setGroups] = useState<StaffGroup[]>();
  const [groupsError, setGroupsError] = useState('');
  const canManage = organization.permissions.includes('enrollment.manage');
  const canView = organization.permissions.includes('enrollment.view');
  const [openGroup, setOpenGroup] = useState<string | undefined>(() => searchParams.get('group') ?? undefined);
  // Opened from "Какво чака вас": once the groups are loaded, bring that group into view and focus it.
  const linkedGroup = useRef(searchParams.get('group'));
  useEffect(() => {
    if (!groups || !linkedGroup.current) return;
    const button = document.getElementById(`group-${linkedGroup.current}`);
    linkedGroup.current = null;
    button?.scrollIntoView({ block: 'start' });
    button?.focus();
  }, [groups]);
  const [busyId, setBusyId] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  // After a change in a group the counts in the group list follow (without closing the open group).
  const refreshGroups = useCallback(() => {
    loadStaffGroups(organization.id).then(setGroups).catch(() => undefined);
  }, [organization.id]);

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
    loadStaffGroups(organization.id)
      .then((items) => { if (active) setGroups(items); })
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

  const tabs: { id: Tab; label: string; title: string; count?: number }[] = [
    { id: 'submitted', label: 'Заявки', title: 'Заявки, които чакат решение', count: submitted?.total },
    { id: 'waitlisted', label: 'Чакащи', title: 'Списък на чакащите', count: waitlisted?.total },
    { id: 'groups', label: 'Групи', title: 'Групи и записани деца' },
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

      <section aria-labelledby="enrollments-view-title" aria-busy={tab === 'groups' ? groups === undefined : list === undefined} className={styles.panel}>
        <h2 id="enrollments-view-title" className={styles.srOnly}>{tabs.find((item) => item.id === tab)?.title}</h2>
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
            {groups.map((group) => {
              const open = openGroup === group.id;
              return (
                <li key={group.id} className={styles.groupItem}>
                  <h3 className={styles.groupHeading}><button id={`group-${group.id}`} type="button" className={styles.groupButton} aria-expanded={open} onClick={() => setOpenGroup(open ? undefined : group.id)}>
                    <span>
                      <strong>{group.name}</strong>
                      <span className={styles.small}>
                        {group.activity_title ?? 'Дейност'} · заети {group.enrolled_for_period}{group.capacity_max ? ` от ${group.capacity_max}` : ''}
                        {group.waitlist_count > 0 ? ` · ${group.waitlist_count} чакащи` : ''}
                      </span>
                    </span>
                    <span aria-hidden="true">{open ? '−' : '+'}</span>
                  </button></h3>
                  {open ? <GroupRosterPanel organizationId={organization.id} groupId={group.id} canManage={canManage} onChanged={refreshGroups} /> : null}
                </li>
              );
            })}
          </ul>
        )}
      </section>
    </div>
  );
}
