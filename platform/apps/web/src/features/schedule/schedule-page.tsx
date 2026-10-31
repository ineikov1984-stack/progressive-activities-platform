'use client';

import { useRouter, useSearchParams } from 'next/navigation';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useDashboardContext } from '../dashboard/dashboard-context';
import { ownScheduleOnly } from '../dashboard/navigation';
import { loadSchedule, ScheduleApiError, type ScheduleBoard, type ScheduleSession } from './api';
import {
  addDays, defaultView, type FilterKey, filterChips, filterKeys, filterOptions, hasFilters, noFilters, parseScheduleAddress, parseStoredView, periodLabel,
  type ScheduleAddress, scheduleAddress, scheduleNames, scheduleQuery, type ScheduleView, scheduleViews, scheduleViewStorageKey, sofiaDay,
  toggleFilter, weekOf,
} from './presentation';
import { ScheduleFilterBar } from './schedule-filters';
import { CardsView, ScheduleSkeleton, WeekView } from './schedule-views';
import { type PanelTarget, SchedulePanel } from './session-panel';
import { NextIcon, PreviousIcon } from './icons';
import styles from './schedule.module.css';

const emptyBoard: ScheduleBoard = { sessions: [], activities: [], groups: [], locations: [], instructors: [], abilities: {} };
/** "Моят график" has no filter by teacher (т. 2); the filter by child waits for a search of the enrolled children. */
const ownFilterKeys: FilterKey[] = ['activity', 'group', 'location'];

/** The view the person chose before, in this browser; without one — by the width of the screen. */
function rememberedView(userId: string): ScheduleView {
  if (typeof window === 'undefined') return 'week';
  let stored: string | null = null;
  try {
    stored = window.localStorage.getItem(scheduleViewStorageKey(userId));
  } catch {
    stored = null;
  }
  return parseStoredView(stored) ?? defaultView(window.innerWidth);
}

/**
 * "График" and, for a teacher, "Моят график" (docs/product/schedule.md, т. 2–4): the week as a calendar or as cards,
 * the filters and the panel of a session. The view, the day and the filters are in the address, so "Назад" works and
 * a link can be sent to a colleague; the hours are by Sofia.
 */
export function SchedulePage() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const { user, organization } = useDashboardContext();
  const own = ownScheduleOnly(organization);
  const withDetails = organization.permissions.includes('enrollment.view');
  const search = searchParams.toString();
  const address = useMemo(() => parseScheduleAddress(search, { own }), [search, own]);

  // "Сега" and today follow the clock.
  const [now, setNow] = useState(() => new Date());
  useEffect(() => {
    const timer = window.setInterval(() => setNow(new Date()), 60_000);
    return () => window.clearInterval(timer);
  }, []);
  const today = sofiaDay(now);
  const [remembered, setRemembered] = useState<ScheduleView>(() => rememberedView(user.id));
  const view = address.view ?? remembered;
  const day = address.date ?? today;
  const week = useMemo(() => weekOf(day), [day]);
  const query = scheduleQuery(week, address.filters);

  const [attempt, setAttempt] = useState(0);
  const key = `${organization.id}?${query}#${attempt}`;
  const [result, setResult] = useState<{ key: string; board?: ScheduleBoard; error?: string }>();
  // The names and the filters' choices stay while the next week loads: they are the same week after week (т. 6.1).
  const [dictionary, setDictionary] = useState<ScheduleBoard>();
  useEffect(() => {
    let active = true;
    loadSchedule(organization.id, query).then((board) => {
      if (!active) return;
      setResult({ key, board });
      setDictionary(board);
    }).catch((reason: unknown) => {
      if (!active) return;
      if (reason instanceof ScheduleApiError && reason.status === 401) {
        router.replace('/login');
        return;
      }
      setResult({ key, error: reason instanceof ScheduleApiError ? reason.message : 'Графикът не се зареди. Проверете връзката и опитайте пак.' });
    });
    return () => { active = false; };
  }, [organization.id, query, key, router]);
  const current = result?.key === key ? result : undefined;
  const board = current?.board;
  const names = useMemo(() => scheduleNames(dictionary ?? emptyBoard), [dictionary]);

  const go = useCallback((next: Partial<ScheduleAddress>) => {
    const target: ScheduleAddress = { view, date: day, filters: address.filters, ...next };
    window.history.pushState(null, '', `${window.location.pathname}${scheduleAddress(target)}`);
  }, [view, day, address.filters]);

  function chooseView(next: ScheduleView) {
    setRemembered(next);
    try {
      window.localStorage.setItem(scheduleViewStorageKey(user.id), next);
    } catch {
      // A private window or blocked storage: the choice holds in the address for this visit.
    }
    go({ view: next });
  }

  // One panel at a time; closing it gives the focus back to the block or card that opened it.
  const [panel, setPanel] = useState<PanelTarget>();
  const opener = useRef<HTMLElement | null>(null);
  const openSession = useCallback((session: ScheduleSession, element: HTMLElement) => {
    opener.current = element;
    setPanel({ kind: 'session', session });
  }, []);
  const openMore = useCallback((sessions: ScheduleSession[], element: HTMLElement) => {
    opener.current = element;
    setPanel({ kind: 'more', sessions });
  }, []);
  const closePanel = useCallback(() => {
    setPanel(undefined);
    requestAnimationFrame(() => opener.current?.focus());
  }, []);

  const keys = own ? ownFilterKeys : filterKeys;
  const filtered = hasFilters(address.filters);
  const clearFilters = () => go({ filters: noFilters });

  let body;
  if (current?.error) {
    body = (
      <div className={styles.problem}>
        <p role="alert">{current.error}</p>
        <button type="button" className={styles.button} onClick={() => setAttempt((value) => value + 1)}>Опитай пак</button>
      </div>
    );
  } else if (!board) {
    body = <><span className="sr-only" role="status">Зареждане на графика…</span><ScheduleSkeleton view={view} /></>;
  } else if (board.sessions.length === 0) {
    body = (
      <div className={styles.empty}>
        <p role="status">Няма занимания в тази седмица.</p>
        {filtered ? <button type="button" className={styles.button} onClick={clearFilters}>Изчисти филтрите</button> : null}
      </div>
    );
  } else if (view === 'week') {
    body = <WeekView week={week} sessions={board.sessions} activities={board.activities} names={names} today={today} now={now} onOpen={openSession} onMore={openMore} />;
  } else {
    body = <CardsView week={week} sessions={board.sessions} names={names} today={today} now={now} onOpen={openSession} />;
  }

  return (
    <div className={styles.page}>
      <header className={styles.header}>
        <div className={styles.titleRow}>
          <h1>{own ? 'Моят график' : 'График'}</h1>
          <div className={styles.period}>
            <button type="button" className={styles.button} onClick={() => go({ date: today })}>Днес</button>
            <button type="button" className={styles.iconButton} aria-label="Предишна седмица" onClick={() => go({ date: addDays(week.from, -7) })}><PreviousIcon /></button>
            <button type="button" className={styles.iconButton} aria-label="Следваща седмица" onClick={() => go({ date: addDays(week.from, 7) })}><NextIcon /></button>
            <p className={styles.periodLabel} aria-live="polite">{periodLabel(week.from, week.to)}</p>
          </div>
        </div>
        <div className={styles.views} role="group" aria-label="Изглед">
          {scheduleViews.map((item) => (
            <button key={item.id} type="button" aria-pressed={view === item.id} onClick={() => chooseView(item.id)}>{item.label}</button>
          ))}
        </div>
      </header>

      <ScheduleFilterBar keys={keys} options={filterOptions(dictionary ?? emptyBoard)} filters={address.filters}
        chips={dictionary ? filterChips(address.filters, dictionary) : []}
        onToggle={(filter, id) => go({ filters: toggleFilter(address.filters, filter, id) })} onClear={clearFilters} />

      {body}

      {panel ? <SchedulePanel organizationId={organization.id} target={panel} names={names} now={now} withDetails={withDetails} onClose={closePanel} /> : null}
    </div>
  );
}
