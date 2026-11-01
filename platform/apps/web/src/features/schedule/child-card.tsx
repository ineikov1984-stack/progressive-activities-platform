'use client';

import Link from 'next/link';
import { type FormEvent, type MouseEvent, type SyntheticEvent, useEffect, useId, useRef, useState } from 'react';
import { changeErrorMessage, skipSession } from '../enrollment/api';
import { ContactReveal } from '../enrollment/contact-reveal';
import { type ChildCard, loadChildCard, ScheduleApiError, type ScheduleSession, type SessionChild } from './api';
import {
  canSkipSession, type CardContext, cardGroupState, cardGroupTitle, childCardMeta, contactsGroupOf, initials, pauseGroupOf, pauseLink, profileLink,
  sessionGroupOf, sessionWhen,
} from './presentation';
import { CloseIcon } from './icons';
import styles from './schedule.module.css';
import { cellLabels, cellSigns, date, format, clock } from '../attendance/presentation';
import attendanceStyles from '../attendance/attendance.module.css';

export type CardSession = Pick<ScheduleSession, 'id' | 'group_id' | 'starts_at'> & Partial<Pick<ScheduleSession, 'ends_at' | 'status'>>;

type Props = {
  organizationId: string;
  /** The child's row in the panel of the session — the card is opened from its eye. */
  child: Pick<SessionChild, 'enrollment_id' | 'name' | 'state'> & { child_id: string };
  session: CardSession;
  /** "Профил на детето →" for those who see the enrollments. */
  withDetails: boolean;
  now: Date;
  onClose: () => void;
  /** The child's name, for the label of the filter "Дете" (т. 3.5). */
  onNamed?: (id: string, name: string) => void;
  /** A change was saved: the panel reads its children again. */
  onChanged?: () => void;
};

const reasonHint = 'Пишете така, както бихте казали на родителя. Без диагнози и лични подробности.';

/**
 * The card of a child (docs/product/schedule.md, т. 4.1; макет `ScheduleChild`): beside the panel on a computer, a sheet
 * from the bottom on a phone and a tablet. A modal dialog over the panel — the focus goes into it, Esc, ✕ or a click
 * outside close it and the panel gives the focus back to the eye. Name, class, age and the groups the person sees; the
 * parent's phone only through "Покажи телефона" (written down), and only the buttons the person may use.
 */
export function ChildCardDialog({ organizationId, child, session, withDetails, now, onClose, onNamed, onChanged }: Props) {
  const dialog = useRef<HTMLDialogElement>(null);
  const heading = useRef<HTMLHeadingElement>(null);
  const titleId = useId();
  const [attempt, setAttempt] = useState(0);
  const cardKey = `${organizationId}:${child.child_id}:${session.starts_at}`;
  const key = `${cardKey}#${attempt}`;
  const [result, setResult] = useState<{ key: string; card?: ChildCard; error?: string }>();
  // Read in the answer, not a reason to load again.
  const named = useRef(onNamed);
  useEffect(() => { named.current = onNamed; });

  useEffect(() => {
    const element = dialog.current;
    if (element && !element.open) element.showModal();
    heading.current?.focus();
  }, []);

  useEffect(() => {
    let active = true;
    const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Sofia', year: 'numeric', month: '2-digit' }).format(new Date(session.starts_at));
    loadChildCard(organizationId, child.child_id, parts)
      .then((card) => {
        if (!active) return;
        setResult({ key, card });
        if (card.name) named.current?.(card.id, card.name);
      })
      .catch((reason: unknown) => {
        if (active) setResult({ key, error: reason instanceof ScheduleApiError ? reason.message : 'Картата не се зареди. Проверете връзката и опитайте пак.' });
      });
    return () => { active = false; };
  }, [organizationId, child.child_id, key, session.starts_at]);

  const loaded = result?.key === key ? result : undefined;
  // After a change the card reads itself again: the last one stays until then, so nothing jumps.
  const card = loaded?.card ?? (loaded ? undefined : result?.key.startsWith(`${cardKey}#`) ? result.card : undefined);
  const name = card?.name || child.name;
  const context: CardContext = { session: { ...session, status: session.status ?? 'scheduled' }, child };

  // The close of this dialog is not the panel's: the event stops here.
  function closed(event: SyntheticEvent<HTMLDialogElement>) {
    event.stopPropagation();
    onClose();
  }
  function outside(event: MouseEvent<HTMLDialogElement>) {
    if (event.target === dialog.current) dialog.current?.close();
  }

  return (
    <dialog ref={dialog} className={styles.childCard} aria-labelledby={titleId} onClose={closed} onClick={outside}>
      <span className={styles.grabber} aria-hidden="true" />
      <header className={styles.cardHead}>
        <span className={styles.avatar} aria-hidden="true">{initials(name)}</span>
        <div className={styles.cardTitle}>
          <h2 id={titleId} ref={heading} tabIndex={-1}>{name}</h2>
          {card && childCardMeta(card) ? <p className={styles.meta}>{childCardMeta(card)}</p> : null}
        </div>
        <button type="button" className={styles.iconButton} aria-label={`Затвори картата на ${name}`} onClick={() => dialog.current?.close()}><CloseIcon /></button>
      </header>
      <div className={styles.cardBody}>
        {loaded?.error ? (
          <div className={styles.problem}>
            <p role="alert">{loaded.error}</p>
            <button type="button" className={styles.button} onClick={() => setAttempt((value) => value + 1)}>Опитай пак</button>
          </div>
        ) : !card ? (
          <>
            <span className="sr-only" role="status">Зареждане на картата…</span>
            <div className={styles.skeletonKids} aria-hidden="true">{[0, 1, 2].map((item) => <span key={item} className={styles.skeleton} />)}</div>
          </>
        ) : (
          <CardContent organizationId={organizationId} card={card} name={name} context={context} session={session} withDetails={withDetails} now={now}
            onSkipped={() => { setAttempt((value) => value + 1); onChanged?.(); }} />
        )}
      </div>
    </dialog>
  );
}

function CardContent({ organizationId, card, name, context, session, withDetails, now, onSkipped }: {
  organizationId: string; card: ChildCard; name: string; context: CardContext; session: CardSession; withDetails: boolean; now: Date; onSkipped: () => void;
}) {
  const ids = useId();
  const contacts = contactsGroupOf(card, context);
  const pause = pauseGroupOf(card, context);
  const profile = profileLink(card, context, withDetails);
  const own = sessionGroupOf(card, context);
  const [skipping, setSkipping] = useState(false);
  const [skipped, setSkipped] = useState('');
  const skip = !skipped && canSkipSession(card, context, now) && own ? own : undefined;

  return (
    <>
      {own?.attendance ? <section className={styles.cardSection} aria-labelledby={`${ids}-attendance`}>
        <h3 className={styles.cardLabel} id={`${ids}-attendance`}>Присъствия през {format(`${own.attendance.month}-01T12:00:00Z`, { month: 'long' })}</h3>
        <strong>{own.attendance.present} от {own.attendance.present + own.attendance.absent} · {own.attendance.percent === null ? '—' : `${own.attendance.percent} %`}</strong>
        <div className={attendanceStyles.dots}>{own.attendance.sessions.map((item, index) => <span key={`${item.session_id}-${index}`} className={`${attendanceStyles.dot} ${item.state === 'present' ? attendanceStyles.present : ''}`}><span aria-hidden="true">{cellSigns[item.state] ?? '?'}</span><span className="sr-only">{date(item.starts_at)}: {cellLabels[item.state] ?? item.state}</span></span>)}</div>
        <p className={styles.small}>{own.attendance.sessions.map((item) => `${date(item.starts_at)}${['cancelled', 'not_held'].includes(item.state) ? ` (${cellLabels[item.state].toLowerCase()})` : ''}`).join(', ') || 'Няма минали занимания през месеца.'}</p>
      </section> : null}
      <section className={styles.cardSection} aria-labelledby={`${ids}-groups`}>
        <h3 id={`${ids}-groups`} className={styles.cardLabel}>Групи</h3>
        {card.groups.length === 0 ? <p className={styles.small}>Няма групи, в които виждате децата.</p> : (
          <ul className={styles.cardGroups}>
            {card.groups.map((group) => {
              const state = cardGroupState(group);
              return (
                <li key={group.enrollment_id}>
                  <span>{cardGroupTitle(group)}</span>
                  <span className={`${styles.state} ${styles[`state_${state.tone}`]}`}>{state.text}</span>
                </li>
              );
            })}
          </ul>
        )}
      </section>

      {contacts ? (
        <section className={styles.cardSection} aria-labelledby={`${ids}-parents`}>
          <h3 id={`${ids}-parents`} className={styles.cardLabel}>Родители</h3>
          {/* The name and the phone come only from the reveal, which is written down (team-rights.md, т. 6). */}
          <ContactReveal organizationId={organizationId} target={{ kind: 'enrollment', id: contacts.enrollment_id }} from="schedule" childName={name}
            hasPhone hasEmail showName />
        </section>
      ) : null}

      {skipped ? <p className={styles.done} role="status">{skipped}</p> : null}
      {skip && skipping ? (
        <SkipForm organizationId={organizationId} enrollment={{ id: skip.enrollment_id, version: skip.version }} session={session} name={name}
          onCancel={() => setSkipping(false)}
          onDone={(message) => { setSkipping(false); setSkipped(message); onSkipped(); }} />
      ) : null}

      {(skip && !skipping) || pause ? (
        <div className={styles.cardActions}>
          {skip && !skipping ? <button type="button" className={styles.button} onClick={() => setSkipping(true)}>Пропусни това занимание</button> : null}
          {pause ? <Link className={styles.button} href={pauseLink(pause)}>Пауза</Link> : null}
        </div>
      ) : null}
      {!card.groups.some((group) => group.abilities.contacts) ? <p className={styles.small}>Учителят не вижда телефоните на родителите и плащанията.</p> : null}
      {profile ? <Link className={styles.buttonPrimary} href={profile}>Профил на детето <span aria-hidden="true">→</span></Link> : null}
    </>
  );
}

/** A short confirmation: which session, and the explanation for the parent the API asks for (as in "Записвания"). */
function SkipForm({ organizationId, enrollment, session, name, onCancel, onDone }: {
  organizationId: string; enrollment: { id: string; version: number }; session: CardSession; name: string;
  onCancel: () => void; onDone: (message: string) => void;
}) {
  const id = useId();
  const field = useRef<HTMLTextAreaElement>(null);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const when = session.ends_at ? sessionWhen({ starts_at: session.starts_at, ends_at: session.ends_at }) : `${date(session.starts_at)} · ${clock(session.starts_at)}`;

  useEffect(() => { field.current?.focus(); }, []);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!reason.trim()) {
      setError('Напишете обяснение за родителя.');
      field.current?.focus();
      return;
    }
    setBusy(true);
    setError('');
    try {
      await skipSession(organizationId, enrollment, { sessionId: session.id, reason });
      onDone(`Записано е, че ${name} пропуска заниманието — ${when}.`);
    } catch (failure) {
      setError(changeErrorMessage(failure, 'Пропускът не беше запазен.'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className={styles.skipForm} onSubmit={submit} noValidate aria-labelledby={`${id}-title`}>
      <p id={`${id}-title`} className={styles.skipTitle}>Пропусни заниманието — {when}</p>
      <p className={styles.small}>Заниманието няма да се брои за {name}.</p>
      <label className={styles.childLabel} htmlFor={`${id}-reason`}>Обяснение за родителя</label>
      <textarea ref={field} id={`${id}-reason`} className={styles.skipReason} value={reason} maxLength={500} aria-describedby={`${id}-hint`}
        aria-invalid={error ? true : undefined} onChange={(event) => { setReason(event.target.value); setError(''); }} />
      <p id={`${id}-hint`} className={styles.small}>{reasonHint}</p>
      {error ? <p className={styles.childProblem} role="alert">{error}</p> : null}
      <div className={styles.cardActions}>
        <button type="submit" className={styles.buttonPrimary} disabled={busy}>{busy ? 'Запазване…' : 'Пропусни'}</button>
        <button type="button" className={styles.button} onClick={onCancel} disabled={busy}>Отказ</button>
      </div>
    </form>
  );
}
