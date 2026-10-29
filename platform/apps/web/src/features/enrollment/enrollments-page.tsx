'use client';

import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useDashboardContext } from '@/features/dashboard/dashboard-context';
import { type ApplicationFacets, type ApplicationPage, loadApplications, loadStaffGroups, reviewApplication, type ReviewDecision, type StaffApplication, type StaffGroup } from './api';
import { GroupRosterPanel } from './group-roster';
import {
  activitySummary, dateText, dateTimeText, type GroupFilter, groupFill, groupFilterLabels, groupsByActivity, matchesGroupFilter,
  matchesGroupSearch, priceText, scheduleText, scopeLabels,
} from './presentation';
import styles from './enrollments.module.css';

type Tab = 'submitted' | 'waitlisted' | 'groups';
/** A link from "Какво чака вас" can open a view directly (?view=waitlisted, ?view=groups). */
function initialTab(view: string | null): Tab {
  return view === 'waitlisted' || view === 'groups' ? view : 'submitted';
}
/** Filters appear once a list is long enough to need them. */
const FILTERS_FROM = 4;
/** With more activities than this, the group list opens folded, one activity at a time. */
const OPEN_ACTIVITIES_UP_TO = 4;

function ApplicationRow({ application, busy, canManage, headingLevel = 'h3', onDecide }: { application: StaffApplication; busy: boolean; canManage: boolean; headingLevel?: 'h3' | 'h4'; onDecide: (decision: ReviewDecision, reason?: string) => void }) {
  const [rejecting, setRejecting] = useState(false);
  const [reason, setReason] = useState('');
  const waiting = application.status === 'waitlisted';
  const Heading = headingLevel;
  return (
    <article className={styles.appRow} aria-labelledby={`app-${application.id}`}>
      <div className={styles.appWho}>
        <Heading id={`app-${application.id}`} className={styles.child}>{application.child.name ?? 'Дете'}</Heading>
        <span className={styles.small}>{application.child.class_label ? `${application.child.class_label} · ` : ''}заявка от {dateText(application.submitted_at)}</span>
      </div>
      <div className={styles.appWhat}>
        <span className={styles.meta}>{application.activity.title} · {application.group.name}</span>
        <span className={styles.small}>
          {application.visit_offer_title ?? scopeLabels[application.scope] ?? 'Записване'}
          {application.session_starts_at ? ` · ${dateTimeText(application.session_starts_at)}` : ''}
          {' · '}{priceText(application.price)}
        </span>
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
          <button type="button" className={styles.primary} disabled={busy} onClick={() => onDecide('approve')}>{busy ? 'Запазване…' : 'Одобри'}<span className={styles.srOnly}> {application.child.name}</span></button>
          {!waiting ? <button type="button" className={styles.secondary} disabled={busy} onClick={() => onDecide('waitlist')}>Към чакащите<span className={styles.srOnly}> {application.child.name}</span></button> : null}
          <button type="button" className={styles.quiet} disabled={busy} onClick={() => setRejecting(true)}>Откажи<span className={styles.srOnly}> {application.child.name}</span></button>
        </div>
      )}
    </article>
  );
}

type FilterValues = { search: string; activityId: string; groupId: string };

function ApplicationFiltersBar({ facets, values, shown, total, onChange, onClear }: { facets?: ApplicationFacets; values: FilterValues; shown?: number; total?: number; onChange: (values: FilterValues) => void; onClear: () => void }) {
  const groups = (facets?.groups ?? []).filter((group) => !values.activityId || group.activity_id === values.activityId);
  const active = values.search.trim() !== '' || values.activityId !== '' || values.groupId !== '';
  return (
    <div className={styles.filters} role="search" aria-label="Търсене в заявките">
      <div className={styles.filterField}>
        <label htmlFor="applications-search">Дете</label>
        <input id="applications-search" type="search" value={values.search} placeholder="Име на детето" maxLength={100} onChange={(event) => onChange({ ...values, search: event.target.value })} />
      </div>
      <div className={styles.filterField}>
        <label htmlFor="applications-activity">Дейност</label>
        <select id="applications-activity" value={values.activityId} onChange={(event) => onChange({ ...values, activityId: event.target.value, groupId: '' })}>
          <option value="">Всички дейности</option>
          {(facets?.activities ?? []).map((activity) => <option key={activity.id} value={activity.id}>{activity.title ?? 'Дейност'} ({activity.count})</option>)}
        </select>
      </div>
      <div className={styles.filterField}>
        <label htmlFor="applications-group">Група</label>
        <select id="applications-group" value={values.groupId} onChange={(event) => onChange({ ...values, groupId: event.target.value })}>
          <option value="">Всички групи</option>
          {groups.map((group) => <option key={group.id} value={group.id}>{group.name} ({group.count})</option>)}
        </select>
      </div>
      {active ? <button type="button" className={styles.quiet} onClick={onClear}>Изчисти</button> : null}
      <p className={styles.small} role="status">{active && shown !== undefined && total !== undefined ? `Намерени: ${total}` : ''}</p>
    </div>
  );
}

/** Waiting children under their group, in the order they applied. */
function WaitingQueues({ list, busyId, canManage, onDecide }: { list: StaffApplication[]; busyId: string; canManage: boolean; onDecide: (application: StaffApplication, decision: ReviewDecision, reason?: string) => void }) {
  const queues = new Map<string, StaffApplication[]>();
  for (const application of list) queues.set(application.group.id, [...(queues.get(application.group.id) ?? []), application]);
  return (
    <div className={styles.queues}>
      {[...queues.values()].map((items) => (
        <section key={items[0].group.id} className={styles.queue} aria-labelledby={`queue-${items[0].group.id}`}>
          <h3 id={`queue-${items[0].group.id}`} className={styles.queueTitle}>
            {items[0].activity.title} · {items[0].group.name}
            <span className={styles.small}>{items.length === 1 ? '1 чака' : `${items.length} чакат`} · по реда на заявките</span>
          </h3>
          <ol className={styles.queueList}>
            {items.map((application) => (
              <li key={application.id}>
                <ApplicationRow application={application} canManage={canManage} headingLevel="h4" busy={busyId === application.id} onDecide={(decision, reason) => onDecide(application, decision, reason)} />
              </li>
            ))}
          </ol>
        </section>
      ))}
    </div>
  );
}

function GroupsView({ groups, organizationId, canManage, openGroup, onToggleGroup, onChanged }: { groups: StaffGroup[]; organizationId: string; canManage: boolean; openGroup?: string; onToggleGroup: (groupId: string) => void; onChanged: () => void }) {
  const [search, setSearch] = useState('');
  const [filter, setFilter] = useState<GroupFilter>('all');
  // Many activities open folded, except the one with the group opened from a link.
  const [folded, setFolded] = useState<Set<string>>(() => {
    const sections = groupsByActivity(groups);
    if (sections.length <= OPEN_ACTIVITIES_UP_TO) return new Set();
    return new Set(sections.filter((section) => !section.groups.some((group) => group.id === openGroup)).map((section) => section.id));
  });
  const narrowing = search.trim() !== '' || filter !== 'all';
  const sections = groupsByActivity(groups.filter((group) => matchesGroupFilter(group, filter) && matchesGroupSearch(group, search)));
  const filters: GroupFilter[] = ['all', 'free', 'full', 'waiting'];

  function toggleActivity(id: string) {
    setFolded((current) => {
      const next = new Set(current);
      if (next.has(id)) next.delete(id); else next.add(id);
      return next;
    });
  }

  return (
    <>
      {groups.length > FILTERS_FROM ? (
        <div className={styles.filters} role="search" aria-label="Търсене в групите">
          <div className={styles.filterField}>
            <label htmlFor="groups-search">Дейност, група или място</label>
            <input id="groups-search" type="search" value={search} placeholder="Например: шахмат" maxLength={100} onChange={(event) => setSearch(event.target.value)} />
          </div>
          <div className={styles.chips} role="group" aria-label="Покажи">
            {filters.map((item) => (
              <button key={item} type="button" className={`${styles.chip} ${filter === item ? styles.chipOn : ''}`} aria-pressed={filter === item} onClick={() => setFilter(item)}>
                {groupFilterLabels[item]} <span className={styles.chipCount}>{groups.filter((group) => matchesGroupFilter(group, item)).length}</span>
              </button>
            ))}
          </div>
        </div>
      ) : null}
      {sections.length === 0 ? (
        <div className={styles.empty} role="status">
          <strong>Няма групи, които отговарят на търсенето</strong>
          <p><button type="button" className={styles.secondary} onClick={() => { setSearch(''); setFilter('all'); }}>Покажи всички групи</button></p>
        </div>
      ) : (
        <div className={styles.activities}>
          {sections.map((section) => {
            const open = narrowing || !folded.has(section.id);
            const label = (
              <span className={styles.activityText}>
                <span className={styles.activityTitle}>{section.title}</span>
                <span className={styles.small}>{section.category ? `${section.category} · ` : ''}{activitySummary(section)}</span>
              </span>
            );
            return (
              <section key={section.id} className={styles.activity} aria-labelledby={`activity-${section.id}`}>
                <h3 className={styles.activityHeading} id={`activity-${section.id}`}>
                  {narrowing ? <span className={styles.activityStatic}>{label}</span> : (
                    <button type="button" className={styles.activityButton} aria-expanded={open} onClick={() => toggleActivity(section.id)}>
                      {label}<span className={styles.toggleSign} aria-hidden="true">{open ? '−' : '+'}</span>
                    </button>
                  )}
                </h3>
                {open ? (
                  <ul className={styles.groupList}>
                    {section.groups.map((group) => {
                      const expanded = openGroup === group.id;
                      const fill = groupFill(group);
                      const details = [scheduleText(group.schedule), group.location_name].filter(Boolean).join(' · ');
                      return (
                        <li key={group.id} className={styles.groupItem}>
                          <h4 className={styles.groupHeading}>
                            <button id={`group-${group.id}`} type="button" className={styles.groupButton} aria-expanded={expanded} onClick={() => onToggleGroup(group.id)}>
                              <span className={styles.groupMain}>
                                <strong>{group.name}</strong>
                                <span className={styles.small}>{details || 'Денят и часът още не са въведени'}</span>
                              </span>
                              <span className={styles.groupStats}>
                                <span className={styles.fill}>
                                  {fill.percent !== null ? <span className={styles.meter} aria-hidden="true"><span className={fill.full ? styles.meterFull : styles.meterValue} style={{ width: `${fill.percent}%` }} /></span> : null}
                                  <span className={fill.full ? styles.fullText : undefined}>{fill.text}</span>
                                </span>
                                {group.waitlist_count > 0 ? <span className={styles.waitBadge}>{group.waitlist_count} чакащи</span> : null}
                              </span>
                              <span className={styles.toggleSign} aria-hidden="true">{expanded ? '−' : '+'}</span>
                            </button>
                          </h4>
                          {expanded ? <GroupRosterPanel organizationId={organizationId} groupId={group.id} canManage={canManage} onChanged={onChanged} /> : null}
                        </li>
                      );
                    })}
                  </ul>
                ) : null}
              </section>
            );
          })}
        </div>
      )}
    </>
  );
}

const noFilters: FilterValues = { search: '', activityId: '', groupId: '' };

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
    if (!filtering || tab === 'groups') return;
    let active = true;
    loadApplications(organization.id, tab, { q: query, activityId: filters.activityId, groupId: filters.groupId })
      .then((page) => { if (active) setFiltered({ key: filterKey, page }); })
      .catch((reason: unknown) => { if (active) setError(reason instanceof Error ? reason.message : 'Заявките не се заредиха.'); });
    return () => { active = false; };
  }, [filtering, filterKey, organization.id, tab, query, filters.activityId, filters.groupId]);

  const refresh = useCallback(async () => {
    const [newOnes, waiting] = await Promise.all([loadApplications(organization.id, 'submitted'), loadApplications(organization.id, 'waitlisted')]);
    setSubmitted(newOnes);
    setWaitlisted(waiting);
    setListVersion((version) => version + 1);
  }, [organization.id]);

  // After a change in a group the counts follow: the group list (without closing the open group)
  // and the "Заявки / Чакащи" counters (a waiting child may have been approved from the group).
  const refreshGroups = useCallback(() => {
    loadStaffGroups(organization.id).then(setGroups).catch(() => undefined);
    refresh().catch(() => undefined);
  }, [organization.id, refresh]);

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

  function chooseTab(next: Tab) {
    setTab(next);
    setFilters(noFilters);
    setQuery('');
  }

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
  const unfiltered = tab === 'submitted' ? submitted : waitlisted;
  const page = filtering ? (filtered?.key === filterKey ? filtered.page : undefined) : unfiltered;
  const list = page?.applications;
  const showFilters = tab !== 'groups' && (filtering || filters.search !== '' || (unfiltered?.total ?? 0) >= FILTERS_FROM);

  return (
    <div className={styles.page}>
      <header className={styles.header}>
        <p className={styles.eyebrow}>Управление</p>
        <h1>Записвания</h1>
        <p className={styles.muted}>Решете заявките на родителите и вижте кой е във всяка група.</p>
      </header>

      <div className={styles.tabs} role="group" aria-label="Изглед">
        {tabs.map((item) => (
          <button key={item.id} type="button" aria-pressed={tab === item.id} className={`${styles.tab} ${tab === item.id ? styles.tabOn : ''}`} onClick={() => chooseTab(item.id)}>
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
          <>
            {showFilters ? (
              <ApplicationFiltersBar facets={unfiltered?.facets} values={filters} shown={list?.length} total={page?.total}
                onChange={setFilters} onClear={() => { setFilters(noFilters); setQuery(''); }} />
            ) : null}
            {list === undefined ? <span className={styles.skeleton} aria-busy="true" /> : list.length === 0 ? (
              <div className={styles.empty}>
                <strong>{filtering ? 'Няма заявки, които отговарят на търсенето' : tab === 'submitted' ? 'Няма нови заявки' : 'Никой не чака място'}</strong>
                <p>{filtering ? 'Променете търсенето или изчистете филтрите.' : tab === 'submitted' ? 'Когато родител подаде заявка, тя ще се появи тук и в „Какво чака вас“.' : 'Тук ще са децата, които чакат свободно място в пълна група.'}</p>
              </div>
            ) : tab === 'waitlisted' ? (
              <WaitingQueues list={list} busyId={busyId} canManage={canManage} onDecide={decide} />
            ) : (
              <div className={styles.appList}>
                {list.map((application) => <ApplicationRow key={application.id} application={application} canManage={canManage} busy={busyId === application.id} onDecide={(decision, reason) => decide(application, decision, reason)} />)}
              </div>
            )}
            {page?.has_more && list ? <p className={styles.muted}>Показани са първите {list.length} от {page.total}. Решете тези или стеснете търсенето.</p> : null}
          </>
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
          <GroupsView groups={groups} organizationId={organization.id} canManage={canManage} openGroup={openGroup}
            onToggleGroup={(groupId) => setOpenGroup((current) => current === groupId ? undefined : groupId)} onChanged={refreshGroups} />
        )}
      </section>
    </div>
  );
}
