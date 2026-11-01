'use client';

import type { ScheduleActivity, ScheduleSession } from './api';
import { calendarAttendance, dayName, fillOf, fillText, legend, type MonthDay, monthLayout, monthLine, type ScheduleNames, sessionsCount } from './presentation';
import { colorClass, Legend, type OpenSession, SessionCard } from './schedule-views';
import { FillBar, type PreviewHandlers } from './session-preview';
import styles from './schedule.module.css';

type Props = {
  /** A day of the month shown; on a narrower screen also the chosen day, whose sessions are listed under the grid. */
  day: string;
  sessions: ScheduleSession[];
  activities: ScheduleActivity[];
  names: ScheduleNames;
  today: string;
  now: Date;
  /** A day of the grid opens "Ден" by places for it (т. 3.2). */
  onDay: (day: string) => void;
  /** On a narrower screen a day is chosen first, and its sessions are listed under the grid. */
  onPick: (day: string) => void;
  onOpen: OpenSession;
  preview: PreviewHandlers;
};

const headings = ['пн', 'вт', 'ср', 'чт', 'пт', 'сб', 'нд'];

function dayWords(item: MonthDay, today: string): string {
  const name = dayName(item.day);
  return `${name.weekday}, ${name.date}${item.day === today ? ', днес' : ''}: ${sessionsCount(item.held, item.cancelled)}${item.notHeld ? `, ${item.notHeld} ${item.notHeld===1?'не се проведе':'не се проведоха'}`:''}.`;
}

/**
 * A line of a day: "16:00 Шахмат · Гр. 1 · 8/10" when it fits; "Пълна" and "Няма записани" always stay; a cancelled
 * one starts with "Отменено ·". A thin bar under it shows how full.
 */
function MonthLine({ session, names, preview, now }: { session: ScheduleSession; names: ScheduleNames; preview: PreviewHandlers; now: Date }) {
  const cancelled = session.status === 'cancelled' || !!session.not_held;
  const fill = fillOf(session);
  const attendance = calendarAttendance(session, now);
  return (
    <span className={`${styles.monthLine} ${colorClass(names.activity(session.activity_id))} ${cancelled ? styles.monthCancelled : ''}`}
      onMouseEnter={(event) => preview.enter({ kind: 'session', session }, event.currentTarget)} onMouseLeave={preview.leave}>
      {cancelled ? <span className={styles.monthCancelWord}>{session.not_held?'Не се проведе':'Отменено'} ·</span> : null}
      <span className={styles.monthText}>{monthLine(session, names)}</span>
      {cancelled ? null : <span className={fill.word ? styles.monthFillWord : styles.monthFill}>{fillText(session, 'block')}</span>}
      {attendance ? <span className={styles.attendanceTick} role="img" aria-label={`Присъствия: ${attendance}`} title={attendance}>{session.attendance?.unmarked === 0 ? '✓' : '?'}</span> : null}
      {cancelled ? null : <FillBar session={session} className={styles.monthBar} />}
    </span>
  );
}

/**
 * Месец (т. 3.2): six weeks from Monday. When there is room (about 860 px), a day shows up to three lines and "+N
 * още", and opens "Ден"; on a narrower screen the grid is compact — the number of sessions held in each day — and the
 * chosen day's cards are listed under it. The other month's days are paler, still readable.
 */
export function MonthView({ day, sessions, activities, names, today, now, onDay, onPick, onOpen, preview }: Props) {
  const layout = monthLayout(sessions, day);
  const chosen = layout.weeks.flat().find((item) => item.day === day);
  const chosenName = dayName(day);
  const cellClass = (item: MonthDay) => [item.inMonth ? '' : styles.monthOut, item.day === today ? styles.monthToday : ''].join(' ');

  return (
    <section className={styles.month} aria-label="Месец">
      <Legend activities={legend(sessions, activities)} />
      <table className={`${styles.monthTable} ${styles.monthWide}`}>
        <thead><tr>{headings.map((heading) => <th key={heading} scope="col">{heading}</th>)}</tr></thead>
        <tbody>
          {layout.weeks.map((week) => (
            <tr key={week[0].day}>
              {week.map((item) => {
                const target = { kind: 'day' as const, day: item.day, sessions: item.sessions };
                return (
                  <td key={item.day} className={cellClass(item)}>
                    <button type="button" className={styles.monthDay} aria-describedby={preview.describedBy(`day-${item.day}`)}
                      onClick={() => { preview.close(); onDay(item.day); }} onFocus={(event) => preview.focus(target, event)} onBlur={preview.blur}>
                      <span className={styles.monthNumber} aria-hidden="true">{item.number}</span>
                      <span className="sr-only">{dayWords(item, today)}</span>
                      {item.shown.map((session) => <MonthLine key={session.id} session={session} names={names} preview={preview} now={now} />)}
                      {item.more ? <span className={styles.monthMore}>+{item.more} още</span> : null}
                      <span className="sr-only"> Виж деня.</span>
                    </button>
                  </td>
                );
              })}
            </tr>
          ))}
        </tbody>
      </table>

      <div className={styles.monthCompact}>
        <table className={styles.monthTable}>
          <thead><tr>{headings.map((heading) => <th key={heading} scope="col">{heading}</th>)}</tr></thead>
          <tbody>
            {layout.weeks.map((week) => (
              <tr key={week[0].day}>
                {week.map((item) => (
                  <td key={item.day} className={cellClass(item)}>
                    <button type="button" className={styles.monthPick} aria-pressed={item.day === day} onClick={() => onPick(item.day)}>
                      <span className={styles.monthNumber} aria-hidden="true">{item.number}</span>
                      {item.held ? <span className={styles.monthCount} aria-hidden="true">{item.held}</span> : null}
                      <span className="sr-only">{dayWords(item, today)}</span>
                    </button>
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
        <section className={styles.cardDay} aria-labelledby="schedule-month-day">
          <h2 id="schedule-month-day" className={`${styles.dayTitle} ${day === today ? styles.dayTitleToday : ''}`}>
            {chosenName.weekday}{day === today ? ' · днес' : ''} <span>{chosenName.date}</span>
          </h2>
          {chosen && chosen.sessions.length ? (
            <>
              <ul className={styles.cardList}>
                {chosen.sessions.map((session) => <li key={session.id}><SessionCard session={session} names={names} now={now} onOpen={onOpen} /></li>)}
              </ul>
              <button type="button" className={styles.button} onClick={() => onDay(day)}>Виж деня по часове</button>
            </>
          ) : <p className={styles.small}>Няма занимания в този ден.</p>}
        </section>
      </div>
    </section>
  );
}
