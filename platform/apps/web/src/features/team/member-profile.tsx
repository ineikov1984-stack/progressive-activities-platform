'use client';

import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { type FormEvent, useCallback, useEffect, useRef, useState } from 'react';
import { useDashboardContext } from '@/features/dashboard/dashboard-context';
import { shortDay, sofiaDay } from '@/features/enrollment/presentation';
import {
  type AccessCard, archiveMember, inviteMember, loadAccess, loadMember, type HistoryEntry, type MemberChanges, type MemberDetails, type SentInvitation,
  TeamApiError, type TeamMember, updateMember,
} from './api';
import { MemberGroups } from './member-groups';
import {
  focusLater, initials, invitableRoles, invitationFold, memberRole, memberStatus, roleLabel, roleLabels, type TeamRole,
} from './presentation';
import { accessSummary, draftFromCard, memberHistoryItem, rightsHeadline, templateLabel } from './rights';
import { AccessLists, RoleAccessList } from './role-access';
import { SentInvitationNotice } from './sent-invitation';
import { NoTeamAccess } from './team-page';
import styles from './team.module.css';

type Notify = (text: string) => void;

const historyTime = new Intl.DateTimeFormat('bg-BG', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Sofia' });
const failure = (reason: unknown, fallback: string) => (reason instanceof Error && reason.message ? reason.message : fallback);
/** History entries shown before "Цялата история". */
const HISTORY_SHOWN = 5;

/**
 * "Изпрати покана" for someone without access: folded to a sentence and the button, open from the start only when
 * the list's button led here. After sending, the form folds away; sending again gives new links and the old ones stop
 * working.
 */
function InviteCard({ organizationId, actorRole, member, now, startOpen, onChanged }: {
  organizationId: string; actorRole: string; member: TeamMember; now: Date; startOpen: boolean; onChanged: () => void;
}) {
  const roles = invitableRoles(actorRole);
  const [open, setOpen] = useState(startOpen);
  const [role, setRole] = useState<TeamRole>(member.instructor_profile_id ? 'instructor' : 'coordinator');
  const [email, setEmail] = useState(member.email ?? '');
  const [sent, setSent] = useState<SentInvitation>();
  const [again, setAgain] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [emailError, setEmailError] = useState('');
  const fold = invitationFold(member, now);
  const resending = Boolean(member.invitation || sent);
  const formShown = !sent || again;

  function show(next: boolean) {
    setOpen(next);
    focusLater(next ? 'invite-role' : 'invite-open');
  }

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
      setAgain(false);
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

  if (!open) {
    return (
      <section className={`${styles.card} ${styles.cardWarm}`} aria-label="Покана за достъп">
        <p>{fold.sentence}</p>
        <div className={styles.actions}>
          <button id="invite-open" type="button" className={styles.primary} aria-expanded={false} onClick={() => show(true)}>
            {fold.action}<span aria-hidden="true"> ▾</span>
          </button>
        </div>
      </section>
    );
  }

  return (
    <section id="invite" className={`${styles.card} ${styles.cardWarm}`} aria-label="Покана за достъп">
      <div className={styles.cardHead}>
        <h2>Покана за достъп</h2>
        <button type="button" className={styles.rowAction} aria-expanded aria-controls="invite-body" onClick={() => show(false)}>
          Скрий<span aria-hidden="true"> ▴</span>
        </button>
      </div>
      <div id="invite-body" className={styles.form}>
        {sent ? <SentInvitationNotice sent={sent} /> : null}
        {error ? <p className={styles.error} role="alert">{error}</p> : null}
        {formShown ? (
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
            <RoleAccessList role={role} when="later" />
            <div className={styles.actions}>
              <button type="submit" className={styles.primary} disabled={busy} aria-busy={busy} aria-describedby="invite-note">
                {busy ? 'Изпращане…' : resending ? 'Изпрати поканата отново' : 'Изпрати поканата'}
              </button>
            </div>
            <p id="invite-note" className={styles.small}>
              {resending ? 'Връзката от предишната покана спира да работи. ' : ''}Ще получи имейл с връзка, която важи 7 дни. От нея създава свой профил и парола.
            </p>
          </form>
        ) : (
          <div className={styles.form}>
            <div className={styles.actions}>
              <button type="button" className={styles.secondary} aria-describedby="invite-again-hint" onClick={() => { setAgain(true); focusLater('invite-email'); }}>
                Изпрати поканата отново
              </button>
            </div>
            <p id="invite-again-hint" className={styles.small}>Връзката от предишната покана спира да работи.</p>
          </div>
        )}
      </div>
    </section>
  );
}

/**
 * "Права" in the profile: the role, "Само преглед + 2 права" and the template's label, and what the person can do —
 * with the added rights. The card itself, with the role change, is its own page ("Промени правата").
 */
function RightsCard({ organizationId, member, canManage }: { organizationId: string; member: TeamMember; canManage: boolean }) {
  const [card, setCard] = useState<AccessCard>();
  const [failed, setFailed] = useState(false);
  const wanted = member.state !== 'archived';

  useEffect(() => {
    if (!wanted) return;
    let active = true;
    loadAccess(organizationId, member.id)
      .then((loaded) => { if (active) setCard(loaded); })
      .catch(() => { if (active) setFailed(true); });
    return () => { active = false; };
  }, [organizationId, member.id, wanted]);

  const href = `/dashboard/team/${encodeURIComponent(member.id)}/rights`;
  const withAccess = card?.state === 'access';
  const role = card?.role ?? (member.state === 'login' ? member.role : null);
  const draft = card ? draftFromCard(card) : null;
  const label = templateLabel(card?.template);
  return (
    <section className={styles.card} aria-labelledby="rights-title">
      <div className={styles.cardHead}>
        <h2 id="rights-title">Права</h2>
        {withAccess && (card?.editable || canManage) ? (
          <Link id="rights-open" className={styles.secondary} href={href}>{card?.editable ? 'Промени правата' : 'Виж правата'}</Link>
        ) : null}
      </div>
      {member.state === 'archived' ? (
        <p>Записът е в архива — няма достъп и роля в организацията.</p>
      ) : card && !withAccess ? (
        <p>Още няма роля, защото няма достъп. Ролята избирате в поканата.</p>
      ) : role ? (
        <>
          <p className={styles.headLine}>
            <span>Роля: <strong className={styles.strong}>{draft ? rightsHeadline(role, draft) : roleLabel(role)}</strong></span>
            {label ? <span className={`${styles.badge} ${styles.tone_info}`}>{label}</span> : null}
          </p>
          {member.state !== 'login' ? <p className={styles.small}>Ролята и правата важат, щом приеме поканата.</p> : null}
          {card && draft ? (
            <AccessLists summary={accessSummary({
              role, scope: draft.scope, grants: draft.grants, selected: draft.targets.length,
              teaches: card.rights.some((right) => right.source === 'assignment'),
            })} when={member.state === 'login' ? 'now' : 'later'} />
          ) : <RoleAccessList role={role} when={member.state === 'login' ? 'now' : 'later'} />}
          {failed ? <p className={styles.small}>Добавените права не се заредиха — показваме само ролята.</p> : null}
        </>
      ) : !card && !failed ? (
        <span className={styles.skeleton} aria-label="Зареждане на правата" />
      ) : (
        <p>Още няма роля, защото няма достъп. Ролята избирате в поканата.</p>
      )}
    </section>
  );
}

/** "Данни" for reading; "Промени" opens the fields with "Запази" and "Отказ", sent with the version. */
function DetailsCard({ organizationId, member, canManage, onChanged, onNotice }: {
  organizationId: string; member: TeamMember; canManage: boolean; onChanged: () => void; onNotice: Notify;
}) {
  const editable = canManage && member.state !== 'archived';
  const [editing, setEditing] = useState(false);
  const [values, setValues] = useState({ full_name: member.full_name, phone: member.phone ?? '', email: member.email ?? '' });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState('');
  const [stale, setStale] = useState(false);
  const [busy, setBusy] = useState(false);
  type Field = keyof typeof values;

  function start() {
    setValues({ full_name: member.full_name, phone: member.phone ?? '', email: member.email ?? '' });
    setErrors({}); setError(''); setStale(false); setEditing(true);
    focusLater('details-full_name');
  }

  function cancel() {
    setEditing(false); setErrors({}); setError(''); setStale(false);
    focusLater('details-change');
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const changes: MemberChanges = { version: member.version };
    if (values.full_name.trim() !== member.full_name) changes.full_name = values.full_name.trim();
    if (values.phone.trim() !== (member.phone ?? '')) changes.phone = values.phone.trim();
    if (values.email.trim() !== (member.email ?? '')) changes.email = values.email.trim() === '' ? null : values.email.trim();
    if (Object.keys(changes).length === 1) {
      onNotice('Нищо не е променено.');
      setEditing(false);
      return;
    }
    setBusy(true); setError(''); setErrors({}); setStale(false);
    try {
      await updateMember(organizationId, member.id, changes);
      onNotice('Данните са запазени.');
      setEditing(false);
      onChanged();
    } catch (reason) {
      if (reason instanceof TeamApiError && reason.status === 409) {
        setStale(true);
        setError('Някой току-що промени тези данни. Вашите промени не са запазени — заредете новите и опитайте отново.');
      } else {
        const fields = reason instanceof TeamApiError ? reason.fieldErrors : {};
        const shown = Object.fromEntries(Object.entries(fields).map(([field, messages]) => [field, messages[0] ?? '']));
        setErrors(shown);
        setError(failure(reason, 'Данните не бяха запазени.'));
        const first = (['full_name', 'phone', 'email'] as Field[]).find((field) => shown[field]);
        if (first) focusLater(`details-${first}`);
      }
    } finally {
      setBusy(false);
    }
  }

  const input = (field: Field, label: string, type: 'text' | 'tel' | 'email') => (
    <div className={styles.field}>
      <label htmlFor={`details-${field}`}>{label}</label>
      <input id={`details-${field}`} className={styles.input} type={type} autoComplete="off" value={values[field]}
        aria-invalid={errors[field] ? true : undefined} aria-describedby={errors[field] ? `details-${field}-error` : undefined}
        onChange={(event) => { const value = event.target.value; setValues((current) => ({ ...current, [field]: value })); }} />
      {errors[field] ? <p id={`details-${field}-error`} className={styles.fieldError}>{errors[field]}</p> : null}
    </div>
  );
  const notSet = <span className={styles.none}>не е въведен</span>;

  return (
    <section className={styles.card} aria-labelledby="details-title">
      <div className={styles.cardHead}>
        <h2 id="details-title">Данни</h2>
        {editable && !editing ? <button id="details-change" type="button" className={styles.secondary} onClick={start}>Промени</button> : null}
      </div>
      {editing ? (
        <form className={styles.form} onSubmit={submit} noValidate>
          {input('full_name', 'Име и фамилия', 'text')}
          {input('phone', 'Телефон', 'tel')}
          {input('email', 'Имейл (по избор)', 'email')}
          {error ? <p className={styles.error} role="alert">{error}</p> : null}
          <div className={styles.actions}>
            {stale
              ? <button type="button" className={styles.primary} onClick={() => { setEditing(false); onChanged(); }}>Зареди новите данни</button>
              : <button type="submit" className={styles.primary} disabled={busy} aria-busy={busy}>{busy ? 'Запазване…' : 'Запази'}</button>}
            <button type="button" className={styles.secondary} onClick={cancel}>Отказ</button>
          </div>
        </form>
      ) : (
        <dl className={styles.facts}>
          <div><dt>Име и фамилия</dt><dd>{member.full_name}</dd></div>
          {canManage ? <div><dt>Телефон</dt><dd>{member.phone ?? notSet}</dd></div> : null}
          {canManage ? <div><dt>Имейл</dt><dd>{member.email ?? notSet}</dd></div> : null}
          <div><dt>Води групи</dt><dd>{member.instructor_profile_id ? 'Да' : 'Не'}</dd></div>
        </dl>
      )}
      {!canManage ? <p className={styles.small}>Телефоните и имейлите на екипа виждат собственикът и администраторът.</p> : null}
    </section>
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
      onNotice(`${member.full_name} е в архива.`);
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
      <h2 id="archive-title">Архив</h2>
      <p className={styles.muted}>Записът се скрива от списъците и изборите, а достъпът до организацията спира. Нищо не се изтрива.</p>
      {error ? <p className={styles.error} role="alert">{error}</p> : null}
      {confirming ? (
        <div className={styles.confirm}>
          <p>Да преместим ли {member.full_name} в архива?</p>
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

/** "История": what, who and when, as nouns — no grammatical gender. */
function HistoryCard({ history }: { history: HistoryEntry[] }) {
  const [all, setAll] = useState(false);
  const shown = all ? history : history.slice(0, HISTORY_SHOWN);
  return (
    <section className={styles.card} aria-labelledby="history-title">
      <div className={styles.cardHead}>
        <h2 id="history-title">История</h2>
        {history.length > HISTORY_SHOWN ? (
          <button type="button" className={styles.rowAction} aria-expanded={all} aria-controls="history-list" onClick={() => setAll((value) => !value)}>
            {all ? 'Само последните' : 'Цялата история'}
          </button>
        ) : null}
      </div>
      {history.length === 0 ? <p className={styles.muted}>Още няма записани промени.</p> : (
        <ul id="history-list" className={styles.rows}>
          {shown.map((entry, index) => {
            const item = memberHistoryItem(entry);
            return (
              <li key={`${entry.at}-${index}`}>
                <span><strong>{item.what}</strong>{item.who ? ` — ${item.who}` : ''}</span>
                {entry.at ? <time className={styles.small} dateTime={entry.at}>{historyTime.format(new Date(entry.at))}</time> : null}
              </li>
            );
          })}
        </ul>
      )}
    </section>
  );
}

/**
 * The profile of a person of the team (docs/product/team.md, т. 10; DECISIONS 31.10.2026 „Екип — полиран списък и
 * профил“): one page without tabs — on a wide screen "Групи" and "История" on the left, "Права", "Данни" and "Архив" on
 * the right; on a phone one under another, with "Изпрати покана" folded.
 */
export function MemberProfile({ memberId }: { memberId: string }) {
  const { organization } = useDashboardContext();
  const searchParams = useSearchParams();
  const canView = organization.permissions.includes('team.view');
  const canManage = organization.permissions.includes('team.manage');
  const canAssign = organization.permissions.includes('catalog.manage');
  const canOpenGroups = organization.permissions.includes('enrollment.view');
  const [attempt, setAttempt] = useState(0);
  const [loaded, setLoaded] = useState<{ memberId: string; details: MemberDetails }>();
  const [failed, setFailed] = useState<{ key: string; text: string; status: number }>();
  const [notice, setNotice] = useState<{ text: string }>();
  const [now] = useState(() => new Date());
  const [inviteOpen] = useState(() => searchParams.get('invite') === '1');
  // Right after adding the person the form for the first group opens by itself; "+ Добави група" adds one more.
  const [addRequest, setAddRequest] = useState(() => (searchParams.get('added') === '1' ? 1 : 0));
  const addedPending = useRef(searchParams.get('added') === '1');
  const noticeRef = useRef<HTMLParagraphElement>(null);
  const wantsInvite = useRef(inviteOpen);
  const key = `${organization.id}|${memberId}|${attempt}`;

  useEffect(() => {
    if (!canView) return;
    let active = true;
    loadMember(organization.id, memberId)
      .then((details) => {
        if (!active) return;
        setLoaded({ memberId, details });
        if (addedPending.current) {
          addedPending.current = false;
          setNotice({ text: `${details.member.full_name} е в екипа. Сега добавете групите, които води.` });
        }
      })
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

  useEffect(() => {
    if (!notice) return;
    noticeRef.current?.scrollIntoView({ block: 'nearest' });
    noticeRef.current?.focus();
  }, [notice]);

  // The list's "Изпрати покана" opens the profile at the invitation, already open.
  useEffect(() => {
    if (!details || !wantsInvite.current) return;
    wantsInvite.current = false;
    document.getElementById('invite')?.scrollIntoView({ block: 'start' });
    focusLater('invite-role');
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
            <strong>{error.status === 404 ? 'Не намерихме този профил' : error.text}</strong>
            <p>{error.status === 404 ? 'Може да е в друга организация или връзката да е грешна.' : 'Проверете интернет връзката и опитайте отново.'}</p>
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
  const editable = canManage && member.state !== 'archived';
  const canAddGroups = canAssign && member.state !== 'archived' && member.instructor_profile_id !== null;
  // With access, "+ Добави група" is the main action in the header; without, "Изпрати покана" is.
  const addInHeader = canAddGroups && !(canManage && member.state === 'profile_only');

  return (
    <div className={styles.page}>
      {back}
      <header className={styles.profileHead}>
        <span className={styles.avatar} aria-hidden="true">{initials(member.full_name)}</span>
        <div className={styles.heading}>
          <h1>{member.full_name}</h1>
          <p className={styles.headLine}>
            <span className={styles.headRole}>{role ? roleLabel(role) : 'Няма роля'}</span>
            <span className={`${styles.badge} ${styles[`tone_${status.tone}`]}`}>{status.label}</span>
            {status.detail ? <span className={styles.small}>{status.detail}</span> : null}
            {member.phone ? <a className={styles.phone} href={`tel:${member.phone}`}>{member.phone}</a> : null}
          </p>
        </div>
        {addInHeader ? (
          <button id="add-group-header" type="button" className={`${styles.primary} ${styles.wideOnly}`} onClick={() => setAddRequest((value) => value + 1)}>+ Добави група</button>
        ) : null}
      </header>

      <p ref={noticeRef} tabIndex={-1} className={notice ? styles.ok : 'sr-only'} role="status">{notice?.text ?? ''}</p>
      {error ? <p className={styles.error} role="alert">{error.text}</p> : null}
      {member.state === 'archived' ? (
        <p className={styles.info}>В архива{member.archived_at ? ` от ${shortDay(sofiaDay(member.archived_at))}` : ''}. Не се показва в списъците и изборите.</p>
      ) : null}

      {canManage && member.state === 'profile_only' ? (
        <InviteCard key={member.id} organizationId={organization.id} actorRole={organization.role} member={member} now={now} startOpen={inviteOpen} onChanged={reload} />
      ) : null}

      <div className={styles.columns}>
        <div className={styles.column}>
          <MemberGroups organizationId={organization.id} member={member} canAssign={canAssign} canManage={canManage} canOpenGroups={canOpenGroups} onChanged={reload}
            addRequest={addRequest} addInHeader={addInHeader} />
          {history ? <HistoryCard history={history} /> : null}
        </div>
        <div className={styles.column}>
          <RightsCard key={`rights-${member.version}`} organizationId={organization.id} member={member} canManage={canManage} />
          <DetailsCard key={`details-${member.version}`} organizationId={organization.id} member={member} canManage={canManage} onChanged={reload} onNotice={notify} />
          {editable ? <ArchiveCard key={`archive-${member.version}`} organizationId={organization.id} member={member} onChanged={reload} onNotice={notify} /> : null}
        </div>
      </div>
    </div>
  );
}
