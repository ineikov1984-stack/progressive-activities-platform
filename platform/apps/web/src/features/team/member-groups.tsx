'use client';

import Link from 'next/link';
import { type FormEvent, useEffect, useState } from 'react';
import { loadStaffGroups, type StaffGroup } from '@/features/enrollment/api';
import { shortDay, sofiaDay } from '@/features/enrollment/presentation';
import { assignGroup, createTeacherProfile, endAssignment, type GroupAssignment, TeamApiError, type TeamMember } from './api';
import { assignmentPhase, assignmentRole, focusLater, focusVisible, groupsByActivity, groupsMeta } from './presentation';
import styles from './team.module.css';

type Props = {
  organizationId: string;
  member: TeamMember;
  /** Who leads and assists a group: owner, administrator and coordinator (`catalog.manage`, т. 13.1). */
  canAssign: boolean;
  /** Letting a person lead groups: owner and administrator (`team.manage`). */
  canManage: boolean;
  onChanged: () => void;
  /** Each new number opens the form for a group: "+ Добави група" in the header, or right after adding the person. */
  addRequest: number;
  /** The header holds "+ Добави група" on a wide screen; the section's own button is then for a phone only. */
  addInHeader: boolean;
};

const capitalized = (text: string) => text.charAt(0).toLocaleUpperCase('bg-BG') + text.slice(1);

function EndForm({ assignment, name, today, busy, onEnd, onCancel }: {
  assignment: GroupAssignment; name: string; today: string; busy: boolean; onEnd: (endsOn: string) => void; onCancel: () => void;
}) {
  const [endsOn, setEndsOn] = useState(() => (assignment.starts_on && assignment.starts_on > today ? assignment.starts_on : today));
  const id = `end-${assignment.group_id}`;
  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (endsOn) onEnd(endsOn);
  }
  return (
    <form className={styles.inline} onSubmit={submit}>
      <div className={styles.field}>
        <label htmlFor={id}>Последен ден в групата</label>
        <input id={id} className={styles.input} type="date" value={endsOn} min={assignment.starts_on ?? undefined} required onChange={(event) => setEndsOn(event.target.value)} aria-describedby={`${id}-hint`} />
      </div>
      <p id={`${id}-hint`} className={styles.small}>След този ден следващите занимания на групата са без {name}. Вече започналите и ръчно сменените занимания не се променят.</p>
      <div className={styles.actions}>
        <button type="submit" className={styles.primary} disabled={busy || !endsOn} aria-busy={busy}>{busy ? 'Приключване…' : 'Да, приключи'}</button>
        <button type="button" className={styles.secondary} onClick={onCancel}>Отказ</button>
      </div>
    </form>
  );
}

function AddForm({ groups, taken, name, busy, onAdd, onCancel }: {
  groups: StaffGroup[]; taken: Set<string>; name: string; busy: boolean;
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
        <p>{groups.length === 0 ? 'Още нямате групи. Създавате ги в стъпка 7 на всяка дейност.' : `${name} вече е във всички групи.`}</p>
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
      <p className={styles.small}>Ако не изберете дати, важи от днес, докато не приключите работата в групата.</p>
      <div className={styles.actions}>
        <button type="submit" className={styles.primary} disabled={busy} aria-busy={busy}>{busy ? 'Добавяне…' : 'Добави групата'}</button>
        <button type="button" className={styles.secondary} onClick={onCancel}>Отказ</button>
      </div>
    </form>
  );
}

/**
 * "Групи" in the profile (docs/product/team.md, т. 5, 10): by activity, lead or assistant, from a day to a day; the
 * ended ones folded. Works in published groups too; ending sends the assignment's version, a stale one gets 409.
 */
export function MemberGroups({ organizationId, member, canAssign, canManage, onChanged, addRequest, addInHeader }: Props) {
  const [today] = useState(() => sofiaDay(new Date()));
  const [groups, setGroups] = useState<StaffGroup[]>();
  const [adding, setAdding] = useState(false);
  const [ending, setEnding] = useState<string>();
  const [endedOpen, setEndedOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const archived = member.state === 'archived';
  const profileId = member.instructor_profile_id;
  const name = member.full_name;
  const open = member.groups.filter((item) => assignmentPhase(item, today) !== 'ended');
  const ended = member.groups.filter((item) => assignmentPhase(item, today) === 'ended');
  const taken = new Set(open.map((item) => item.group_id));
  const canAdd = canAssign && !archived && profileId !== null;
  const message = (reason: unknown, fallback: string) => (reason instanceof Error && reason.message ? reason.message : fallback);

  // "+ Добави група" in the header, or right after adding the person: the groups load, then the form opens.
  useEffect(() => {
    if (addRequest === 0 || !canAdd) return;
    let active = true;
    loadStaffGroups(organizationId)
      .then((loaded) => {
        if (!active) return;
        setGroups(loaded);
        setEnding(undefined);
        setAdding(true);
        focusLater('assign-group');
      })
      .catch(() => { if (active) setError('Групите не се заредиха. Проверете интернет връзката и опитайте отново.'); });
    return () => { active = false; };
  }, [addRequest, canAdd, organizationId]);

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
      setError(message(reason, 'Групите не се заредиха. Проверете интернет връзката и опитайте отново.'));
    }
  }

  async function add(groupId: string, role: 'lead' | 'assistant', startsOn: string, endsOn: string) {
    if (!profileId) return;
    setBusy(true); setError('');
    try {
      await assignGroup(organizationId, groupId, { instructor_profile_id: profileId, role, starts_on: startsOn || null, ends_on: endsOn || null });
      const group = groups?.find((item) => item.id === groupId);
      const label = group ? [group.activity_title, group.name].filter(Boolean).join(' · ') : 'групата';
      setNotice(`${name} вече е ${assignmentRole(role)} в „${label}“.`);
      setAdding(false);
      onChanged();
    } catch (reason) {
      setError(message(reason, 'Групата не беше добавена.'));
    } finally {
      setBusy(false);
    }
  }

  async function end(assignment: GroupAssignment, endsOn: string) {
    if (!profileId) return;
    setBusy(true); setError('');
    try {
      await endAssignment(organizationId, assignment.group_id, profileId, endsOn, assignment.version);
      setNotice(`Последният ден в „${assignment.label}“ е ${shortDay(endsOn)}.`);
      setEnding(undefined);
      onChanged();
    } catch (reason) {
      setError(message(reason, 'Промяната не беше запазена.'));
      // Changed meanwhile: the profile is loaded again, so the next try has the new version.
      if (reason instanceof TeamApiError && reason.status === 409) onChanged();
    } finally {
      setBusy(false);
    }
  }

  async function letLead() {
    setBusy(true); setError('');
    try {
      await createTeacherProfile(organizationId, member.id);
      setNotice(`${name} вече може да води групи.`);
      onChanged();
    } catch (reason) {
      setError(message(reason, 'Промяната не беше запазена.'));
    } finally {
      setBusy(false);
    }
  }

  const row = (assignment: GroupAssignment, closed: boolean) => (
    <li key={assignment.group_id} className={styles.groupItem}>
      <div className={styles.groupRow}>
        <span className={styles.groupName}>{assignment.group}</span>
        <span className={assignment.role === 'assistant' ? styles.groupRoleStrong : styles.groupRole}>{capitalized(assignmentRole(assignment.role))}</span>
        {closed && assignment.ends_on ? <span className={styles.tag}>до {shortDay(assignment.ends_on)}</span> : null}
        {!closed && assignment.starts_on && assignment.starts_on > today ? <span className={styles.tag}>от {shortDay(assignment.starts_on)}</span> : null}
        {canAssign && !archived && !closed && ending !== assignment.group_id ? (
          <button id={`end-button-${assignment.group_id}`} type="button" className={styles.rowAction}
            onClick={() => { setEnding(assignment.group_id); setAdding(false); setNotice(''); setError(''); focusLater(`end-${assignment.group_id}`); }}>
            Приключи<span className="sr-only"> работата в „{assignment.label}“</span>
          </button>
        ) : null}
      </div>
      {ending === assignment.group_id ? (
        <EndForm assignment={assignment} name={name} today={today} busy={busy} onEnd={(endsOn) => end(assignment, endsOn)}
          onCancel={() => { setEnding(undefined); focusLater(`end-button-${assignment.group_id}`); }} />
      ) : null}
    </li>
  );

  return (
    <section className={styles.card} aria-labelledby="groups-title">
      <div className={styles.cardHead}>
        <h2 id="groups-title">Групи</h2>
        {open.length > 0 ? <span className={styles.muted}>{groupsMeta(open)}</span> : null}
      </div>
      <p className={notice ? styles.ok : 'sr-only'} role="status">{notice}</p>
      {error ? <p className={styles.error} role="alert">{error}</p> : null}

      {!profileId ? (
        <>
          <p>Още не може да води групи.</p>
          {canManage && !archived ? (
            <div className={styles.actions}>
              <button type="button" className={styles.secondary} disabled={busy} onClick={letLead}>Разреши да води групи</button>
            </div>
          ) : null}
        </>
      ) : (
        <>
          {open.length === 0 ? <p className={styles.muted}>Още не води група.</p> : groupsByActivity(open).map((item) => (
            <div key={item.activity} className={styles.activityBlock}>
              <h3 className={styles.activityTitle}>{item.activity}</h3>
              <ul className={styles.groupList}>{item.groups.map((assignment) => row(assignment, false))}</ul>
            </div>
          ))}
          {ended.length > 0 ? (
            <div>
              <button type="button" className={styles.rowAction} aria-expanded={endedOpen} aria-controls="ended-groups" onClick={() => setEndedOpen((value) => !value)}>
                Приключили групи ({ended.length})<span aria-hidden="true">{endedOpen ? ' ▴' : ' ▾'}</span>
              </button>
              {endedOpen ? (
                <div id="ended-groups">
                  {groupsByActivity(ended).map((item) => (
                    <div key={item.activity} className={styles.activityBlock}>
                      <h3 className={styles.activityTitle}>{item.activity}</h3>
                      <ul className={styles.groupList}>{item.groups.map((assignment) => row(assignment, true))}</ul>
                    </div>
                  ))}
                </div>
              ) : null}
            </div>
          ) : null}
          {canAdd ? (
            adding && groups
              ? <AddForm groups={groups} taken={taken} name={name} busy={busy} onAdd={add} onCancel={() => { setAdding(false); focusVisible(['add-group-button', 'add-group-header']); }} />
              : (
                <div className={`${styles.actions} ${addInHeader ? styles.phoneOnly : ''}`}>
                  <button id="add-group-button" type="button" className={styles.secondary} onClick={openAdd}>+ Добави група</button>
                </div>
              )
          ) : !canAssign ? <p className={styles.small}>Групите разпределят собственикът, администраторът и координаторът.</p> : null}
        </>
      )}
    </section>
  );
}
