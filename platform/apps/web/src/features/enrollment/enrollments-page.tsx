'use client';

import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useDashboardContext } from '@/features/dashboard/dashboard-context';
import { type ApplicationFacets, type ApplicationPage, type DetailsTarget, loadApplications, loadStaffGroups, reviewApplication, type ReviewDecision, type StaffApplication, type StaffGroup } from './api';
import { ChildDetailsPanel, DetailsName } from './child-details';
import { GroupsWorkspace } from './groups-workspace';
import { ownScheduleOnly } from '../dashboard/navigation';
import {
  applicationPlaces, dateText, dateTimeText, needsGroupPlace, priceText, scopeLabels, waitingQueues, waitingText,
} from './presentation';
import styles from './enrollments.module.css';

type Tab = 'submitted' | 'waitlisted' | 'groups';
/** A link from "Какво ви чака" can open a view directly (?view=waitlisted, ?view=groups). */
function initialTab(view: string | null): Tab {
  return view === 'waitlisted' || view === 'groups' ? view : 'submitted';
}
/** Filters appear once a list is long enough to need them. */
const FILTERS_FROM = 4;
/** Applications shown at once; "Покажи още" adds the next ones. */
const PAGE = 20;

type RowProps = {
  application: StaffApplication;
  busy: boolean;
  canManage: boolean;
  /** In a queue under its group: the heading already names the activity and the group. */
  inQueue?: boolean;
  onDecide: (decision: ReviewDecision, reason?: string) => void;
  /** Opens the "Подробности" panel; the button is given back the focus when it closes. */
  onDetails: (opener: HTMLElement) => void;
};

function ApplicationRow({ application, busy, canManage, inQueue = false, onDecide, onDetails }: RowProps) {
  const [rejecting, setRejecting] = useState(false);
  const [reason, setReason] = useState('');
  const waiting = application.status === 'waitlisted';
  // Only a place for the whole period depends on the group's count (a trial or one-off visit has its own session).
  const places = needsGroupPlace(application) ? applicationPlaces(application.group) : undefined;
  // A waiting child cannot be approved into a full group; a new application can still go to the waiting list.
  const blocked = waiting && places?.full === true;
  const Heading = inQueue ? 'h4' : 'h3';
  const name = application.child.name ?? 'Дете';
  return (
    <article className={styles.appRow} aria-labelledby={`app-${application.id}`}>
      <div className={styles.appWho}>
        <Heading id={`app-${application.id}`} className={styles.child}><DetailsName name={name} onOpen={onDetails} /></Heading>
        <span className={styles.small}>{application.child.class_label ? `${application.child.class_label} · ` : ''}заявка от {dateText(application.submitted_at)}</span>
        {application.parent_name ? <span className={styles.small}>Родител: {application.parent_name}</span> : null}
      </div>
      <div className={styles.appWhat}>
        {!inQueue ? <span className={styles.meta}>{application.activity.title} · {application.group.name}</span> : null}
        <span className={styles.small}>
          {application.visit_offer_title ?? scopeLabels[application.scope] ?? 'Записване'}
          {application.session_starts_at ? ` · ${dateTimeText(application.session_starts_at)}` : ''}
          {' · '}{priceText(application.price)}
        </span>
        {!inQueue && places ? <span className={places.full ? styles.smallWarning : styles.placeFree}>{places.text}</span> : null}
        {waiting && application.public_reason ? <span className={styles.small}>Казахме на родителя: „{application.public_reason}“</span> : null}
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
        <div className={`${styles.actions} ${styles.appActions}`}>
          <button type="button" className={blocked ? styles.secondary : styles.primary} disabled={busy || blocked} aria-describedby={blocked ? (inQueue ? `queue-full-${application.group.id}` : `full-${application.id}`) : undefined} onClick={() => onDecide('approve')}>
            {busy ? 'Запазване…' : 'Одобри'}<span className={styles.srOnly}> {name}</span>
          </button>
          {!waiting ? <button type="button" className={styles.secondary} disabled={busy} onClick={() => onDecide('waitlist')}>Към чакащите<span className={styles.srOnly}> {name}</span></button> : null}
          <button type="button" className={styles.quiet} disabled={busy} onClick={() => setRejecting(true)}>Откажи<span className={styles.srOnly}> {name}</span></button>
          {/* In a queue the heading already says the group is full. */}
          {blocked && !inQueue ? <span id={`full-${application.id}`} className={styles.smallWarning}>Няма свободно място</span> : null}
        </div>
      )}
    </article>
  );
}

type FilterValues = { search: string; activityId: string; groupId: string };

function ApplicationFiltersBar({ facets, values, total, byParent, onChange, onClear }: { facets?: ApplicationFacets; values: FilterValues; total?: number; byParent: boolean; onChange: (values: FilterValues) => void; onClear: () => void }) {
  const active = values.search.trim() !== '' || values.activityId !== '' || values.groupId !== '';
  // On a phone the filters fold behind one button; the button says when a filter is on.
  const [open, setOpen] = useState(false);
  const activities = facets?.activities ?? [];
  const groups = facets?.groups ?? [];
  const groupOption = (group: ApplicationFacets['groups'][number]) => <option key={group.id} value={group.id}>{group.name} ({group.count})</option>;
  return (
    <div className={styles.filterArea}>
      <button type="button" className={`${styles.secondary} ${styles.filtersToggle}`} aria-expanded={open} aria-controls="applications-filters" onClick={() => setOpen(!open)}>
        Търсене и филтри{active ? ' · включени' : ''}
      </button>
      <div id="applications-filters" className={`${styles.filters} ${open ? styles.filtersOpen : ''}`} role="search" aria-label="Търсене в заявките">
        <div className={styles.filterField}>
          {/* The parent's name is searched only for those who see contacts. */}
          <label htmlFor="applications-search">{byParent ? 'Дете или родител' : 'Дете'}</label>
          <input id="applications-search" type="search" value={values.search} placeholder={byParent ? 'Име на детето или родителя' : 'Име на детето'} maxLength={100} onChange={(event) => onChange({ ...values, search: event.target.value })} />
        </div>
        <div className={styles.filterField}>
          <label htmlFor="applications-activity">Дейност</label>
          <select id="applications-activity" value={values.activityId} onChange={(event) => onChange({ ...values, activityId: event.target.value, groupId: '' })}>
            <option value="">Всички дейности</option>
            {activities.map((activity) => <option key={activity.id} value={activity.id}>{activity.title ?? 'Дейност'} ({activity.count})</option>)}
          </select>
        </div>
        <div className={styles.filterField}>
          <label htmlFor="applications-group">Група</label>
          <select id="applications-group" value={values.groupId} onChange={(event) => onChange({ ...values, groupId: event.target.value })}>
            <option value="">Всички групи</option>
            {/* Without a chosen activity, groups are listed under their activity: "Група 1" alone is ambiguous. */}
            {values.activityId
              ? groups.filter((group) => group.activity_id === values.activityId).map(groupOption)
              : activities.map((activity) => (
                <optgroup key={activity.id} label={activity.title ?? 'Дейност'}>
                  {groups.filter((group) => group.activity_id === activity.id).map(groupOption)}
                </optgroup>
              ))}
          </select>
        </div>
        {active ? <button type="button" className={styles.quiet} onClick={onClear}>Изчисти филтрите</button> : null}
        <p className={styles.small} role="status">{active && total !== undefined ? `Намерени: ${total}` : ''}</p>
      </div>
    </div>
  );
}

/** Waiting children under their group, in the order they applied, with the places the group has today. */
function WaitingQueues({ queues, counts, busyId, canManage, onDecide, onDetails }: { queues: StaffApplication[][]; counts: Map<string, number>; busyId: string; canManage: boolean; onDecide: (application: StaffApplication, decision: ReviewDecision, reason?: string) => void; onDetails: (target: DetailsTarget, opener: HTMLElement) => void }) {
  return (
    <div className={styles.queues}>
      {queues.map((items) => {
        const places = applicationPlaces(items[0].group);
        return (
          <section key={items[0].group.id} className={styles.queue} aria-labelledby={`queue-${items[0].group.id}`}>
            <h3 id={`queue-${items[0].group.id}`} className={styles.queueTitle}>
              {items[0].activity.title} · {items[0].group.name}
              <span className={styles.queueMeta}>
                <span className={styles.small}>{waitingText(counts.get(items[0].group.id) ?? items.length)} · по реда на заявките</span>
                {places ? <span id={`queue-full-${items[0].group.id}`} className={places.full ? styles.smallWarning : styles.placeFree}>{places.text}</span> : null}
              </span>
            </h3>
            <ol className={styles.queueList}>
              {items.map((application) => (
                <li key={application.id}>
                  <ApplicationRow application={application} canManage={canManage} inQueue busy={busyId === application.id} onDecide={(decision, reason) => onDecide(application, decision, reason)}
                    onDetails={(opener) => onDetails({ kind: 'application', id: application.id }, opener)} />
                </li>
              ))}
            </ol>
          </section>
        );
      })}
    </div>
  );
}

const noFilters: FilterValues = { search: '', activityId: '', groupId: '' };

export function EnrollmentsPage() {
  const { organization } = useDashboardContext();
  const searchParams = useSearchParams();
  const router = useRouter();
  // A teacher without "Вижда децата и записванията" comes from "Начало" to their own groups only: no applications
  // and no waiting lists (DECISIONS 31.10.2026).
  const canView = organization.permissions.includes('enrollment.view');
  const tab: Tab = canView ? initialTab(searchParams.get('view')) : 'groups';
  const [submitted, setSubmitted] = useState<ApplicationPage>();
  const [waitlisted, setWaitlisted] = useState<ApplicationPage>();
  const [groups, setGroups] = useState<StaffGroup[]>();
  const [groupsError, setGroupsError] = useState('');
  const canManage = organization.permissions.includes('enrollment.manage');
  // How a group approves is set with the activities, not with "Записва деца". "Подробности" (the child's record) needs
  // "Вижда децата и записванията": a teacher's own list already has what they need.
  const canChangeApproval = organization.permissions.includes('catalog.manage');
  const canSeeContacts = organization.permissions.includes('enrollment.contacts');
  // One "Подробности" panel at a time; closing it gives the focus back to the button that opened it.
  // The eye next to a child in "График" (docs/product/schedule.md, т. 4) opens the group's list with the child's
  // "Подробности" on top, for those who see the enrollments (`?enrollment=`).
  const [details, setDetails] = useState<DetailsTarget | undefined>(() => {
    const id = canView ? searchParams.get('enrollment') : null;
    return id ? { kind: 'enrollment', id } : undefined;
  });
  const detailsOpener = useRef<HTMLElement | null>(null);
  const openDetails = useCallback((target: DetailsTarget, opener: HTMLElement) => {
    detailsOpener.current = opener;
    setDetails(target);
  }, []);
  const closeDetails = useCallback(() => {
    setDetails(undefined);
    // Opened from a link, the panel gives the focus to the group it came with.
    requestAnimationFrame(() => (detailsOpener.current?.isConnected ? detailsOpener.current : document.getElementById('selected-group-title'))?.focus());
  }, []);
  const openGroup = searchParams.get('group') ?? undefined;
  const changeView = (next: Tab, groupId?: string) => {
    const params = new URLSearchParams(searchParams.toString());
    params.set('view', next); params.delete('enrollment');
    if (next === 'groups' && groupId) params.set('group', groupId); else params.delete('group');
    router.push(`/dashboard/enrollments?${params}`, { scroll: false });
  };
  // Opened from "Какво ви чака": once the groups are loaded, bring that group into view and focus it.
  const linkedGroup = useRef(searchParams.get('group'));
  useEffect(() => {
    if (!groups || !linkedGroup.current) return;
    const button = document.getElementById('selected-group-title');
    linkedGroup.current = null;
    button?.scrollIntoView({ block: 'start' });
    button?.focus();
  }, [groups]);
  const [busyId, setBusyId] = useState('');
  const [error, setError] = useState('');
  // The decided row disappears: the message takes the focus so it is seen and read, also on a phone.
  // A new object each time, so the same text said twice still moves the focus.
  const [notice, setNotice] = useState<{ text: string }>();
  const noticeRef = useRef<HTMLParagraphElement>(null);
  useEffect(() => {
    if (!notice) return;
    noticeRef.current?.scrollIntoView({ block: 'nearest' });
    noticeRef.current?.focus();
  }, [notice]);
  const [shownCount, setShownCount] = useState(PAGE);
  const errorRef = useRef<HTMLParagraphElement>(null);
  useEffect(() => {
    if (!error) return;
    errorRef.current?.scrollIntoView({ block: 'nearest' });
    errorRef.current?.focus();
  }, [error]);

  // Filters for "Заявки" and "Чакащи": the name is searched a moment after typing stops.
  const [filters, setFilters] = useState<FilterValues>(noFilters);
  const [query, setQuery] = useState('');
  const [listVersion, setListVersion] = useState(0);
  const [filtered, setFiltered] = useState<{ key: string; page: ApplicationPage }>();
  useEffect(() => {
    const timer = setTimeout(() => setQuery(filters.search.trim()), 300);
    return () => clearTimeout(timer);
  }, [filters.search]);
  const filtering = tab !== 'groups' && (query !== '' || filters.activityId !== '' || filters.groupId !== '');
  const filterKey = `${tab}|${query}|${filters.activityId}|${filters.groupId}|${listVersion}`;
  useEffect(() => {
    if (!filtering) return;
    let active = true;
    loadApplications(organization.id, tab, { q: query, activityId: filters.activityId, groupId: filters.groupId })
      .then((page) => { if (active) setFiltered({ key: filterKey, page }); })
      .catch((reason: unknown) => { if (active) setError(reason instanceof Error ? reason.message : 'Заявките не се заредиха.'); });
    return () => { active = false; };
  }, [filtering, filterKey, organization.id, tab, query, filters.activityId, filters.groupId]);

  const refresh = useCallback(async () => {
    if (!canView) return;
    const [newOnes, waiting] = await Promise.all([loadApplications(organization.id, 'submitted'), loadApplications(organization.id, 'waitlisted')]);
    setSubmitted(newOnes);
    setWaitlisted(waiting);
    setListVersion((version) => version + 1);
  }, [organization.id, canView]);

  // After a change in a group the counts follow: the group list (without closing the open group)
  // and the "Заявки / Чакащи" counters (a waiting child may have been approved from the group).
  const refreshGroups = useCallback(() => {
    loadStaffGroups(organization.id).then(setGroups).catch(() => undefined);
    refresh().catch(() => undefined);
  }, [organization.id, refresh]);

  useEffect(() => {
    if (!canView) return;
    let active = true;
    Promise.all([loadApplications(organization.id, 'submitted'), loadApplications(organization.id, 'waitlisted')])
      .then(([newOnes, waiting]) => { if (active) { setSubmitted(newOnes); setWaitlisted(waiting); } })
      .catch((reason: unknown) => { if (active) setError(reason instanceof Error ? reason.message : 'Заявките не се заредиха.'); });
    return () => { active = false; };
  }, [organization.id, canView]);

  useEffect(() => {
    if (tab !== 'groups' || groups) return;
    let active = true;
    loadStaffGroups(organization.id)
      .then((items) => { if (active) setGroups(items); })
      .catch(() => { if (active) { setGroupsError('Групите не се заредиха. Проверете връзката и опитайте отново.'); setGroups([]); } });
    return () => { active = false; };
  }, [tab, groups, organization.id]);

  function chooseTab(next: Tab) {
    changeView(next);
    setFilters(noFilters);
    setQuery('');
    setShownCount(PAGE);
  }

  function changeFilters(next: FilterValues) {
    setFilters(next);
    setShownCount(PAGE);
  }

  async function decide(application: StaffApplication, decision: ReviewDecision, reason?: string) {
    setBusyId(application.id); setError(''); setNotice(undefined);
    try {
      const result = await reviewApplication(organization.id, application, decision, reason);
      const name = application.child.name ?? 'Детето';
      const group = application.group.name ?? 'групата';
      const text = result.status === 'approved' ? `${name} е записано в „${group}“. Родителят ще види решението в профила си.`
        // "Одобри" on a full group puts the child on the waiting list: say why.
        : result.status === 'waitlisted' && decision === 'approve' ? `Групата „${group}“ е пълна, затова ${name} е добавено в чакащите.`
          : result.status === 'waitlisted' ? `${name} е в списъка на чакащите за „${group}“.`
            : `Заявката за ${name} е отказана. Родителят ще види обяснението.`;
      setNotice({ text });
      await refresh();
      if (groups) await loadStaffGroups(organization.id).then(setGroups).catch(() => undefined);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Решението не беше запазено.');
      await refresh().catch(() => undefined);
    } finally { setBusyId(''); }
  }

  const tabs: { id: Tab; label: string; title: string; count?: number }[] = canView ? [
    { id: 'submitted', label: 'Заявки', title: 'Заявки, които чакат решение', count: submitted?.total },
    { id: 'waitlisted', label: 'Чакащи', title: 'Списък на чакащите', count: waitlisted?.total },
    { id: 'groups', label: 'Групи', title: 'Групи и записани деца' },
  ] : [{ id: 'groups', label: 'Групи', title: 'Вашите групи и децата в тях' }];
  const unfiltered = tab === 'submitted' ? submitted : waitlisted;
  const page = filtering ? (filtered?.key === filterKey ? filtered.page : undefined) : unfiltered;
  const list = page?.applications;
  // "Чакащи" grows by whole queues, so a group's waiting children are never split across the page.
  const queues = tab === 'waitlisted' && list ? (() => {
    const shown: StaffApplication[][] = [];
    let count = 0;
    for (const queue of waitingQueues(list)) {
      if (count >= shownCount) break;
      shown.push(queue);
      count += queue.length;
    }
    return shown;
  })() : undefined;
  const visible = queues ? queues.flat() : list?.slice(0, shownCount);
  const hidden = list && visible ? list.length - visible.length : 0;
  // Waiting counts per group from the API (all of them, not only the ones shown).
  const queueCounts = new Map((waitlisted?.facets?.groups ?? []).map((group) => [group.id, group.count]));
  const showFilters = tab !== 'groups' && (filtering || filters.search !== '' || (unfiltered?.total ?? 0) >= FILTERS_FROM);

  return (
    <div className={`${styles.page} ${tab === 'groups' && openGroup ? styles.selectedPage : ''}`}>
      <header className={styles.header}>
        <p className={styles.eyebrow}>Управление</p>
        <h1>{canView ? 'Записвания' : 'Моите групи'}</h1>
        <p className={styles.muted}>{canView ? 'Решете заявките на родителите и вижте кой е във всяка група.' : 'Децата във всяка група, която водите.'}</p>
      </header>

      {tabs.length > 1 ? (
        <div className={styles.tabs} role="group" aria-label="Изглед">
          {tabs.map((item) => (
            <button key={item.id} type="button" aria-pressed={tab === item.id} className={`${styles.tab} ${tab === item.id ? styles.tabOn : ''}`} onClick={() => chooseTab(item.id)}>
              {item.label}{item.count !== undefined ? <span className={styles.count}>{item.count}</span> : null}
            </button>
          ))}
        </div>
      ) : null}

      <p ref={noticeRef} tabIndex={-1} className={notice ? styles.success : styles.srOnly} role="status">{notice?.text ?? ''}</p>
      {error ? <p ref={errorRef} tabIndex={-1} className={styles.error} role="alert">{error}</p> : null}

      {canView && !canManage ? <p className={styles.muted}>Вашата роля позволява само преглед. Решенията се вземат от собственика, администратора или координатора.</p> : null}

      <section aria-labelledby="enrollments-view-title" aria-busy={tab === 'groups' ? groups === undefined : list === undefined} className={styles.panel}>
        <h2 id="enrollments-view-title" className={styles.srOnly}>{tabs.find((item) => item.id === tab)?.title}</h2>
        {tab !== 'groups' ? (
          <>
            {showFilters ? (
              <ApplicationFiltersBar facets={unfiltered?.facets} values={filters} total={page?.total} byParent={canSeeContacts}
                onChange={changeFilters} onClear={() => { changeFilters(noFilters); setQuery(''); }} />
            ) : null}
            {list === undefined || visible === undefined ? <span className={styles.skeleton} aria-busy="true" /> : list.length === 0 ? (
              <div className={styles.empty}>
                <strong>{filtering ? 'Няма заявки, които отговарят на търсенето' : tab === 'submitted' ? 'Няма нови заявки' : 'Никой не чака място'}</strong>
                <p>{filtering ? 'Променете търсенето или изчистете филтрите.' : tab === 'submitted' ? 'Когато родител подаде заявка, тя ще се появи тук и в „Изисква внимание“.' : 'Тук ще са децата, които чакат свободно място в пълна група.'}</p>
              </div>
            ) : tab === 'waitlisted' ? (
              <WaitingQueues queues={queues ?? []} counts={filtering ? new Map() : queueCounts} busyId={busyId} canManage={canManage} onDecide={decide} onDetails={openDetails} />
            ) : (
              <div className={styles.appList}>
                {visible.map((application) => <ApplicationRow key={application.id} application={application} canManage={canManage} busy={busyId === application.id} onDecide={(decision, reason) => decide(application, decision, reason)}
                  onDetails={(opener) => openDetails({ kind: 'application', id: application.id }, opener)} />)}
              </div>
            )}
            {hidden > 0 ? (
              <div className={styles.more}>
                <span className={styles.small}>Показани {visible?.length} от {page?.total ?? list?.length}</span>
                <button type="button" className={styles.secondary} onClick={() => setShownCount((count) => count + PAGE)}>Покажи още {Math.min(PAGE, hidden)}</button>
              </div>
            ) : page?.has_more && list ? <p className={styles.muted}>Показани са първите {list.length} от {page.total}. Решете тези или стеснете търсенето.</p> : null}
          </>
        ) : groups === undefined ? <span className={styles.skeleton} aria-busy="true" /> : groupsError ? (
          <div className={styles.empty} role="alert">
            <strong>{groupsError}</strong>
            <p><button type="button" className={styles.secondary} onClick={() => { setGroupsError(''); setGroups(undefined); }}>Опитай отново</button></p>
          </div>
        ) : groups.length === 0 ? (
          <div className={styles.empty}>
            <strong>{canView ? 'Все още няма групи' : 'Все още нямате групи'}</strong>
            <p>{canView ? <>Групите се създават в стъпка 7 на всяка дейност. <Link href="/dashboard/activities">Към дейностите</Link></> : 'Когато ви добавят в група, тя ще се появи тук и на „Начало“.'}</p>
          </div>
        ) : (
          <GroupsWorkspace groups={groups} organizationId={organization.id} canManage={canManage} canChangeApproval={canChangeApproval} openGroup={openGroup} ownSchedule={ownScheduleOnly(organization)}
            onSelectGroup={(groupId) => changeView('groups', groupId)} onChanged={refreshGroups} onDetails={canView ? openDetails : undefined} />
        )}
      </section>
      {details ? <ChildDetailsPanel key={`${details.kind}-${details.id}`} organizationId={organization.id} target={details} onClose={closeDetails} /> : null}
    </div>
  );
}
