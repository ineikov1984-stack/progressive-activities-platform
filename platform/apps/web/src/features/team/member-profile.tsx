'use client';

import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { type FormEvent, type RefObject, useCallback, useEffect, useRef, useState } from 'react';
import { useDashboardContext } from '@/features/dashboard/dashboard-context';
import { shortDay, sofiaDay } from '@/features/enrollment/presentation';
import {
  archiveMember, changeRole, inviteMember, loadMember, type HistoryEntry, type MemberChanges, type MemberDetails, type SentInvitation, TeamApiError,
  type TeamMember, updateMember,
} from './api';
import { MemberGroups } from './member-groups';
import {
  changeableRoles, expiresText, focusLater, historySentence, initials, invitableRoles, memberRole, memberStatus, roleAccess, roleLabel, roleLabels,
  type TeamRole,
} from './presentation';
import { SentInvitationNotice } from './sent-invitation';
import { NoTeamAccess } from './team-page';
import styles from './team.module.css';

type Tab = 'details' | 'groups' | 'rights' | 'history';
type Notify = (text: string) => void;

function initialTab(value: string | null): Tab {
  return value === 'groups' || value === 'rights' || value === 'history' ? value : 'details';
}

const historyTime = new Intl.DateTimeFormat('bg-BG', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Sofia' });
const failure = (reason: unknown, fallback: string) => (reason instanceof Error && reason.message ? reason.message : fallback);

function Access({ role }: { role: string }) {
  const access = roleAccess(role);
  return (
    <div className={styles.access}>
      <p><strong>Ще вижда:</strong> {access.sees}</p>
      <p><strong>Няма да вижда:</strong> {access.hidden}</p>
    </div>
  );
}

/** "Покана за вход": for a person without a login; sending again gives new links and the old ones stop working. */
function InviteCard({ organizationId, actorRole, member, now, headingRef, onChanged }: {
  organizationId: string; actorRole: string; member: TeamMember; now: Date; headingRef: RefObject<HTMLHeadingElement | null>; onChanged: () => void;
}) {
  const roles = invitableRoles(actorRole);
  const [role, setRole] = useState<TeamRole>(member.instructor_profile_id ? 'instructor' : 'coordinator');
  const [email, setEmail] = useState(member.email ?? '');
  const [sent, setSent] = useState<SentInvitation>();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [emailError, setEmailError] = useState('');
  const invited = memberStatus(member, now).key === 'invited';
  const status = member.invitation
    ? invited ? `Поканен — поканата ${expiresText(member.invitation.expires_at, now)}.` : 'Поканата изтече. Изпратете я пак.'
    : 'Още не е поканен. Без вход профилът също работи — тогава екипът отбелязва вместо него.';

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (email.trim() === '') {
      setEmailError('Въведете имейл, на който да изпратим поканата.');
      focusLater('invite-email');
      return;
    }
    setBusy(true); setError(''); setEmailError('');
    try {
      setSent(await inviteMember(organizationId, member.id, role, email.trim()));
      onChanged();
    } catch (reason) {
      const field = reason instanceof TeamApiError ? reason.fieldErrors.email?.[0] : undefined;
      if (field) {
        setEmailError(field);
        focusLater('invite-email');
      } else setError(failure(reason, 'Поканата не беше изпратена.'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <section id="invite" className={`${styles.card} ${styles.cardWarm}`} aria-labelledby="invite-title">
      <h2 id="invite-title" ref={headingRef} tabIndex={-1}>Покана за вход</h2>
      <p>{status}</p>
      {sent ? <SentInvitationNotice sent={sent} /> : null}
      {error ? <p className={styles.error} role="alert">{error}</p> : null}
      <form className={styles.form} onSubmit={submit} noValidate>
        <div className={styles.fieldGrid}>
          <div className={styles.field}>
            <label htmlFor="invite-role">Роля</label>
            <select id="invite-role" className={styles.input} value={role} onChange={(event) => setRole(event.target.value as TeamRole)}>
              {roles.map((option) => <option key={option} value={option}>{roleLabels[option]}</option>)}
            </select>
          </div>
          <div className={styles.field}>
            <label htmlFor="invite-email">Имейл</label>
            <input id="invite-email" className={styles.input} type="email" inputMode="email" autoComplete="off" value={email} required
              aria-invalid={emailError ? true : undefined} aria-describedby={emailError ? 'invite-email-error' : undefined}
              onChange={(event) => { setEmail(event.target.value); setEmailError(''); }} />
            {emailError ? <p id="invite-email-error" className={styles.fieldError}>{emailError}</p> : null}
          </div>
        </div>
        <Access role={role} />
        <div className={styles.actions}>
          <button type="submit" className={styles.primary} disabled={busy} aria-busy={busy}>
            {busy ? 'Изпращане…' : member.invitation || sent ? 'Изпрати поканата пак' : 'Изпрати поканата'}
          </button>
        </div>
      </form>
    </section>
  );
}

/** "Данни": name, phone and e-mail with the version; a stale version says so and offers to load the new data. */
function DetailsForm({ organizationId, member, onChanged, onNotice }: { organizationId: string; member: TeamMember; onChanged: () => void; onNotice: Notify }) {
  const [values, setValues] = useState({ full_name: member.full_name, phone: member.phone ?? '', email: member.email ?? '' });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState('');
  const [stale, setStale] = useState(false);
  const [busy, setBusy] = useState(false);
  type Field = keyof typeof values;

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const changes: MemberChanges = { version: member.version };
    if (values.full_name.trim() !== member.full_name) changes.full_name = values.full_name.trim();
    if (values.phone.trim() !== (member.phone ?? '')) changes.phone = values.phone.trim();
    if (values.email.trim() !== (member.email ?? '')) changes.email = values.email.trim() === '' ? null : values.email.trim();
    if (Object.keys(changes).length === 1) {
      onNotice('Няма промени за запазване.');
      return;
    }
    setBusy(true); setError(''); setErrors({}); setStale(false);
    try {
      await updateMember(organizationId, member.id, changes);
      onNotice('Данните са запазени.');
      onChanged();
    } catch (reason) {
      if (reason instanceof TeamApiError && reason.status === 409) {
        setStale(true);
        setError('Някой току-що промени данните на този човек. Заредете новите данни — вашите промени не са запазени.');
      } else {
        const fields = reason instanceof TeamApiError ? reason.fieldErrors : {};
        setErrors(Object.fromEntries(Object.entries(fields).map(([field, messages]) => [field, messages[0] ?? ''])));
        setError(failure(reason, 'Данните не бяха запазени.'));
      }
    } finally {
      setBusy(false);
    }
  }

  const input = (field: Field, label: string, type: 'text' | 'tel' | 'email') => (
    <div className={`${styles.field} ${field === 'full_name' ? styles.wide : ''}`}>
      <label htmlFor={`details-${field}`}>{label}</label>
      <input id={`details-${field}`} className={styles.input} type={type} autoComplete="off" value={values[field]}
        aria-invalid={errors[field] ? true : undefined} aria-describedby={errors[field] ? `details-${field}-error` : undefined}
        onChange={(event) => { const value = event.target.value; setValues((current) => ({ ...current, [field]: value })); }} />
      {errors[field] ? <p id={`details-${field}-error`} className={styles.fieldError}>{errors[field]}</p> : null}
    </div>
  );

  return (
    <form className={styles.form} onSubmit={submit} noValidate>
      <div className={styles.fieldGrid}>
        {input('full_name', 'Име и фамилия', 'text')}
        {input('phone', 'Телефон', 'tel')}
        {input('email', 'Имейл (по избор)', 'email')}
      </div>
      {error ? <p className={styles.error} role="alert">{error}</p> : null}
      <div className={styles.actions}>
        {stale
          ? <button type="button" className={styles.primary} onClick={onChanged}>Зареди новите данни</button>
          : <button type="submit" className={styles.primary} disabled={busy} aria-busy={busy}>{busy ? 'Запазване…' : 'Запази промените'}</button>}
      </div>
    </form>
  );
}

function ArchiveCard({ organizationId, member, onChanged, onNotice }: { organizationId: string; member: TeamMember; onChanged: () => void; onNotice: Notify }) {
  const [confirming, setConfirming] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  async function archive() {
    setBusy(true); setError('');
    try {
      await archiveMember(organizationId, member.id, member.version);
      onNotice(`Записът на ${member.full_name} е архивиран.`);
      onChanged();
    } catch (reason) {
      // The server says what stops it ("Първо приключете назначението в …"), as it is.
      setError(failure(reason, 'Записът не беше архивиран.'));
      setConfirming(false);
      focusLater('archive-button');
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className={styles.card} aria-labelledby="archive-title">
      <h2 id="archive-title">Архивиране</h2>
      <p className={styles.muted}>Архивираният човек изчезва от списъците и изборите. Ако влиза в системата, входът му в организацията спира. Нищо не се изтрива.</p>
      {error ? <p className={styles.error} role="alert">{error}</p> : null}
      {confirming ? (
        <div className={styles.confirm}>
          <p>Да архивираме ли {member.full_name}?</p>
          <div className={styles.actions}>
            <button id="archive-yes" type="button" className={styles.danger} disabled={busy} aria-busy={busy} onClick={archive}>{busy ? 'Архивиране…' : 'Да, архивирай'}</button>
            <button type="button" className={styles.secondary} onClick={() => { setConfirming(false); focusLater('archive-button'); }}>Отказ</button>
          </div>
        </div>
      ) : (
        <div className={styles.actions}>
          <button id="archive-button" type="button" className={styles.secondary} onClick={() => { setConfirming(true); setError(''); focusLater('archive-yes'); }}>Архивирай</button>
        </div>
      )}
    </section>
  );
}

/** "Права": the role and what it means; a change is confirmed first and sent with the version. */
function RightsCard({ organizationId, actorRole, member, canManage, onChanged, onNotice }: {
  organizationId: string; actorRole: string; member: TeamMember; canManage: boolean; onChanged: () => void; onNotice: Notify;
}) {
  const options = member.state === 'login' && canManage ? changeableRoles(actorRole, member.role) : null;
  const [next, setNext] = useState(member.role ?? '');
  const [confirming, setConfirming] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [stale, setStale] = useState(false);
  const role = memberRole(member);

  function ask(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (next && next !== member.role) { setConfirming(true); setError(''); focusLater('rights-yes'); }
  }

  async function confirm() {
    setBusy(true); setError('');
    try {
      await changeRole(organizationId, member.id, next, member.version);
      onNotice(`Ролята на ${member.full_name} вече е „${roleLabel(next)}“.`);
      onChanged();
    } catch (reason) {
      setStale(reason instanceof TeamApiError && reason.status === 409);
      setError(failure(reason, 'Ролята не беше сменена.'));
      setConfirming(false);
      focusLater('rights-role');
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className={styles.card} aria-labelledby="rights-title">
      <h2 id="rights-title">Права</h2>
      {member.state === 'login' ? (
        <>
          <p>Роля: <strong>{roleLabel(member.role)}</strong></p>
          <Access role={member.role ?? ''} />
        </>
      ) : member.state === 'archived' ? (
        <p>Записът е архивиран — няма вход и права в организацията.</p>
      ) : (
        <p>Не влиза в системата, затова няма роля и права{role === 'instructor' ? ' — екипът отбелязва вместо него' : ''}. Ролята се избира в поканата за вход.</p>
      )}
      {error ? <p className={styles.error} role="alert">{error}</p> : null}
      {stale ? <div className={styles.actions}><button type="button" className={styles.secondary} onClick={onChanged}>Зареди новите данни</button></div> : null}
      {member.state === 'login' && canManage ? (
        options ? (
          confirming ? (
            <div className={styles.confirm} role="group" aria-labelledby="rights-confirm">
              <p id="rights-confirm">Да сменим ли ролята на {member.full_name} от „{roleLabel(member.role)}“ на „{roleLabel(next)}“?</p>
              <Access role={next} />
              <div className={styles.actions}>
                <button id="rights-yes" type="button" className={styles.primary} disabled={busy} aria-busy={busy} onClick={confirm}>{busy ? 'Смяна…' : 'Да, смени ролята'}</button>
                <button type="button" className={styles.secondary} onClick={() => { setConfirming(false); focusLater('rights-role'); }}>Отказ</button>
              </div>
            </div>
          ) : (
            <form className={styles.form} onSubmit={ask}>
              <div className={styles.field}>
                <label htmlFor="rights-role">Нова роля</label>
                <select id="rights-role" className={styles.input} value={next} onChange={(event) => setNext(event.target.value)}>
                  {options.map((option) => <option key={option} value={option}>{roleLabels[option]}</option>)}
                </select>
              </div>
              <div className={styles.actions}><button type="submit" className={styles.secondary} disabled={!next || next === member.role}>Смени ролята</button></div>
            </form>
          )
        ) : <p className={styles.muted}>Тази роля сменя само собственикът.</p>
      ) : null}
    </section>
  );
}

function HistoryCard({ history }: { history: HistoryEntry[] }) {
  return (
    <section className={styles.card} aria-labelledby="history-title">
      <h2 id="history-title">История</h2>
      {history.length === 0 ? <p className={styles.muted}>Още няма записани промени.</p> : (
        <ul className={styles.rows}>
          {history.map((entry, index) => (
            <li key={`${entry.at}-${index}`}>
              <span>{historySentence(entry)}</span>
              {entry.at ? <time className={styles.small} dateTime={entry.at}>{historyTime.format(new Date(entry.at))}</time> : null}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

/**
 * The profile of a person of the team (docs/product/team.md, т. 10): "Данни", "Групи", "Права", "История", the
 * invitation to log in and archiving. Phones, e-mails and the history only for those who manage the team.
 */
export function MemberProfile({ memberId }: { memberId: string }) {
  const { organization } = useDashboardContext();
  const searchParams = useSearchParams();
  const canView = organization.permissions.includes('team.view');
  const canManage = organization.permissions.includes('team.manage');
  const canAssign = organization.permissions.includes('catalog.manage');
  const [tab, setTab] = useState<Tab>(() => initialTab(searchParams.get('tab')));
  const [attempt, setAttempt] = useState(0);
  const [loaded, setLoaded] = useState<{ memberId: string; details: MemberDetails }>();
  const [failed, setFailed] = useState<{ key: string; text: string; status: number }>();
  const [notice, setNotice] = useState<{ text: string } | undefined>(() => (searchParams.get('added') === '1' ? { text: 'Човекът е добавен в екипа. Добавете групите, които води.' } : undefined));
  const [now] = useState(() => new Date());
  const noticeRef = useRef<HTMLParagraphElement>(null);
  const inviteHeading = useRef<HTMLHeadingElement>(null);
  const wantsInvite = useRef(searchParams.get('invite') === '1');
  const key = `${organization.id}|${memberId}|${attempt}`;

  useEffect(() => {
    if (!canView) return;
    let active = true;
    loadMember(organization.id, memberId)
      .then((details) => { if (active) setLoaded({ memberId, details }); })
      .catch((reason: unknown) => {
        if (active) setFailed({ key, text: failure(reason, 'Профилът не се зареди.'), status: reason instanceof TeamApiError ? reason.status : 0 });
      });
    return () => { active = false; };
  }, [canView, organization.id, memberId, key]);

  // After a change the profile is loaded again; the old one stays on screen meanwhile.
  const details = loaded?.memberId === memberId ? loaded.details : undefined;
  const error = failed?.key === key ? failed : undefined;
  const reload = useCallback(() => setAttempt((value) => value + 1), []);
  const notify = useCallback((text: string) => setNotice({ text }), []);

  // A message takes the focus, so it is seen and read; the one after adding waits until the profile is shown.
  const ready = details !== undefined;
  useEffect(() => {
    if (!notice || !ready) return;
    noticeRef.current?.scrollIntoView({ block: 'nearest' });
    noticeRef.current?.focus();
  }, [notice, ready]);

  // "Покани да влиза" in the list opens the profile at the invitation.
  useEffect(() => {
    if (!details || !wantsInvite.current) return;
    wantsInvite.current = false;
    inviteHeading.current?.scrollIntoView({ block: 'start' });
    inviteHeading.current?.focus();
  }, [details]);

  const back = <Link className={styles.back} href="/dashboard/team"><span aria-hidden="true">←&nbsp;</span>Екип</Link>;

  if (!canView) {
    return <div className={styles.page}>{back}<NoTeamAccess /></div>;
  }
  if (!details) {
    return (
      <div className={styles.page} aria-busy={!error}>
        {back}
        {error ? (
          <div className={styles.empty} role="alert">
            <strong>{error.status === 404 ? 'Не намерихме този човек' : error.text}</strong>
            <p>{error.status === 404 ? 'Може да е в друга организация или връзката да е грешна.' : 'Проверете връзката и опитайте отново.'}</p>
            {error.status === 404 ? <p><Link href="/dashboard/team">Към екипа</Link></p> : <p><button type="button" className={styles.secondary} onClick={reload}>Опитай отново</button></p>}
          </div>
        ) : (
          <>
            <span className="sr-only" role="status">Зареждане на профила…</span>
            <span className={styles.skeleton} />
            <div className={styles.skeletonList}><span className={styles.skeleton} /><span className={styles.skeleton} /></div>
          </>
        )}
      </div>
    );
  }

  const { member, history } = details;
  const status = memberStatus(member, now);
  const role = memberRole(member);
  const tabs: { id: Tab; label: string }[] = [
    { id: 'details', label: 'Данни' }, { id: 'groups', label: 'Групи' }, { id: 'rights', label: 'Права' },
    ...(history ? [{ id: 'history' as const, label: 'История' }] : []),
  ];
  const shownTab = tabs.some((item) => item.id === tab) ? tab : 'details';
  const editable = canManage && member.state !== 'archived';

  return (
    <div className={styles.page}>
      {back}
      <header className={styles.profileHead}>
        <span className={styles.avatar} aria-hidden="true">{initials(member.full_name)}</span>
        <div className={styles.heading}>
          <h1>{member.full_name}</h1>
          <p className={styles.headLine}>
            <span>{role ? roleLabel(role) : 'Без роля'}</span>
            <span className={`${styles.badge} ${styles[`tone_${status.tone}`]}`}>{status.label}</span>
            {member.phone ? <span>{member.phone}</span> : null}
          </p>
        </div>
      </header>

      <p ref={noticeRef} tabIndex={-1} className={notice ? styles.ok : 'sr-only'} role="status">{notice?.text ?? ''}</p>
      {error ? <p className={styles.error} role="alert">{error.text}</p> : null}
      {member.state === 'archived' ? (
        <p className={styles.info}>Записът е архивиран{member.archived_at ? ` на ${shortDay(sofiaDay(member.archived_at))}` : ''}. Не се показва в списъците и изборите.</p>
      ) : null}

      {canManage && member.state === 'profile_only' ? (
        <InviteCard key={member.id} organizationId={organization.id} actorRole={organization.role} member={member} now={now} headingRef={inviteHeading} onChanged={reload} />
      ) : null}

      <div className={styles.tabs} role="group" aria-label="Раздели на профила">
        {tabs.map((item) => (
          <button key={item.id} type="button" aria-pressed={shownTab === item.id} className={`${styles.tab} ${shownTab === item.id ? styles.tabOn : ''}`} onClick={() => setTab(item.id)}>{item.label}</button>
        ))}
      </div>

      {shownTab === 'details' ? (
        <>
          <section className={styles.card} aria-labelledby="details-title">
            <h2 id="details-title">Данни</h2>
            {editable ? <DetailsForm key={`${member.id}-${member.version}`} organizationId={organization.id} member={member} onChanged={reload} onNotice={notify} /> : (
              <dl className={styles.facts}>
                <div><dt>Име и фамилия</dt><dd>{member.full_name}</dd></div>
                {member.phone ? <div><dt>Телефон</dt><dd>{member.phone}</dd></div> : null}
                {member.email ? <div><dt>Имейл</dt><dd>{member.email}</dd></div> : null}
                <div><dt>Профил на преподавател</dt><dd>{member.instructor_profile_id ? 'Има' : 'Няма'}</dd></div>
              </dl>
            )}
            {!canManage ? <p className={styles.small}>Телефоните и имейлите на екипа виждат собственикът и администраторът.</p> : null}
          </section>
          {editable ? <ArchiveCard key={`archive-${member.version}`} organizationId={organization.id} member={member} onChanged={reload} onNotice={notify} /> : null}
        </>
      ) : null}
      {shownTab === 'groups' ? <MemberGroups organizationId={organization.id} member={member} canAssign={canAssign} canManage={canManage} onChanged={reload} /> : null}
      {shownTab === 'rights' ? (
        <RightsCard key={`rights-${member.version}`} organizationId={organization.id} actorRole={organization.role} member={member} canManage={canManage} onChanged={reload} onNotice={notify} />
      ) : null}
      {shownTab === 'history' && history ? <HistoryCard history={history} /> : null}
    </div>
  );
}
