'use client';

import Link from 'next/link';
import { type FormEvent, useState } from 'react';
import { loadStaffGroups, type StaffGroup } from '@/features/enrollment/api';
import { shortDay, sofiaDay } from '@/features/enrollment/presentation';
import { assignGroup, createTeacherProfile, endAssignment, type GroupAssignment, TeamApiError, type TeamMember } from './api';
import { assignmentPhase, assignmentRole, focusLater, periodText } from './presentation';
import styles from './team.module.css';

type Props = {
  organizationId: string;
  member: TeamMember;
  /** Who leads and assists a group: owner, administrator and coordinator (`catalog.manage`, т. 13.1). */
  canAssign: boolean;
  /** A teacher profile for a person: owner and administrator (`team.manage`). */
  canManage: boolean;
  onChanged: () => void;
};

const phaseOrder = { current: 0, coming: 1, ended: 2 } as const;
const capitalized = (text: string) => text.charAt(0).toLocaleUpperCase('bg-BG') + text.slice(1);

function phaseBadge(assignment: GroupAssignment, today: string): { text: string; tone: 'ok' | 'info' | 'muted' } {
  const phase = assignmentPhase(assignment, today);
  if (phase === 'coming') return { text: `Започва на ${shortDay(assignment.starts_on)}`, tone: 'info' };
  if (phase === 'ended') return { text: `Приключило на ${shortDay(assignment.ends_on)}`, tone: 'muted' };
  return { text: 'Текущо', tone: 'ok' };
}

function EndForm({ assignment, today, busy, onEnd, onCancel }: { assignment: GroupAssignment; today: string; busy: boolean; onEnd: (endsOn: string) => void; onCancel: () => void }) {
  const [endsOn, setEndsOn] = useState(() => (assignment.starts_on && assignment.starts_on > today ? assignment.starts_on : today));
  const id = `end-${assignment.group_id}`;
  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (endsOn) onEnd(endsOn);
  }
  return (
    <form className={styles.inline} onSubmit={submit}>
      <div className={styles.field}>
        <label htmlFor={id}>Последен ден</label>
        <input id={id} className={styles.input} type="date" value={endsOn} min={assignment.starts_on ?? undefined} required onChange={(event) => setEndsOn(event.target.value)} aria-describedby={`${id}-hint`} />
      </div>
      <p id={`${id}-hint`} className={styles.small}>След този ден човекът излиза от следващите занимания на групата. Започналите и ръчно сменените занимания не се променят.</p>
      <div className={styles.actions}>
        <button type="submit" className={styles.primary} disabled={busy || !endsOn} aria-busy={busy}>{busy ? 'Приключване…' : 'Приключи назначението'}</button>
        <button type="button" className={styles.secondary} onClick={onCancel}>Отказ</button>
      </div>
    </form>
  );
}

function AddForm({ groups, taken, busy, onAdd, onCancel }: {
  groups: StaffGroup[]; taken: Set<string>; busy: boolean;
  onAdd: (groupId: string, role: 'lead' | 'assistant', startsOn: string, endsOn: string) => void; onCancel: () => void;
}) {
  const [groupId, setGroupId] = useState('');
  const [role, setRole] = useState<'lead' | 'assistant'>('lead');
  const [startsOn, setStartsOn] = useState('');
  const [endsOn, setEndsOn] = useState('');
  const [missing, setMissing] = useState(false);
  const free = groups.filter((group) => !taken.has(group.id));
  const byActivity = new Map<string, StaffGroup[]>();
  for (const group of free) {
    const title = group.activity_title ?? 'Без дейност';
    byActivity.set(title, [...(byActivity.get(title) ?? []), group]);
  }

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!groupId) {
      setMissing(true);
      focusLater('assign-group');
      return;
    }
    onAdd(groupId, role, startsOn, endsOn);
  }

  if (free.length === 0) {
    return (
      <div className={styles.inline}>
        <p>{groups.length === 0 ? 'Организацията още няма групи. Групите се създават в стъпка 7 на всяка дейност.' : 'Човекът вече води всички групи.'}</p>
        <div className={styles.actions}>
          {groups.length === 0 ? <Link className={styles.secondary} href="/dashboard/activities">Към дейностите</Link> : null}
          <button type="button" className={styles.secondary} onClick={onCancel}>Затвори</button>
        </div>
      </div>
    );
  }

  return (
    <form className={styles.inline} onSubmit={submit} noValidate>
      <div className={styles.field}>
        <label htmlFor="assign-group">Група</label>
        <select id="assign-group" className={styles.input} value={groupId} required aria-invalid={missing ? true : undefined} aria-describedby={missing ? 'assign-group-error' : undefined}
          onChange={(event) => { setGroupId(event.target.value); setMissing(false); }}>
          <option value="">Изберете група</option>
          {[...byActivity].map(([title, items]) => (
            <optgroup key={title} label={title}>{items.map((group) => <option key={group.id} value={group.id}>{group.name}</option>)}</optgroup>
          ))}
        </select>
        {missing ? <p id="assign-group-error" className={styles.fieldError}>Изберете група.</p> : null}
      </div>
      <fieldset className={styles.choices}>
        <legend>Роля в групата</legend>
        <label className={styles.choice}><input type="radio" name="assign-role" checked={role === 'lead'} onChange={() => setRole('lead')} /><span className={styles.choiceText}>Водещ<span>Води заниманията</span></span></label>
        <label className={styles.choice}><input type="radio" name="assign-role" checked={role === 'assistant'} onChange={() => setRole('assistant')} /><span className={styles.choiceText}>Помощник<span>Помага на водещия</span></span></label>
      </fieldset>
      <div className={styles.fieldGrid}>
        <div className={styles.field}>
          <label htmlFor="assign-from">От (по избор)</label>
          <input id="assign-from" className={styles.input} type="date" value={startsOn} onChange={(event) => setStartsOn(event.target.value)} />
        </div>
        <div className={styles.field}>
          <label htmlFor="assign-to">До (по избор)</label>
          <input id="assign-to" className={styles.input} type="date" value={endsOn} min={startsOn || undefined} onChange={(event) => setEndsOn(event.target.value)} />
        </div>
      </div>
      <p className={styles.small}>Без дати назначението важи веднага, докато не го приключите.</p>
      <div className={styles.actions}>
        <button type="submit" className={styles.primary} disabled={busy} aria-busy={busy}>{busy ? 'Добавяне…' : 'Добави групата'}</button>
        <button type="button" className={styles.secondary} onClick={onCancel}>Отказ</button>
      </div>
    </form>
  );
}

/**
 * "Групи" in the profile (docs/product/team.md, т. 5, 10): lead or assistant, from a day to a day; works in published
 * groups too. Ending sends the assignment's version from the profile; a stale one gets 409.
 */
export function MemberGroups({ organizationId, member, canAssign, canManage, onChanged }: Props) {
  const [today] = useState(() => sofiaDay(new Date()));
  const [groups, setGroups] = useState<StaffGroup[]>();
  const [adding, setAdding] = useState(false);
  const [ending, setEnding] = useState<string>();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const archived = member.state === 'archived';
  const profileId = member.instructor_profile_id;
  const assignments = [...member.groups].sort((a, b) => phaseOrder[assignmentPhase(a, today)] - phaseOrder[assignmentPhase(b, today)]);
  const taken = new Set(member.groups.filter((item) => assignmentPhase(item, today) !== 'ended').map((item) => item.group_id));
  const message = (reason: unknown, fallback: string) => (reason instanceof Error && reason.message ? reason.message : fallback);

  async function organizationGroups(): Promise<StaffGroup[]> {
    if (groups) return groups;
    const loaded = await loadStaffGroups(organizationId);
    setGroups(loaded);
    return loaded;
  }

  async function openAdd() {
    setError(''); setNotice(''); setEnding(undefined);
    try {
      await organizationGroups();
      setAdding(true);
      focusLater('assign-group');
    } catch (reason) {
      setError(message(reason, 'Групите не се заредиха. Проверете връзката и опитайте отново.'));
    }
  }

  async function add(groupId: string, role: 'lead' | 'assistant', startsOn: string, endsOn: string) {
    if (!profileId) return;
    setBusy(true); setError('');
    try {
      await assignGroup(organizationId, groupId, { instructor_profile_id: profileId, role, starts_on: startsOn || null, ends_on: endsOn || null });
      const group = groups?.find((item) => item.id === groupId);
      const label = group ? [group.activity_title, group.name].filter(Boolean).join(' · ') : 'групата';
      setNotice(`${member.full_name} е ${assignmentRole(role)} в „${label}“.`);
      setAdding(false);
      onChanged();
    } catch (reason) {
      setError(message(reason, 'Назначението не беше добавено.'));
    } finally {
      setBusy(false);
    }
  }

  async function end(assignment: GroupAssignment, endsOn: string) {
    if (!profileId) return;
    setBusy(true); setError('');
    try {
      await endAssignment(organizationId, assignment.group_id, profileId, endsOn, assignment.version);
      setNotice(`Назначението в „${assignment.label}“ приключва на ${shortDay(endsOn)}.`);
      setEnding(undefined);
      onChanged();
    } catch (reason) {
      setError(message(reason, 'Назначението не беше приключено.'));
      // Changed meanwhile: the profile is loaded again, so the next try has the new version.
      if (reason instanceof TeamApiError && reason.status === 409) onChanged();
    } finally {
      setBusy(false);
    }
  }

  async function makeTeacher() {
    setBusy(true); setError('');
    try {
      await createTeacherProfile(organizationId, member.id);
      setNotice(`${member.full_name} вече има профил на преподавател и може да води групи.`);
      onChanged();
    } catch (reason) {
      setError(message(reason, 'Профилът на преподавател не беше създаден.'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className={styles.card} aria-labelledby="groups-title">
      <h2 id="groups-title">Групи</h2>
      <p className={notice ? styles.ok : 'sr-only'} role="status">{notice}</p>
      {error ? <p className={styles.error} role="alert">{error}</p> : null}

      {!profileId ? (
        <>
          <p>Няма профил на преподавател, затова не води групи.</p>
          {canManage && !archived ? (
            <div className={styles.actions}>
              <button type="button" className={styles.secondary} disabled={busy} onClick={makeTeacher}>Създай профил на преподавател</button>
            </div>
          ) : null}
        </>
      ) : (
        <>
          {assignments.length === 0 ? <p className={styles.muted}>Още не води група.</p> : (
            <ul className={styles.rows}>
              {assignments.map((assignment) => {
                const badge = phaseBadge(assignment, today);
                const period = periodText(assignment);
                const open = assignmentPhase(assignment, today) !== 'ended';
                return (
                  <li key={assignment.group_id}>
                    <div className={styles.rowHead}>
                      <span className={styles.rowTitle}>{assignment.label}</span>
                      <span className={`${styles.badge} ${styles[`tone_${badge.tone}`]}`}>{badge.text}</span>
                    </div>
                    <span>{capitalized(assignmentRole(assignment.role))}{period ? ` · ${period}` : ''}</span>
                    {canAssign && !archived && open ? (
                      ending === assignment.group_id
                        ? <EndForm assignment={assignment} today={today} busy={busy} onEnd={(endsOn) => end(assignment, endsOn)}
                          onCancel={() => { setEnding(undefined); focusLater(`end-button-${assignment.group_id}`); }} />
                        : <button id={`end-button-${assignment.group_id}`} type="button" className={styles.linkButton}
                          onClick={() => { setEnding(assignment.group_id); setAdding(false); setNotice(''); setError(''); focusLater(`end-${assignment.group_id}`); }}>
                          Приключи назначението<span className="sr-only"> в „{assignment.label}“</span>
                        </button>
                    ) : null}
                  </li>
                );
              })}
            </ul>
          )}
          {canAssign && !archived ? (
            adding && groups
              ? <AddForm groups={groups} taken={taken} busy={busy} onAdd={add} onCancel={() => { setAdding(false); focusLater('add-group-button'); }} />
              : <button id="add-group-button" type="button" className={styles.linkButton} onClick={openAdd}>+ Добави група</button>
          ) : !canAssign ? <p className={styles.small}>Групите разпределят собственикът, администраторът и координаторът.</p> : null}
        </>
      )}
    </section>
  );
}
