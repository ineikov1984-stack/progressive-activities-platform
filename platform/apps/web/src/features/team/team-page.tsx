'use client';

import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { useEffect, useState } from 'react';
import { useDashboardContext } from '@/features/dashboard/dashboard-context';
import { AddMemberDialog } from './add-member-dialog';
import { loadTeam, type TeamList, type TeamMember } from './api';
import {
  assignmentText, matchesFilters, memberRole, memberStatus, parseStateFilter, roleLabel, roleLabels, stateFilterLabels, waitingAction,
  type StateFilter, type TeamFilters, type TeamRole,
} from './presentation';
import styles from './team.module.css';

const noFilters: TeamFilters = { search: '', role: '', state: '' };

/** The page "Екип" is for every role that sees the team (`team.view`); a teacher gets the "no access" state. */
export function NoTeamAccess() {
  return (
    <div className={styles.empty} role="note">
      <strong>Нямате достъп до „Екип“</strong>
      <p>Вашата роля не вижда екипа на организацията. Обърнете се към собственика на организацията.</p>
      <p><Link href="/dashboard">Към началото</Link></p>
    </div>
  );
}

function MemberRow({ member, canManage, now }: { member: TeamMember; canManage: boolean; now: Date }) {
  const status = memberStatus(member, now);
  const role = memberRole(member);
  const waiting = waitingAction(member, canManage);
  const contacts = [member.phone, member.email].filter(Boolean).join(' · ');
  return (
    <tr>
      <td>
        <div className={styles.person}>
          <Link className={styles.name} href={`/dashboard/team/${encodeURIComponent(member.id)}`}>{member.full_name}</Link>
          <span className={`${styles.badge} ${styles[`tone_${status.tone}`]}`}>{status.label}</span>
          {contacts ? <span className={styles.contacts}>{contacts}</span> : null}
        </div>
      </td>
      <td data-label="Роля">{role ? roleLabel(role) : <span className={styles.none}>Без роля</span>}</td>
      <td data-label="Групи">
        {member.groups.length > 0 ? (
          <ul className={styles.plainList}>{member.groups.map((group) => <li key={group.group_id}>{assignmentText(group)}</li>)}</ul>
        ) : <span className={styles.none}>Няма групи</span>}
      </td>
      <td data-label="Чака">
        {waiting ? <Link className={styles.waiting} href={waiting.href}>{waiting.label}</Link> : <span className={styles.none}>Нищо</span>}
      </td>
    </tr>
  );
}

/**
 * "Екип" (docs/product/team.md, т. 10): who is in the organization, what they lead and what waits for each of them.
 * Search and filters work on the loaded list; "Архивирани" loads the archived people.
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
  const [now] = useState(() => new Date());
  const scope = `${organization.id}|${archived ? 'archived' : 'current'}`;
  const key = `${scope}|${attempt}`;

  useEffect(() => {
    if (!canView) return;
    let active = true;
    loadTeam(organization.id, archived)
      .then((list) => { if (active) setLoaded({ scope, list }); })
      .catch((reason: unknown) => { if (active) setFailed({ key, text: reason instanceof Error ? reason.message : 'Екипът не се зареди.' }); });
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
          <p className={styles.eyebrow}>Организация</p>
          <h1>Екип</h1>
          <p className={styles.lead}>Хората в организацията, групите им и какво чака всеки.</p>
        </div>
        {canView && canManage ? <button type="button" className={styles.primary} onClick={() => setAdding(true)}>+ Добави човек</button> : null}
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
              <label htmlFor="team-state">Състояние</label>
              <select id="team-state" className={styles.input} value={filters.state}
                onChange={(event) => setFilters((current) => ({ ...current, state: event.target.value as StateFilter }))}>
                <option value="">Всички без архивираните</option>
                {(Object.keys(stateFilterLabels) as Exclude<StateFilter, ''>[]).map((state) => <option key={state} value={state}>{stateFilterLabels[state]}</option>)}
              </select>
            </div>
          </div>

          <section aria-labelledby="team-list-title" aria-busy={list === undefined && !error}>
            <h2 id="team-list-title" className="sr-only">{archived ? 'Архивирани хора' : 'Хората в екипа'}</h2>
            {error && list === undefined ? (
              <div className={styles.empty} role="alert">
                <strong>{error}</strong>
                <p><button type="button" className={styles.secondary} onClick={() => setAttempt((value) => value + 1)}>Опитай отново</button></p>
              </div>
            ) : list === undefined || shown === undefined ? (
              <div className={styles.skeletonList}>{[0, 1, 2].map((row) => <span className={styles.skeleton} key={row} />)}</div>
            ) : list.members.length === 0 ? (
              <div className={styles.empty}>
                <strong>{archived ? 'Няма архивирани хора' : 'В екипа още няма хора'}</strong>
                <p>{archived ? 'Тук ще са хората, които сте архивирали.' : canManage ? 'С „Добави човек“ добавяте преподавател без вход или колега, когото каните да влиза в системата.' : 'Собственикът и администраторът добавят хората в екипа.'}</p>
              </div>
            ) : shown.length === 0 ? (
              <div className={styles.empty}>
                <strong>Никой не отговаря на търсенето</strong>
                <p><button type="button" className={styles.secondary} onClick={() => setFilters({ ...noFilters, state: archived ? 'archived' : '' })}>Изчисти филтрите</button></p>
              </div>
            ) : (
              <>
                {error ? <p className={styles.error} role="alert">{error}</p> : null}
                {filtering ? <p className={styles.small} role="status">Показани {shown.length} от {list.members.length}</p> : null}
                <div className={styles.tableWrap}>
                  <table className={styles.table}>
                    <thead><tr><th scope="col">Човек</th><th scope="col">Роля</th><th scope="col">Групи</th><th scope="col">Чака</th></tr></thead>
                    <tbody>{shown.map((member) => <MemberRow key={member.id} member={member} canManage={list.can_manage} now={now} />)}</tbody>
                  </table>
                </div>
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
