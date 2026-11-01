'use client';

import Link from 'next/link';
import { useEffect, useRef, useState } from 'react';
import type { DetailsTarget, StaffGroup } from './api';
import { GroupRosterPanel } from './group-roster';
import { type GroupFilter, groupFill, groupFilterLabels, hasPlaceForWaiting, matchesGroupFilter, matchesGroupSearch, scheduleText, sofiaDay, waitingText } from './presentation';
import styles from './enrollments.module.css';

type Props = { groups: StaffGroup[]; organizationId: string; canManage: boolean; canChangeApproval: boolean; ownSchedule: boolean; openGroup?: string; onSelectGroup: (id?: string) => void; onChanged: () => void; onDetails?: (target: DetailsTarget, opener: HTMLElement) => void };

function GroupPlaces({ group }: { group: StaffGroup }) {
  if (typeof group.enrolled_for_period !== 'number') return null;
  const fill = groupFill(group);
  return <span className={styles.fill}>
    {fill.percent !== null ? <span className={styles.meter} aria-hidden="true"><span className={fill.full ? styles.meterFull : styles.meterValue} style={{ width: `${fill.percent}%` }} /></span> : null}
    <span className={fill.full ? styles.fullText : undefined}>{fill.text}</span>
  </span>;
}

/** Select one group; keep the same roster and its existing actions throughout the workspace. */
export function GroupsWorkspace({ groups, organizationId, canManage, canChangeApproval, ownSchedule, openGroup, onSelectGroup, onChanged, onDetails }: Props) {
  const [search, setSearch] = useState('');
  const [filter, setFilter] = useState<GroupFilter>('all');
  const [activity, setActivity] = useState('');
  const [filtersOpen, setFiltersOpen] = useState(false);
  const focusTarget = useRef<string | undefined>(undefined);
  useEffect(() => {
    if (!focusTarget.current) return;
    document.getElementById(focusTarget.current)?.focus();
    focusTarget.current = undefined;
  }, [openGroup]);
  const selected = groups.find(group => group.id === openGroup);
  const activities = [...new Map(groups.map(group => [group.activity_id ?? '', group.activity_title ?? 'Дейност'])).entries()];
  const counts = groups.some(group => typeof group.enrolled_for_period === 'number');
  const filters: GroupFilter[] = counts ? ['all', 'free', 'full', 'waiting'] : ['all'];
  const shown = groups.filter(group => (!activity || group.activity_id === activity) && matchesGroupSearch(group, search) && matchesGroupFilter(group, filter));
  function clear() { setSearch(''); setActivity(''); setFilter('all'); }
  function select(id?: string, focus = true) {
    if (id === openGroup) { if (focus) document.getElementById('selected-group-title')?.focus(); return; }
    focusTarget.current = focus ? (id ? 'selected-group-title' : `open-group-${openGroup}`) : undefined;
    onSelectGroup(id);
  }

  if (openGroup && !selected) return <div className={styles.empty} role="status"><strong>Групата не е в достъпния списък</strong><p>Възможно е вече да е в архива или достъпът ви да е променен.</p><button className={styles.secondary} onClick={() => select()}>Всички групи</button></div>;
  return <div className={styles.groupsWorkspace}>
    {selected ? <>
      <button type="button" className={styles.backToGroups} onClick={() => select()}>← Всички групи</button>
      <div className={styles.mobileGroupChoice}><label htmlFor="selected-group">Избрана група</label><select id="selected-group" value={selected.id} onChange={event => select(event.target.value, false)}>{groups.map(group => <option key={group.id} value={group.id}>{group.activity_title ?? 'Дейност'} · {group.name}</option>)}</select></div>
      <div className={styles.groupWorkspaceLayout}>
        <nav className={styles.groupRail} aria-label="Избор на група">
          {groups.map(group => <button type="button" key={group.id} aria-current={selected.id === group.id ? 'true' : undefined} className={styles.groupRailItem} onClick={() => select(group.id)}><strong>{group.activity_title ?? 'Дейност'} · {group.name}</strong><span>{scheduleText(group.schedule) || 'Без въведен график'}</span></button>)}
        </nav>
        <section className={styles.selectedGroup} aria-labelledby="selected-group-title">
          <header className={styles.selectedGroupHeader}>
            <p className={styles.activityTag}>{selected.activity_title ?? 'Дейност'}</p>
            <h3 id="selected-group-title" tabIndex={-1}>{selected.name}</h3>
            <p className={styles.meta}>{scheduleText(selected.schedule) || 'Денят и часът още не са въведени'}</p>
            {selected.location_name ? <p className={styles.small}>{selected.location_name}</p> : null}
            <div className={styles.groupHeaderActions}><Link className={styles.calendarLink} href={`/dashboard/schedule?view=${ownSchedule ? 'week' : 'day'}&date=${sofiaDay()}&group=${encodeURIComponent(selected.id)}`}>График на групата →</Link></div>
          </header>
          <GroupRosterPanel key={selected.id} organizationId={organizationId} groupId={selected.id} canManage={canManage} canChangeApproval={canChangeApproval} onChanged={onChanged} onDetails={onDetails} />
        </section>
      </div>
    </> : <>
      <div className={styles.groupFilters} role="search" aria-label="Търсене в групите">
        <div className={styles.filterField}><label htmlFor="groups-search">Дейност, група или място</label><input id="groups-search" type="search" value={search} placeholder="Търси група" maxLength={100} onChange={event => setSearch(event.target.value)} /></div>
        <button type="button" className={styles.groupFiltersToggle} aria-expanded={filtersOpen} aria-controls="group-extra-filters" onClick={() => setFiltersOpen(!filtersOpen)}>Филтри{activity || filter !== 'all' ? ' · включени' : ''}</button>
      </div>
      <div id="group-extra-filters" className={`${styles.groupExtraFilters} ${filtersOpen ? styles.groupExtraFiltersOpen : ''}`}>
        {activities.length > 1 ? <div className={styles.filterField}><label htmlFor="groups-activity">Дейност</label><select id="groups-activity" value={activity} onChange={event => setActivity(event.target.value)}><option value="">Всички дейности</option>{activities.map(([id, title]) => <option key={id} value={id}>{title}</option>)}</select></div> : null}
      {filters.length > 1 ? <div className={styles.chips} role="group" aria-label="Покажи">{filters.map(item => <button key={item} type="button" className={`${styles.chip} ${filter === item ? styles.chipOn : ''}`} aria-pressed={filter === item} onClick={() => setFilter(item)}>{groupFilterLabels[item]} <span className={styles.chipCount}>{groups.filter(group => (!activity || group.activity_id === activity) && matchesGroupSearch(group, search) && matchesGroupFilter(group, item)).length}</span></button>)}</div> : null}
        {search || filter !== 'all' || activity ? <button className={styles.quiet} onClick={clear}>Изчисти филтрите</button> : null}
      </div>
      <p className={styles.small} role="status">{shown.length} {shown.length === 1 ? 'група' : 'групи'}</p>
      {!shown.length ? <div className={styles.empty}><strong>Няма групи, които отговарят на търсенето</strong><p>Променете търсенето или изчистете филтрите.</p><button className={styles.secondary} onClick={clear}>Покажи всички групи</button></div> : <div className={styles.groupCards}>{shown.map(group => <article className={styles.groupCard} key={group.id}>
        <p className={styles.activityTag}>{group.activity_title ?? 'Дейност'}</p><h3>{group.name}</h3>
        <p className={styles.meta}>{scheduleText(group.schedule) || 'Без въведен график'}</p>{group.location_name ? <p className={styles.small}>{group.location_name}</p> : null}
        <GroupPlaces group={group} />
        {group.waitlist_count > 0 ? <span className={hasPlaceForWaiting(group) ? styles.workBadge : styles.waitBadge}>{hasPlaceForWaiting(group) ? `Има място · ${waitingText(group.waitlist_count)}` : waitingText(group.waitlist_count)}</span> : null}
        <button type="button" id={`open-group-${group.id}`} className={styles.openGroup} aria-label={`Виж децата — ${group.activity_title ?? 'Дейност'} · ${group.name}`} onClick={() => select(group.id)}>Виж децата →</button>
      </article>)}</div>}
    </>}
  </div>;
}
