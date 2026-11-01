'use client';

import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { Fragment, type KeyboardEvent, useEffect, useRef, useState } from 'react';
import { useDashboardContext } from '@/features/dashboard/dashboard-context';
import { sofiaDay } from '@/features/enrollment/presentation';
import { AddMemberDialog } from './add-member-dialog';
import { type GroupAssignment, loadTeam, type TeamList, type TeamMember } from './api';
import {
  accessAction, foundText, GROUPS_SHOWN, groupLink, groupsByActivity, groupSummary, groupTags, lastLoginText, matchesFilters, memberRole, memberStatus,
  noGroupsText, parseStateFilter, roleLabel, roleLabels, rolesFilterText, stateFilterLabels, type StateFilter, type TeamFilters, type TeamRole,
} from './presentation';
import styles from './team.module.css';

const noFilters: TeamFilters = { search: '', roles: [], state: '' };

/**
 * "Роля" for several roles at once: a button that opens a list of checkboxes (DECISIONS 31.10.2026 „Екип — последен
 * вход и филтър по роли“). A disclosure: Tab goes through the checkboxes, Esc and a click outside close it and the
 * focus goes back to the button. On a phone the list opens under the button, in the page.
 */
function RolesFilter({ roles, onChange }: { roles: TeamRole[]; onChange: (roles: TeamRole[]) => void }) {
  const [open, setOpen] = useState(false);
  const wrapper = useRef<HTMLDivElement>(null);
  const button = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (!open) return;
    function outside(event: MouseEvent) {
      if (wrapper.current && event.target instanceof Node && !wrapper.current.contains(event.target)) setOpen(false);
    }
    document.addEventListener('mousedown', outside);
    return () => document.removeEventListener('mousedown', outside);
  }, [open]);

  function keys(event: KeyboardEvent<HTMLDivElement>) {
    if (event.key !== 'Escape' || !open) return;
    event.preventDefault();
    setOpen(false);
    button.current?.focus();
  }

  function toggle(role: TeamRole, on: boolean) {
    onChange(on ? (Object.keys(roleLabels) as TeamRole[]).filter((item) => item === role || roles.includes(item)) : roles.filter((item) => item !== role));
  }

  return (
    <div ref={wrapper} className={`${styles.field} ${styles.rolesFilter}`} onKeyDown={keys}>
      <span id="team-roles-label" className={styles.label}>Роля</span>
      <button ref={button} id="team-roles" type="button" className={`${styles.input} ${styles.rolesButton}`} aria-expanded={open} aria-controls="team-roles-list"
        aria-labelledby="team-roles-label team-roles" onClick={() => setOpen((value) => !value)}>
        <span>{rolesFilterText(roles)}</span>
        <svg className={styles.rolesChevron} viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
      </button>
      {open ? (
        <fieldset id="team-roles-list" className={styles.rolesList}>
          <legend className="sr-only">Покажи хората с тези роли</legend>
          {(Object.keys(roleLabels) as TeamRole[]).map((role) => (
            <label key={role} className={styles.rolesItem}>
              <input type="checkbox" checked={roles.includes(role)} onChange={(event) => toggle(role, event.target.checked)} />
              <span>{roleLabels[role]}</span>
            </label>
          ))}
          {roles.length > 0 ? (
            <button type="button" className={styles.rowAction} onClick={() => { onChange([]); button.current?.focus(); }}>Всички роли</button>
          ) : null}
        </fieldset>
      ) : null}
    </div>
  );
}

/** The page "Екип" is for every role that sees the team (`team.view`); a teacher gets this state instead. */
export function NoTeamAccess() {
  return (
    <div className={styles.empty} role="note">
      <strong>Страницата „Екип“ не е за вашата роля</strong>
      <p>Ако ви трябва, помолете собственика на организацията да смени ролята ви.</p>
      <p><Link href="/dashboard">Към началото</Link></p>
    </div>
  );
}

/** The group's name, a link to the group's schedule. */
export function GroupName({ assignment }: { assignment: GroupAssignment }) {
  const link = groupLink(assignment);
  return <Link className={styles.groupLink} href={link.href} aria-label={link.label}>{assignment.group}</Link>;
}

/**
 * "Шахмат: Група 1, Група 2 · 3.–4. клас [помощник]" — one line per activity; only "помощник" and a coming start are
 * marked. Each group opens its schedule.
 */
export function ActivityLines({ groups, today }: { groups: GroupAssignment[]; today: string }) {
  return (
    <ul className={styles.activityLines}>
      {groupsByActivity(groups).map((item) => (
        <li key={item.activity}>
          <span className={styles.activityName}>{item.activity}:</span>{' '}
          {item.groups.map((group, index) => (
            <Fragment key={group.group_id}>
              {index > 0 ? ', ' : ''}<GroupName assignment={group} />
              {groupTags(group, today).map((tag) => <span key={tag} className={styles.tag}>{tag}</span>)}
            </Fragment>
          ))}
        </li>
      ))}
    </ul>
  );
}

function Chevron({ open }: { open: boolean }) {
  return (
    <svg className={`${styles.chevron} ${open ? styles.chevronOpen : ''}`} viewBox="0 0 20 20" width="20" height="20" aria-hidden="true" focusable="false">
      <path d="M5 7.5 10 12.5 15 7.5" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

/** Variant Б: up to two groups are shown; from three on, a button with the count and the activities opens them. */
function GroupsCell({ member, today }: { member: TeamMember; today: string }) {
  const [open, setOpen] = useState(false);
  if (member.groups.length === 0) return <span className={styles.none}>{noGroupsText(member)}</span>;
  if (member.groups.length <= GROUPS_SHOWN) return <ActivityLines groups={member.groups} today={today} />;
  const summary = groupSummary(member.groups);
  const id = `groups-${member.id}`;
  return (
    <>
      <button type="button" className={styles.groupsButton} aria-expanded={open} aria-controls={id} onClick={() => setOpen((value) => !value)}>
        <strong>{summary.count}</strong>
        {summary.chips.map((chip) => <span key={chip} className={styles.chip}>{chip}</span>)}
        <Chevron open={open} />
      </button>
      {open ? <div id={id} className={styles.groupsOpen}><ActivityLines groups={member.groups} today={today} /></div> : null}
    </>
  );
}

function MemberRow({ member, canManage, now, today }: { member: TeamMember; canManage: boolean; now: Date; today: string }) {
  const status = memberStatus(member, now);
  const role = memberRole(member);
  const action = accessAction(member, canManage, now);
  return (
    <tr>
      <td className={styles.cellName}>
        <div className={styles.person}>
          <Link className={styles.name} href={`/dashboard/team/${encodeURIComponent(member.id)}`}>{member.full_name}</Link>
          {member.phone ? <span className={styles.contacts}>{member.phone}</span> : null}
        </div>
      </td>
      <td className={styles.cellRole}>{role ? roleLabel(role) : <span className={styles.none}>Няма роля</span>}</td>
      <td className={styles.cellGroups}><span className={styles.mobileLabel} aria-hidden="true">Групи</span><GroupsCell member={member} today={today} /></td>
      <td className={styles.cellAccess}>
        <span className={styles.mobileLabel} aria-hidden="true">Достъп</span>
        <div className={styles.accessCell}>
          <span className={styles.stack}>
            <span className={`${styles.badge} ${styles[`tone_${status.tone}`]}`}>{status.label}</span>
            {status.detail ? <span className={styles.small}>{status.detail}</span> : null}
            {/* Only for those who manage the team, who get the moment. */}
            {status.key === 'login' && member.last_login_at !== undefined ? <span className={styles.small}>{lastLoginText(member.last_login_at, now)}</span> : null}
          </span>
          {action ? <Link className={action.kind === 'button' ? styles.secondary : styles.waiting} href={action.href}>{action.label}</Link> : null}
        </div>
      </td>
    </tr>
  );
}

/**
 * "Екип" (docs/product/team.md, т. 10; DECISIONS 31.10.2026 „Екип — полиран списък и профил“): who is in the
 * organization, their role, the groups they lead and their access. Search and filters work on the loaded list;
 * "В архива" loads the archived records.
 */
export function TeamPage() {
  const { organization } = useDashboardContext();
  const searchParams = useSearchParams();
  const canView = organization.permissions.includes('team.view');
  const canManage = organization.permissions.includes('team.manage');
  const [filters, setFilters] = useState<TeamFilters>(() => ({ ...noFilters, state: parseStateFilter(searchParams.get('state')) }));
  const archived = filters.state === 'archived';
  const [attempt, setAttempt] = useState(0);
  const [loaded, setLoaded] = useState<{ scope: string; list: TeamList }>();
  const [failed, setFailed] = useState<{ key: string; text: string }>();
  const [adding, setAdding] = useState(false);
  const [filtersOpen, setFiltersOpen] = useState(false);
  const filterCount = filters.roles.length + (filters.state ? 1 : 0);
  const hasFilters = !!filters.search || filterCount > 0;
  const [now] = useState(() => new Date());
  const [today] = useState(() => sofiaDay(new Date()));
  const scope = `${organization.id}|${archived ? 'archived' : 'current'}`;
  const key = `${scope}|${attempt}`;

  useEffect(() => {
    if (!canView) return;
    let active = true;
    loadTeam(organization.id, archived)
      .then((list) => { if (active) setLoaded({ scope, list }); })
      .catch((reason: unknown) => { if (active) setFailed({ key, text: reason instanceof Error ? reason.message : 'Списъкът на екипа не се зареди.' }); });
    return () => { active = false; };
  }, [canView, organization.id, archived, scope, key]);

  // Loaded again after adding someone, the list stays on screen until the new one comes, so the page does not jump.
  const list = loaded?.scope === scope ? loaded.list : undefined;
  const error = failed?.key === key ? failed.text : '';
  const shown = list?.members.filter((member) => matchesFilters(member, filters, now));

  return (
    <div className={styles.page}>
      <header className={styles.header}>
        <div className={styles.heading}>
          <h1>Екип</h1>
          <p className={styles.lead}>Хората в организацията, ролите им и групите, които водят.</p>
        </div>
        {canView && canManage ? <button type="button" className={styles.primary} onClick={() => setAdding(true)}>+ Добави в екипа</button> : null}
      </header>

      {!canView ? <NoTeamAccess /> : (
        <>
          <div className={styles.filterPanel} role="search" aria-label="Търсене в екипа">
            <div className={styles.searchRow}>
            <div className={styles.field}>
              <label htmlFor="team-search">Търси по име</label>
              <input id="team-search" className={styles.input} type="search" autoComplete="off" value={filters.search}
                onChange={(event) => setFilters((current) => ({ ...current, search: event.target.value }))} />
            </div>
            <button type="button" className={`${styles.secondary} ${styles.filterToggle}`} aria-expanded={filtersOpen} aria-controls="team-extra-filters" onClick={() => setFiltersOpen((open) => !open)}>
              Филтри{filterCount ? ` · ${filterCount}` : ''}<Chevron open={filtersOpen} />
            </button>
            </div>
            <div id="team-extra-filters" className={`${styles.extraFilters} ${filtersOpen ? styles.filtersOpen : ''}`}>
            <RolesFilter roles={filters.roles} onChange={(roles) => setFilters((current) => ({ ...current, roles }))} />
            <div className={styles.field}>
              <label htmlFor="team-state">Достъп</label>
              <select id="team-state" className={styles.input} value={filters.state}
                onChange={(event) => setFilters((current) => ({ ...current, state: event.target.value as StateFilter }))}>
                <option value="">Всички (без архива)</option>
                {(Object.keys(stateFilterLabels) as Exclude<StateFilter, ''>[]).map((state) => <option key={state} value={state}>{stateFilterLabels[state]}</option>)}
              </select>
            </div>
            </div>
            {hasFilters ? <div className={styles.filterSummary}>
              <span>{[filters.search ? `Име: ${filters.search}` : '', filters.roles.map(roleLabel).join(', '), filters.state ? stateFilterLabels[filters.state] : ''].filter(Boolean).join(' · ')}</span>
              <button type="button" className={styles.rowAction} onClick={() => setFilters(noFilters)}>Изчисти всички филтри</button>
            </div> : null}
          </div>

          <section aria-labelledby="team-list-title" aria-busy={list === undefined && !error}>
            <h2 id="team-list-title" className="sr-only">{archived ? 'В архива' : 'Хората в екипа'}</h2>
            {/* The count follows the search and the filters, and is read out when it changes. */}
            <p className={list && shown && list.members.length > 0 ? styles.found : 'sr-only'} role="status">
              {list && shown && list.members.length > 0 ? foundText(shown.length) : ''}
            </p>
            {error && list === undefined ? (
              <div className={styles.empty} role="alert">
                <strong>{error}</strong>
                <p><button type="button" className={styles.secondary} onClick={() => setAttempt((value) => value + 1)}>Опитай отново</button></p>
              </div>
            ) : list === undefined || shown === undefined ? (
              <div className={styles.skeletonList}>{[0, 1, 2].map((row) => <span className={styles.skeleton} key={row} />)}</div>
            ) : list.members.length === 0 ? (
              <div className={styles.empty}>
                <strong>{archived ? 'Архивът е празен' : 'В екипа още няма никого'}</strong>
                <p>{archived ? 'Тук ще виждате записите, които архивирате.' : canManage ? 'С „Добави в екипа“ добавяте преподавател, който не се нуждае от достъп, или колега, на когото изпращате покана.' : 'Собственикът и администраторът добавят хората в екипа.'}</p>
              </div>
            ) : shown.length === 0 ? (
              <div className={styles.empty}>
                <strong>Никой не отговаря на търсенето</strong>
                <p><button type="button" className={styles.secondary} onClick={() => setFilters({ ...noFilters, state: archived ? 'archived' : '' })}>Изчисти филтрите</button></p>
              </div>
            ) : (
              <>
                {error ? <p className={styles.error} role="alert">{error}</p> : null}
                <div className={styles.tableWrap}>
                  <table className={styles.table}>
                    <thead><tr><th scope="col">Име</th><th scope="col">Роля</th><th scope="col">Групи</th><th scope="col">Достъп</th></tr></thead>
                    <tbody>{shown.map((member) => <MemberRow key={member.id} member={member} canManage={list.can_manage} now={now} today={today} />)}</tbody>
                  </table>
                </div>
                {archived ? null : (
                  <details className={`${styles.more} ${styles.accessHelp}`}>
                    <summary>Какво означават състоянията на достъп?</summary>
                    <p className={styles.small}><strong>Има достъп</strong> — влиза със свой имейл и парола. <strong>Няма достъп</strong> — присъствията отбелязвате вие вместо
                    преподавателя. <strong>Изпратена покана</strong> — чакаме да я приеме.</p>
                  </details>
                )}
              </>
            )}
          </section>
        </>
      )}

      {adding ? (
        <AddMemberDialog organizationId={organization.id} actorRole={organization.role} onClose={() => setAdding(false)}
          onAdded={() => setAttempt((value) => value + 1)} />
      ) : null}
    </div>
  );
}
