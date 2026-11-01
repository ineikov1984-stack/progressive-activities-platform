'use client';
import Link from 'next/link';
import { useEffect, useState } from 'react';
import type { TeachingGroup } from '../dashboard/api';
import { AttendanceError, loadToday, type Today, type TodaySession } from './api';
import { clock, format, when } from './presentation';
import styles from './attendance.module.css';
export function hasMonth(groups: TeachingGroup[], now = new Date()): boolean {
  const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Sofia', year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
  return groups.some((g) => g.basis === 'offering_lead' || (g.basis === 'assignment' && (!g.starts_on || g.starts_on <= today) && (!g.ends_on || g.ends_on >= today)));
}
export function TodaySection({ organizationId, groups, showMonthLink = true, showEmpty = false }: { organizationId: string; groups: TeachingGroup[]; showMonthLink?: boolean; showEmpty?: boolean }) {
  const [result, setResult] = useState<{ data?: Today; error?: string }>(); const [attempt, retry] = useState(0);
  useEffect(() => { const controller = new AbortController(); let active = true;
    const refresh = () => loadToday(organizationId, controller.signal).then((data) => { if (active) setResult({ data }); }).catch((e: unknown) => { if (active) setResult(e instanceof AttendanceError && e.status === 403 ? { data: { now: '', sessions: [], pending: [] } } : { error: 'Днешните занимания не се заредиха.' }); });
    void refresh(); const timer = setInterval(refresh, 30000); return () => { active = false; controller.abort(); clearInterval(timer); };
  }, [organizationId, attempt]);
  if (!result) return <section aria-label="Зареждане на днешните занимания" aria-busy="true" className={styles.skeleton} />;
  if (result.error) return <section><p role="alert">{result.error}</p><button className={styles.button} onClick={() => retry((n) => n + 1)}>Опитай пак</button></section>;
  const data = result.data!; if (!data.sessions.length && !data.pending.length) return showEmpty ? <section><h2>Днес</h2><div className={styles.card}><strong>Днес няма ваши занимания</strong><p className={styles.muted}>Предстоящите занимания са в графика.</p><Link className={styles.link} href="/dashboard/schedule">Моят график за седмицата →</Link></div></section> : null;
  return <section aria-labelledby="attendance-today"><h2 id="attendance-today">Днес</h2><p className={styles.muted}>{format(data.now, { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' })}</p>
    {data.pending.map((s) => <article className={styles.pending} key={s.id}><strong>Чака вас · Неотбелязано от вчера</strong><h3>{s.activity} · {s.group}{s.age_label ? ` · ${s.age_label}` : ''}</h3><p>{when(s.starts_at, s.ends_at)}</p><Link className={styles.button} href={`/dashboard/attendance/${s.id}?from=today`}>Отбележи присъствията</Link><p className={styles.muted}>Можете да отбелязвате и поправяте до края на следващия ден. След това — само собственикът или администраторът, с причина.</p></article>)}
    <h3>Моите занимания днес</h3><div className={styles.cards}>{data.sessions.map((s) => <TodayCard key={s.id} session={s} now={Date.parse(data.now)} />)}</div>
    <Link className={styles.link} href="/dashboard/schedule">Моят график за седмицата →</Link>{showMonthLink && hasMonth(groups) ? <Link className={styles.link} href="/dashboard/attendance?view=month">Присъствия за месеца →</Link> : null}
  </section>;
}
function TodayCard({ session: s, now }: { session: TodaySession; now: number }) {
  const closed = s.state === 'cancelled' || s.state === 'not_held'; const complete = s.counts.expected > 0 && s.counts.unmarked === 0;
  const role = s.my_role === 'assistant' ? `Вие сте помощник${s.leads.length ? ` · водещ е ${s.leads.join(', ')}` : ''}` : s.my_role === 'substitute' ? `Замествате ${s.replaces.join(', ')}` : 'Вие сте водещ';
  return <article className={styles.card}><div className={styles.rowHead}><strong>{clock(s.starts_at)}–{clock(s.ends_at)}</strong><span className={`${styles.badge} ${complete ? styles.present : ''}`}>{s.state === 'cancelled' ? 'Отменено' : s.state === 'not_held' ? 'Не се проведе' : complete ? '✓ Отбелязано' : s.state === 'now' ? 'Сега' : s.state === 'later' ? 'Предстои' : 'Приключи'}</span></div>
    <h3>{s.activity} · {s.group}{s.age_label ? ` · ${s.age_label}` : ''}</h3><p className={styles.muted}>{s.location ? `${s.location} · ` : ''}{role}</p>
    {!closed ? <><p className={styles.muted}>{!s.counts.expected ? 'Няма записани деца' : complete ? `${s.counts.present} от ${s.counts.expected} присъстваха${s.last_marked_at ? ` · отбелязано в ${clock(s.last_marked_at)}` : ''}` : `${s.counts.expected} деца · ${s.counts.trial} пробно · ${s.counts.warned} предупредени отсъствия`}</p>
    {s.can_mark && s.counts.unmarked > 0 ? <Link className={styles.primary} href={`/dashboard/attendance/${s.id}?from=today`}>Отбележи присъствията</Link> : Date.parse(s.opens_at) > now ? <p className={styles.muted}>Започва в {clock(s.starts_at)} · отбелязването — от {clock(s.opens_at)}</p> : <Link className={styles.link} href={`/dashboard/attendance/${s.id}?from=today`}>Виж или поправи →</Link>}</> : null}</article>;
}
