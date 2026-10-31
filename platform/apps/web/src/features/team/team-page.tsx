'use client';

import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { Fragment, useEffect, useState } from 'react';
import { useDashboardContext } from '@/features/dashboard/dashboard-context';
import { sofiaDay } from '@/features/enrollment/presentation';
import { AddMemberDialog } from './add-member-dialog';
import { type GroupAssignment, loadTeam, type TeamList, type TeamMember } from './api';
import {
  accessAction, GROUPS_SHOWN, groupLink, groupsByActivity, groupSummary, groupTags, matchesFilters, memberRole, memberStatus, noGroupsText, parseStateFilter,
  roleLabel, roleLabels, stateFilterLabels, type StateFilter, type TeamFilters, type TeamRole,
} from './presentation';
import styles from './team.module.css';

const noFilters: TeamFilters = { search: '', role: '', state: '' };

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

/** The group's name; a link to its children in "Записвания" for those who see the enrollments. */
export function GroupName({ assignment, linked }: { assignment: GroupAssignment; linked: boolean }) {
  if (!linked) return <>{assignment.group}</>;
  const link = groupLink(assignment);
  return <Link className={styles.groupLink} href={link.href} aria-label={link.label}>{assignment.group}</Link>;
}

/**
 * "Шахмат: Група 1, Група 2 · 3.–4. клас [помощник]" — one line per activity; only "помощник" and a coming start are
 * marked. Each group opens its children in "Записвания" when `linked`.
 */
export function ActivityLines({ groups, today, linked }: { groups: GroupAssignment[]; today: string; linked: boolean }) {
  return (
    <ul className={styles.activityLines}>
      {groupsByActivity(groups).map((item) => (
        <li key={item.activity}>
          <span className={styles.activityName}>{item.activity}:</span>{' '}
          {item.groups.map((group, index) => (
            <Fragment key={group.group_id}>
              {index > 0 ? ', ' : ''}<GroupName assignment={group} linked={linked} />
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
function GroupsCell({ member, today, linked }: { member: TeamMember; today: string; linked: boolean }) {
  const [open, setOpen] = useState(false);
  if (member.groups.length === 0) return <span className={styles.none}>{noGroupsText(member)}</span>;
  if (member.groups.length <= GROUPS_SHOWN) return <ActivityLines groups={member.groups} today={today} linked={linked} />;
  const summary = groupSummary(member.groups);
  const id = `groups-${member.id}`;
  return (
    <>
      <button type="button" className={styles.groupsButton} aria-expanded={open} aria-controls={id} onClick={() => setOpen((value) => !value)}>
        <strong>{summary.count}</strong>
        {summary.chips.map((chip) => <span key={chip} className={styles.chip}>{chip}</span>)}
        <Chevron open={open} />
      </button>
      {open ? <div id={id} className={styles.groupsOpen}><ActivityLines groups={member.groups} today={today} linked={linked} /></div> : null}
    </>
  );
}

function MemberRow({ member, canManage, linked, now, today }: { member: TeamMember; canManage: boolean; linked: boolean; now: Date; today: string }) {
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
      <td><GroupsCell member={member} today={today} linked={linked} /></td>
      <td className={styles.cellAccess}>
        <div className={styles.accessCell}>
          <span className={styles.stack}>
            <span className={`${styles.badge} ${styles[`tone_${status.tone}`]}`}>{status.label}</span>
            {status.detail ? <span className={styles.small}>{status.detail}</span> : null}
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
  // The groups open their children in "Записвания" for those who see the enrollments.
  const canOpenGroups = organization.permissions.includes('enrollment.view');
  const [filters, setFilters] = useState<TeamFilters>(() => ({ ...noFilters, state: parseStateFilter(searchParams.get('state')) }));
  const archived = filters.state === 'archived';
  const [attempt, setAttempt] = useState(0);
  const [loaded, setLoaded] = useState<{ scope: string; list: TeamList }>();
  const [failed, setFailed] = useState<{ key: string; text: string }>();
  const [adding, setAdding] = useState(false);
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
  const filtering = filters.search.trim() !== '' || filters.role !== '' || (filters.state !== '' && !archived);

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
          <div className={styles.filters} role="search" aria-label="Търсене в екипа">
            <div className={styles.field}>
              <label htmlFor="team-search">Търси по име</label>
              <input id="team-search" className={styles.input} type="search" autoComplete="off" value={filters.search}
                onChange={(event) => setFilters((current) => ({ ...current, search: event.target.value }))} />
            </div>
            <div className={styles.field}>
              <label htmlFor="team-role">Роля</label>
              <select id="team-role" className={styles.input} value={filters.role}
                onChange={(event) => setFilters((current) => ({ ...current, role: event.target.value as '' | TeamRole }))}>
                <option value="">Всички роли</option>
                {(Object.keys(roleLabels) as TeamRole[]).map((role) => <option key={role} value={role}>{roleLabels[role]}</option>)}
              </select>
            </div>
            <div className={styles.field}>
              <label htmlFor="team-state">Достъп</label>
              <select id="team-state" className={styles.input} value={filters.state}
                onChange={(event) => setFilters((current) => ({ ...current, state: event.target.value as StateFilter }))}>
                <option value="">Всички (без архива)</option>
                {(Object.keys(stateFilterLabels) as Exclude<StateFilter, ''>[]).map((state) => <option key={state} value={state}>{stateFilterLabels[state]}</option>)}
              </select>
            </div>
          </div>

          <section aria-labelledby="team-list-title" aria-busy={list === undefined && !error}>
            <h2 id="team-list-title" className="sr-only">{archived ? 'В архива' : 'Хората в екипа'}</h2>
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
                {filtering ? <p className={styles.small} role="status">Показани са {shown.length} от {list.members.length}</p> : null}
                <div className={styles.tableWrap}>
                  <table className={styles.table}>
                    <thead><tr><th scope="col">Име</th><th scope="col">Роля</th><th scope="col">Групи</th><th scope="col">Достъп</th></tr></thead>
                    <tbody>{shown.map((member) => <MemberRow key={member.id} member={member} canManage={list.can_manage} linked={canOpenGroups} now={now} today={today} />)}</tbody>
                  </table>
                </div>
                {archived ? null : (
                  <p className={styles.small}>
                    <strong>Има достъп</strong> — влиза със свой имейл и парола. <strong>Няма достъп</strong> — присъствията отбелязвате вие вместо
                    преподавателя. <strong>Изпратена покана</strong> — чакаме да я приеме.
                  </p>
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
