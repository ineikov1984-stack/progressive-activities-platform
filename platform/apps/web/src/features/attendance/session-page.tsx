'use client';
import Link from 'next/link';
import { useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'next/navigation';
import { useDashboardContext } from '../dashboard/dashboard-context';
import { ChildCardDialog } from '../schedule/child-card';
import { AttendanceError, loadSheet, saveAttendance, type Answer, type Applied, type AttendanceChild, type Change, type Mark, type Sheet } from './api';
import { childLabel, clock, deadlineText, historyLines, initialView, markLabel, undoAllowed, when } from './presentation';
import { AttendanceQueue } from './queue';
import styles from './attendance.module.css';
type Intent = { id: string; action: Change['action']; marks?: { enrollment_id: string; status: Mark; version?: number }[]; topic?: string; reason?: string; undo?: boolean };
function project(base: Sheet, intents: Intent[]): Sheet {
  const next = { ...base, session: { ...base.session }, children: base.children.map((c) => ({ ...c })), counts: { ...base.counts } };
  for (const intent of intents) {
    if (intent.action === 'topic') { next.session.topic = intent.topic ?? null; continue; }
    for (const child of next.children) {
      const mark = intent.marks?.find((m) => m.enrollment_id === child.enrollment_id);
      if (mark || (intent.action === 'all_present' && child.expected && child.status === null && !child.warned)) {
        child.status = mark ? mark.status : 'present'; child.warned = child.expected && child.status !== 'present' && !!(child.skip || child.late_cancelled);
        if (child.status === null && child.warned) child.status = 'absent'; child.came_while_paused = !child.expected && child.status === 'present';
      }
    }
  }
  next.counts.present = next.children.filter((c) => c.expected && c.status === 'present').length;
  next.counts.absent = next.children.filter((c) => c.expected && c.status === 'absent').length;
  next.counts.unmarked = next.children.filter((c) => c.expected && c.status === null).length;
  next.counts.present_paused = next.children.filter((c) => c.came_while_paused).length;
  return next;
}
export function AttendanceSessionPage({ sessionId }: { sessionId: string }) {
  const { organization } = useDashboardContext();
  return <SessionLoader key={`${organization.id}:${sessionId}`} organizationId={organization.id} sessionId={sessionId} withDetails={organization.permissions.includes('enrollment.view')} />;
}
function SessionLoader({ organizationId, sessionId, withDetails }: { organizationId: string; sessionId: string; withDetails: boolean }) {
  const [result, setResult] = useState<{ sheet?: Sheet; error?: string }>(); const [attempt, retry] = useState(0);
  useEffect(() => { const c = new AbortController(); loadSheet(organizationId, sessionId, c.signal).then((sheet) => setResult({ sheet })).catch((error: unknown) => { if (!c.signal.aborted) setResult({ error: error instanceof AttendanceError ? error.message : 'Присъствията не се заредиха.' }); }); return () => c.abort(); }, [organizationId, sessionId, attempt]);
  if (result?.sheet) return <SheetEditor initial={result.sheet} organizationId={organizationId} withDetails={withDetails} />;
  return <div className={styles.page}><Link className={styles.link} href="/dashboard">← Днес</Link>{result?.error ? <><p role="alert">{result.error}</p><button className={styles.button} onClick={() => retry((n) => n + 1)}>Опитай пак</button></> : <div className={styles.skeleton} aria-label="Зареждане на присъствията" aria-busy="true" />}</div>;
}
function SheetEditor({ initial, organizationId, withDetails }: { initial: Sheet; organizationId: string; withDetails: boolean }) {
  const search = useSearchParams(); const from = search.get('from'); const back = from === 'schedule' ? '/dashboard/schedule' : from === 'month' ? '/dashboard/attendance' : '/dashboard';
  const [now, setNow] = useState(() => new Date());
  const [pendingRows, setPendingRows] = useState<string[]>([]); const epoch = useRef(0);
  const [sheet, setSheet] = useState(initial); const base = useRef(initial); const intents = useRef<Intent[]>([]);
  const [mode, setMode] = useState(initialView(initial)); const [reason, setReason] = useState(''); const reasonField = useRef<HTMLTextAreaElement>(null);
  const [topic, setTopic] = useState(initial.session.topic ?? ''); const topicRef = useRef(topic); const lastTopic = useRef(topic);
  const [notice, setNotice] = useState(''); const [queueState, setQueueState] = useState({ pending: 0, failed: false, offline: false });
  const queue = useRef<AttendanceQueue | null>(null); const [child, setChild] = useState<AttendanceChild>(); const eye = useRef<HTMLButtonElement | null>(null);
  const [toast, setToast] = useState<{ text: string; applied: Applied[] }>(); const [hoverToast, setHoverToast] = useState(false); const [focusToast, setFocusToast] = useState(false); const pauseToast = hoverToast || focusToast;
  const enqueueRef = useRef<(intent: Omit<Intent, 'id'>) => void>(() => {});
  const repaint = () => { setSheet(project(base.current, intents.current)); setPendingRows(intents.current.flatMap((i) => i.action === 'all_present' ? ['all'] : (i.marks ?? []).map((m) => m.enrollment_id))); };
  useEffect(() => {
    const abort = new AbortController(); const q = new AttendanceQueue((body, key) => saveAttendance(organizationId, initial.session.id, body, key, abort.signal), () => setQueueState({ pending: q.pending, failed: q.failed, offline: q.retrying })); queue.current = q;
    const online = () => q.retry(); window.addEventListener('online', online);
    const unload = (event: BeforeUnloadEvent) => { if (q.pending || topicRef.current.trim() !== (base.current.session.topic ?? '')) { event.preventDefault(); event.returnValue = ''; } }; window.addEventListener('beforeunload', unload);
    const refresh = setInterval(() => { setNow(new Date()); const generation = epoch.current; if (q.pending || topicRef.current.trim() !== (base.current.session.topic ?? '')) return; loadSheet(organizationId, initial.session.id, abort.signal).then((fresh) => { if (!abort.signal.aborted && !q.pending && generation === epoch.current) { base.current = fresh; setSheet(fresh); setTopic(fresh.session.topic ?? ''); topicRef.current = fresh.session.topic ?? ''; lastTopic.current = fresh.session.topic ?? ''; } }).catch(() => {}); }, 30000);
    return () => { q.dispose(); abort.abort(); clearInterval(refresh); window.removeEventListener('online', online); window.removeEventListener('beforeunload', unload); };
  }, [organizationId, initial.session.id]);
  useEffect(() => { if (!toast || pauseToast) return; const timer = setTimeout(() => setToast(undefined), 6000); return () => clearTimeout(timer); }, [toast, pauseToast]);
  useEffect(() => { const timer = setTimeout(() => { if (topic.trim() !== lastTopic.current.trim()) enqueueRef.current({ action: 'topic', topic }); }, 800); return () => clearTimeout(timer); }, [topic]);
  function enqueue(draft: Omit<Intent, 'id'>) {
    if (!queue.current || !base.current.abilities.mark) return;
    if (draft.action !== 'topic' && base.current.abilities.reason_required && !reason.trim()) { setNotice('Напишете причина за поправката.'); reasonField.current?.focus(); return; }
    const intent: Intent = { ...draft, id: crypto.randomUUID(), ...(draft.action !== 'topic' && reason.trim() ? { reason: reason.trim() } : {}) };
    if (intent.action === 'topic') lastTopic.current = intent.topic ?? '';
    epoch.current++; intents.current.push(intent); repaint();
    queue.current.add(intent.id, () => intent.action === 'mark' ? { action: 'mark', reason: intent.reason, marks: (intent.marks ?? []).map((m) => ({ enrollment_id: m.enrollment_id, status: m.status, version: m.version ?? base.current.children.find((c) => c.enrollment_id === m.enrollment_id)?.version ?? 0 })) } : intent.action === 'topic' ? { action: 'topic', topic: intent.topic?.trim() || null } : { action: 'all_present', reason: intent.reason }, (answer, error) => {
      intents.current = intents.current.filter((i) => i.id !== intent.id);
      if (answer) { base.current = answer;
        if (intent.undo) setToast({ text: `Отменено: ${answer.applied.length} ${answer.applied.length === 1 ? 'дете' : 'деца'}.`, applied: [] });
        else if (intent.action !== 'topic' && answer.applied.length) { const one = answer.applied[0]; const name = answer.children.find((c) => c.enrollment_id === one.enrollment_id)?.name ?? 'Дете'; setToast({ text: answer.applied.length === 1 ? `Записано: ${name} — ${markLabel(one.to)}` : `Записано: ${answer.applied.length} деца — Присъства`, applied: undoAllowed(answer.applied, answer.abilities.reason_required) ? answer.applied : [] }); }
        setNotice('');
      } else if (error instanceof AttendanceError) {
        if (error.status === 409 && error.body.children && error.body.session && error.body.abilities && error.body.counts) {
          base.current = error.body as Answer; const changed = error.body.children.find((c) => error.body.conflicts?.includes(c.enrollment_id));
          setNotice(changed ? `Междувременно ${changed.marked_by ?? 'член на екипа'} отбеляза ${changed.name}: „${markLabel(changed.status)}“. Вижте новото състояние.` : error.message);
        } else { setNotice(error.status === 403 ? 'Срокът за отбелязване изтече или нямате право за промяна.' : error.message); if (error.status === 403) { base.current = { ...base.current, abilities: { ...base.current.abilities, mark: false } }; setMode('view'); } }
      }
      repaint();
    });
  }
  useEffect(() => { enqueueRef.current = enqueue; });
  function saveTopic() { if (topic.trim() !== lastTopic.current.trim()) enqueue({ action: 'topic', topic }); }
  function leave(event: React.MouseEvent<HTMLAnchorElement>) { saveTopic(); if ((queue.current?.pending || topic.trim() !== (base.current.session.topic ?? '')) && !window.confirm('Има незаписани промени. Искате ли да излезете?')) event.preventDefault(); }
  const editing = mode === 'mark' && sheet.abilities.mark; const s = sheet.session;
  const hidden = s.status === 'cancelled' || !!s.not_held;
  const history = historyLines(sheet.history, sheet.children);
  const childRow = (c: AttendanceChild) => <div className={`${styles.child} ${!editing ? styles.readRow : ''}`} key={c.enrollment_id} role="group" aria-labelledby={`child-${c.enrollment_id}`}>
    <div className={styles.rowHead}><div className={styles.childName}><strong id={`child-${c.enrollment_id}`}>{c.name}</strong>{c.class ? <span className={styles.muted}>· {c.class}</span> : null}{c.scope !== 'full_period' ? <span className={styles.badge}>{c.scope === 'trial' ? 'Пробно' : 'Еднократно'}</span> : null}</div>
    {c.child_id ? <button className={styles.icon} aria-label={`Виж ${c.name}`} onClick={(event) => { eye.current = event.currentTarget; setChild(c); }}><svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button> : null}</div>
    {!c.expected && editing ? <p className={styles.muted}>В пауза{c.paused_until ? ` до ${formatPause(c.paused_until)}` : ''}</p> : null}
    {c.warned && editing ? <p className={styles.warning}>Предупредено отсъствие · {c.skip?.by === 'parent' ? 'Родителят предупреди' : c.skip ? 'Екипът предупреди' : 'Късен отказ от посещение'}{c.skip ? ` · ${clock(c.skip.at)}` : ''}. Ако детето дойде, отбележете „Присъства“.</p> : null}
    {editing ? <div className={styles.buttons}>{(c.expected ? ['present', 'absent'] as const : ['present'] as const).map((status) => <button key={status} className={c.status === status ? status === 'present' ? styles.good : styles.absent : styles.button} aria-pressed={c.status === status} onClick={() => { if (c.status !== status) enqueue({ action: 'mark', marks: [{ enrollment_id: c.enrollment_id, status }] }); }}>{c.status === status ? status === 'present' ? '✓ ' : '✕ ' : ''}{markLabel(status)}</button>)}</div> : <p className={`${styles.readStatus} ${c.status === 'present' ? styles.present : c.status === 'absent' ? styles.warning : styles.muted}`}>{c.status === 'present' ? '✓ ' : c.status === 'absent' ? '✕ ' : ''}{childLabel(c)}</p>}
    {c.came_while_paused && editing ? <p className={styles.present}>✓ Присъства · дошло по време на пауза</p> : null}
    {(pendingRows.includes('all') || pendingRows.includes(c.enrollment_id)) ? <p className={styles.muted}>{queueState.failed ? 'Не е записано · Опитай пак' : 'Записва се…'}</p> : null}
  </div>;
  return <div className={styles.page}>
    <Link className={styles.link} href={back} onClick={leave}>← {from === 'schedule' ? 'График' : from === 'month' ? 'Присъствия' : 'Днес'}</Link>
    <h1 className={styles.heading}>{s.activity.title} · {s.group.name}{s.group.age_label ? ` · ${s.group.age_label}` : ''}</h1><p className={styles.muted}>{when(s.starts_at, s.ends_at)}{s.location ? ` · ${s.location.name}` : ''}</p>
    {queueState.pending > 0 ? <div role="status" className={styles.error}>{queueState.failed ? 'Не е записано.' : queueState.offline ? 'Няма връзка. Отметките ще се запишат, щом се върне.' : 'Записва се…'}{queueState.failed ? <button className={styles.button} onClick={() => queue.current?.retry()}>Опитай пак</button> : null}</div> : null}
    {notice ? <p className={styles.error} role="alert">{notice}</p> : null}
    {hidden ? <div className={styles.banner}><p>{s.not_held ? 'Заниманието не се проведе — присъствия не се отбелязват.' : 'Заниманието е отменено — присъствия не се отбелязват.'}</p>{s.not_held?.reason ? <p>{s.not_held.reason} · {s.not_held.by} · {when(s.not_held.at, s.not_held.at)}</p> : null}</div> : <>
    {!editing ? <div className={styles.banner}><strong>{sheet.counts.unmarked === 0 ? '✓ Всички деца са отбелязани' : `Неотбелязани ${sheet.counts.unmarked}`}</strong><p>{sheet.counts.present} присъстват · {sheet.counts.absent} отсъстват{s.topic ? ` · Тема: ${s.topic}` : ''}</p></div> : null}
    {editing && sheet.abilities.reason_required ? <label className={styles.field}>Причина (задължителна)<textarea ref={reasonField} value={reason} maxLength={500} onChange={(e) => setReason(e.target.value)} aria-invalid={notice === 'Напишете причина за поправката.'} /><span className={styles.muted}>Поправката след срока се записва с причина.</span></label> : null}
    {editing && sheet.counts.unmarked > 0 ? <><button className={`${styles.good} ${styles.full}`} onClick={() => enqueue({ action: 'all_present' })}>✓ Всички присъстват</button><p className={styles.muted}>Отбелязва неотбелязаните. Предупредените отсъствия и децата в пауза не се променят.</p></> : null}
    <div className={styles.counts}><span className={`${styles.badge} ${styles.present}`}>Присъстват {sheet.counts.present}</span><span className={`${styles.badge} ${styles.warning}`}>Отсъстват {sheet.counts.absent}</span><span className={styles.badge}>Неотбелязани {sheet.counts.unmarked}</span>{sheet.counts.present_paused ? <span className={styles.badge}>+{sheet.counts.present_paused} в пауза</span> : null}</div>
    {editing ? <label className={styles.field}>Тема на заниманието (по избор)<input maxLength={200} value={topic} onChange={(e) => { setTopic(e.target.value); topicRef.current = e.target.value; }} onBlur={saveTopic} /></label> : null}
    {!sheet.abilities.mark && now.getTime() < Date.parse(sheet.abilities.opens_at) ? <p className={styles.muted}>Отбелязването започва в {clock(sheet.abilities.opens_at)}.</p> : null}
    <div className={styles.kids}>{sheet.children.filter((c) => c.expected).map(childRow)}</div>
    {sheet.children.some((c) => !c.expected) ? <><h2>Не се очакват днес</h2><div className={styles.kids}>{sheet.children.filter((c) => !c.expected).map(childRow)}</div></> : null}
    {!editing && sheet.abilities.mark ? <button className={styles.button} onClick={() => setMode('mark')}>Поправи</button> : null}
    <p className={styles.muted}>{editing ? 'Записва се при всяко докосване. ' : ''}{sheet.abilities.reason_required ? 'Поправката след срока се записва с причина.' : deadlineText(sheet.abilities.deadline)}</p>
    <h2>История на промените</h2>{history.length === 0 ? <p className={styles.muted}>Все още няма промени.</p> : null}<ul className={styles.history}>{history.map((line) => <li key={line.id}><strong>{line.text}</strong><p className={styles.muted}>{when(line.at, line.at)}</p></li>)}</ul>
    </>}
    <Link className={styles.button} href={back} onClick={leave}>Готово</Link>
    {toast ? <div className={styles.toast} onMouseEnter={() => setHoverToast(true)} onMouseLeave={() => setHoverToast(false)} onFocus={() => setFocusToast(true)} onBlur={(e) => { if (!e.currentTarget.contains(e.relatedTarget)) setFocusToast(false); }}><span role="status" aria-live="polite">{toast.text}</span>{toast.applied.length > 0 ? <button className={styles.button} onClick={() => { enqueue({ action: 'mark', undo: true, marks: toast.applied.map((a) => ({ enrollment_id: a.enrollment_id, status: a.from, version: a.version })) }); setToast(undefined); }}>Отмени</button> : null}</div> : null}
    {child?.child_id ? <ChildCardDialog organizationId={organizationId} child={{ ...child, child_id: child.child_id }} session={{ id: s.id, group_id: s.group.id, starts_at: s.starts_at }} withDetails={withDetails} now={now} onClose={() => { setChild(undefined); eye.current?.focus(); }} /> : null}
  </div>;
}
function formatPause(value: string) { return new Intl.DateTimeFormat('bg-BG', { day: 'numeric', month: 'long', timeZone: 'Europe/Sofia' }).format(new Date(`${value}T12:00:00Z`)); }
