'use client';
import Link from 'next/link';
import { useCallback, useEffect, useId, useRef, useState } from 'react';
import { useDashboardContext } from '../dashboard/dashboard-context';
import { sofiaDay } from '../schedule/presentation';
import type { TeachingGroup } from '../dashboard/api';
import { AttendanceSessionButton } from '../attendance/session-dialog';
import { RequestDialog } from './request-dialog';
import { loadMine, SubstituteError, withdrawRequest, type Mine, type SubstituteRequest } from './api';
import { canRequest, dateLabel, isOffice, requestStatus, when } from './presentation';
import styles from './substitute.module.css';

const changedEvent = 'substitute-requests-changed';
function announceChange() { window.dispatchEvent(new Event(changedEvent)); }
function useMine(org: string) {
  const [attempt, setAttempt] = useState(0);
  const [result, setResult] = useState<{ org: string; data?: Mine; error?: string }>();
  const retry = useCallback(() => setAttempt(n => n + 1), []);
  useEffect(() => {
    const controller = new AbortController(); let generation = 0;
    const read = () => {
      const current = ++generation;
      loadMine(org, controller.signal).then(data => { if (!controller.signal.aborted && generation === current) setResult({ org, data }); })
        .catch((error: unknown) => { if (!controller.signal.aborted && generation === current) setResult({ org, error: error instanceof Error ? error.message : 'Заявките не се заредиха.' }); });
    };
    read(); const timer = setInterval(read, 30000);
    window.addEventListener(changedEvent, read); window.addEventListener('focus', read);
    return () => { controller.abort(); clearInterval(timer); window.removeEventListener(changedEvent, read); window.removeEventListener('focus', read); };
  }, [org, attempt]);
  return { ...(result?.org === org ? result : undefined), retry };
}

export function TeacherSubstitutes({ groups, embedded = false }: { groups: TeachingGroup[]; embedded?: boolean }) {
  const { organization } = useDashboardContext();
  if (!groups.length || organization.role === 'platform_admin') return null;
  return <TeacherSection key={organization.id} embedded={embedded} allowRequest={canRequest(organization, groups)} />;
}
function TeacherSection({ embedded, allowRequest }: { embedded: boolean; allowRequest: boolean }) {
  const { organization } = useDashboardContext();
  const { data, error, retry } = useMine(organization.id);
  const id = useId();
  const [notice, setNotice] = useState('');
  const requests = data?.requests.filter(r => r.sessions.some(s => s.state !== 'withdrawn')) ?? [];
  const cards = [
    ...requests.map(request => ({ key: request.id, day: request.sessions.find(s => s.state !== 'withdrawn')!.starts_at, element: <RequestCard request={request} emailConfirmed={!!data?.email_confirmed} /> })),
    ...(embedded ? [] : data?.standing_in.filter(s => Date.parse(s.until) >= Date.parse(data.now)).map(s => ({ key: `${s.session_id}:${s.replaces.name}`, day: s.starts_at, element: <article className={`${styles.card} ${styles.standing}`} aria-label={`${s.children_now ? 'Замествате' : 'Ще замествате'} ${s.replaces.name}`}>
      <div className={styles.head}><span className={`${styles.badge} ${styles.limited}`}>{s.children_now ? 'Замествате' : 'Ще замествате'}</span><span>{when(s)}</span></div>
      <strong>{s.children_now ? 'Замествате' : 'Ще замествате'} {s.replaces.name}</strong>
      <p>{[s.activity, s.group, s.age_label, s.location].filter(Boolean).join(' · ')}</p>
      {s.children_now ? <><AttendanceSessionButton sessionId={s.session_id} className={styles.primary}>Отбележи присъствията</AttendanceSessionButton><p className={styles.small}>Групата е ваша до {dateLabel(s.until)}, 23:59 — после изчезва сама.</p></> : <p className={styles.small}>На {new Intl.DateTimeFormat('bg-BG', { day: 'numeric', month: 'long', timeZone: 'Europe/Sofia' }).format(new Date(s.starts_at))} ще виждате децата в групата — име и клас.</p>}
      <Link href={`/dashboard/schedule?view=week&date=${sofiaDay(s.starts_at)}`} className={styles.link}>Виж в графика →</Link>
    </article> })) ?? []),
  ].sort((a,b) => a.day.localeCompare(b.day));
  if (data && !allowRequest && !cards.length) return null;
  return <section className={styles.section} aria-labelledby={embedded ? undefined : `${id}-title`} aria-label={embedded ? 'Заместници' : undefined}>
    {!embedded ? <h2 id={`${id}-title`} className={styles.title}>Заместници</h2> : null}
    {allowRequest ? <RequestButton onSent={() => { setNotice(`Заявката е изпратена. Ще получите имейл, когато ${organization.name} реши.`); }} /> : null}
    <p className={notice ? styles.success : 'sr-only'} role="status">{notice}</p>
    {error ? <p role="alert">{error} <button className={styles.button} onClick={retry}>Опитай пак</button></p> : !data ? <div className={styles.skeleton} role="status">Зареждане на заявките…</div> : <div className={styles.stack}>{cards.map(card => <div key={card.key}>{card.element}</div>)}</div>}
  </section>;
}

function RequestCard({ request, emailConfirmed, sessionId, onChanged }: { request: SubstituteRequest; emailConfirmed: boolean; sessionId?: string; onChanged?: () => void }) {
  const { organization } = useDashboardContext();
  const [confirm, setConfirm] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const button = useRef<HTMLButtonElement>(null);
  const confirmHeading = useRef<HTMLParagraphElement>(null);
  const sessions = request.sessions.filter(s => s.state !== 'withdrawn' && (!sessionId || s.session_id === sessionId));
  useEffect(() => { if (confirm) confirmHeading.current?.focus(); }, [confirm]);
  async function withdraw() {
    if (busy) return;
    setBusy(true); setError('');
    try { await withdrawRequest(organization.id, request.id, request.version); setConfirm(false); announceChange(); onChanged?.(); }
    catch (error: unknown) {
      if (error instanceof SubstituteError && [409,422].includes(error.status)) { setConfirm(false); setError('Заявката е променена междувременно. Прегледайте я отново.'); announceChange(); onChanged?.(); }
      else setError(error instanceof Error ? error.message : 'Заявката не се отмени. Опитайте пак.');
    } finally { setBusy(false); }
  }
  return <article className={styles.card} aria-label="Заявка за заместник">
    {sessions.map(s => { const status = requestStatus(s); return <div key={s.session_id} className={styles.session}>
      <div className={styles.head}><span className={`${styles.badge} ${styles[status.tone]}`}>{status.label}</span><span>{when(s)}</span></div>
      <strong>{s.state === 'open' ? 'Търсите заместник · ' : ''}{s.activity} · {s.group}</strong><p>{status.text}</p>
      {s.state === 'assigned' && !s.now.teaching && !s.now.substitute ? <Link className={styles.link} href={`/dashboard/schedule?view=week&date=${sofiaDay(s.starts_at)}`}>Виж в графика →</Link> : null}
      {status.decision ? <p className={styles.small}>{status.decision}{emailConfirmed && s.state === 'assigned' ? ' · получихте имейл' : ''}</p> : null}
    </div>; })}
    {request.sessions.some(s => s.state === 'open') ? confirm ? <div className={styles.confirm}><p ref={confirmHeading} tabIndex={-1}>Да отменим ли заявката?{request.sessions.filter(s => s.state === 'open').length > 1 ? ' Ще отмените всички чакащи занимания в нея.' : ''}</p><div className={styles.choices}><button className={styles.button} disabled={busy} onClick={withdraw}>{busy ? 'Отменяне…' : 'Отмени заявката'}</button><button className={styles.button} disabled={busy} onClick={() => { setConfirm(false); requestAnimationFrame(() => button.current?.focus()); }}>Назад</button></div></div> : <button ref={button} className={styles.link} onClick={() => setConfirm(true)}>Отмени заявката</button> : null}
    {error ? <p role="alert">{error}</p> : null}
  </article>;
}

function RequestButton({ initialDay, sessionId, onSent, onChanged }: { initialDay?: string; sessionId?: string; onSent?: () => void; onChanged?: () => void }) {
  const { organization } = useDashboardContext();
  const [open, setOpen] = useState(false);
  const button = useRef<HTMLButtonElement>(null);
  const close = () => { setOpen(false); requestAnimationFrame(() => button.current?.focus()); };
  return <><button className={styles.entry} ref={button} onClick={() => setOpen(true)} aria-haspopup="dialog">Търся заместник <span aria-hidden="true">›</span></button>
    {open ? <RequestDialog organizationId={organization.id} initialDay={initialDay} sessionId={sessionId} onClose={close} onSent={() => { close(); announceChange(); onSent?.(); onChanged?.(); }} /> : null}</>;
}

/** Connect by session_id; the schedule never contains request reasons or notes. */
export function SessionSubstitutes({ sessionId, day, allowed, onChanged }: { sessionId: string; day: string; allowed: boolean; onChanged: () => void }) {
  const { organization } = useDashboardContext();
  if (isOffice(organization) || organization.role === 'platform_admin') return null;
  return <SessionRequests key={`${organization.id}:${sessionId}`} sessionId={sessionId} day={day} allowed={allowed} onChanged={onChanged} />;
}
function SessionRequests({ sessionId, day, allowed, onChanged }: { sessionId: string; day: string; allowed: boolean; onChanged: () => void }) {
  const { organization } = useDashboardContext();
  const { data, error, retry } = useMine(organization.id);
  const [notice, setNotice] = useState('');
  const pending = data?.requests.find(r => r.sessions.some(s => s.session_id === sessionId && s.state === 'open'));
  return <div className={styles.section}>
    {pending ? <><p>Търсите заместник · чака решение</p><RequestCard request={pending} emailConfirmed={!!data?.email_confirmed} sessionId={sessionId} onChanged={onChanged} /></> : allowed && data ? <RequestButton initialDay={day} sessionId={sessionId} onChanged={onChanged} onSent={() => setNotice(`Заявката е изпратена. Ще получите имейл, когато ${organization.name} реши.`)} /> : null}
    {error && allowed ? <p role="alert">{error} <button className={styles.button} onClick={retry}>Опитай пак</button></p> : null}
    <p role="status" className={notice ? styles.success : 'sr-only'}>{notice}</p>
  </div>;
}
