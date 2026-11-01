'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { loadSchedule, type ScheduleBoard } from '../schedule/api';
import { sofiaDay } from '../schedule/presentation';
import { clock } from '../attendance/presentation';
import styles from './home.module.css';

/** Uses the existing scoped schedule API; the calendar retains its full behavior. */
export function OrganizationToday({ organizationId }: { organizationId: string }) {
  const [result, setResult] = useState<{ board?: ScheduleBoard; error?: string; day: string }>();
  const [attempt, retry] = useState(0);
  useEffect(() => {
    let active = true, generation = 0;
    const read = async () => {
      const current = ++generation, day = sofiaDay(new Date());
      try {
        const board = await loadSchedule(organizationId, new URLSearchParams({ from: day, to: day }).toString());
        if (active && current === generation) setResult({ board, day });
      } catch {
        if (active && current === generation) setResult({ error: 'Днешният график не се зареди.', day });
      }
    };
    void read(); const timer = setInterval(read, 30000);
    window.addEventListener('focus', read);
    return () => { active = false; clearInterval(timer); window.removeEventListener('focus', read); };
  }, [organizationId, attempt]);
  const day = result?.day ?? sofiaDay(new Date());
  const board = result?.board;
  const sessions = board?.sessions.slice().sort((a, b) => a.starts_at.localeCompare(b.starts_at));
  return <section className={styles.section} aria-labelledby="organization-today">
    <div className={styles.sectionHead}><h2 id="organization-today" className={styles.sectionTitle}>Занимания днес</h2><Link className={styles.textLink} href={`/dashboard/schedule?view=day&date=${day}`}>График →</Link></div>
    {!result ? <div className={styles.skeleton} role="status">Зареждане на графика…</div> : result.error ? <div className={styles.empty}><p role="alert">{result.error}</p><button className={styles.control} onClick={() => retry(n => n + 1)}>Опитай пак</button></div> : !sessions?.length ? <div className={styles.empty}><strong>Днес няма занимания</strong><p>Предстоящите дни са в графика.</p></div> : <div className={styles.dailyList}>{sessions.map(s => {
      const group = board!.groups.find(g => g.id === s.group_id);
      const activity = board!.activities.find(a => a.id === s.activity_id);
      const location = board!.locations.find(l => l.id === s.location_id);
      const closed = s.status === 'cancelled' || s.not_held;
      const status = s.status === 'cancelled' ? 'Отменено' : s.not_held ? 'Не се проведе' : s.taken > 0 && s.attendance && s.attendance.unmarked === 0 ? '✓ Отбелязано' : s.moved ? 'Преместено' : s.extra ? 'Допълнително' : null;
      return <Link key={s.id} className={styles.dailyRow} href={`/dashboard/schedule?view=day&date=${day}&group=${encodeURIComponent(s.group_id)}`}>
        <span className={styles.dailyTime}><strong>{clock(s.starts_at)}</strong><small>{clock(s.ends_at)}</small></span>
        <span className={styles.dailyCopy}><strong>{activity?.title ?? 'Занимание'} · {group?.name ?? 'Група'}</strong><span>{[location?.name, s.instructors.map(i => i.name).join(', ')].filter(Boolean).join(' · ')}</span><span>{s.taken}{s.capacity !== null ? ` от ${s.capacity} места` : ' записани деца'}</span>{status ? <small className={closed ? styles.neutralStatus : styles.dailyStatus}>{status}</small> : null}</span><span aria-hidden="true">›</span>
      </Link>;
    })}</div>}
  </section>;
}
