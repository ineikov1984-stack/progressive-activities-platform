'use client';

import Link from 'next/link';
import { type FormEvent, useState } from 'react';
import { type ActivityGroup, type InstructorProfile, type Location, saveGroup } from '../api';
import { buildSchedulePattern, conflictPresentation, summarizeSchedule } from '../presentation';
import { Field, Icon, icons } from './ui';
import styles from './wizard.module.css';

type Slot = { weekday: number; starts_at: string; duration_minutes: number };
type ExtraDate = { date: string; starts_at: string; duration_minutes: number };

const weekdays = ['Понеделник', 'Вторник', 'Сряда', 'Четвъртък', 'Петък', 'Събота', 'Неделя'];
const durations = [30, 45, 60, 75, 90, 120];
const defaultSlot = (): Slot => ({ weekday: 1, starts_at: '16:00', duration_minutes: 60 });

type Props = {
  organizationId: string;
  activityId: string;
  ageLabel: string;
  groups: ActivityGroup[];
  locations: Location[];
  instructors: InstructorProfile[];
  defaultLocationId: string | null;
  locked: boolean;
  onGroup: (group: ActivityGroup) => void;
};

export function StepGroups({ organizationId, activityId, ageLabel, groups, locations, instructors, defaultLocationId, locked, onGroup }: Props) {
  const [editing, setEditing] = useState<ActivityGroup | 'new' | undefined>(groups.length === 0 ? 'new' : undefined);
  const [slots, setSlots] = useState<Slot[]>([defaultSlot()]);
  const [extraDates, setExtraDates] = useState<ExtraDate[]>([]);
  const [excluded, setExcluded] = useState('');
  const [frequency, setFrequency] = useState<'weekly' | 'biweekly'>('weekly');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  function open(group?: ActivityGroup) {
    setEditing(group ?? 'new');
    setSlots(group?.schedule_pattern.slots?.length ? group.schedule_pattern.slots : [defaultSlot()]);
    setExtraDates(group?.schedule_pattern.added_dates ?? []);
    setExcluded(group?.schedule_pattern.excluded_dates?.join('\n') ?? '');
    setFrequency(group?.schedule_pattern.frequency ?? 'weekly');
    setError(''); setNotice('');
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const existing = editing && editing !== 'new' ? editing : undefined;
    const value = (name: string) => String(form.get(name) ?? '').trim();
    setBusy(true); setError(''); setNotice('');
    try {
      const group = await saveGroup(organizationId, activityId, {
        ...(existing ? { version: existing.version } : {}),
        name: value('name'),
        age_label: ageLabel,
        capacity_min: Number(value('capacity_min') || 1),
        capacity_max: Number(value('capacity_max')),
        location_id: value('location_id') || null,
        instructor_ids: form.getAll('instructor_ids').map(String),
        schedule_pattern: buildSchedulePattern({ startDate: value('start_date'), endDate: value('end_date'), frequency, slots, excludedDates: excluded, addedDates: extraDates.filter((item) => item.date) }),
      }, existing?.id);
      onGroup(group);
      setEditing(undefined);
      setNotice(existing ? `Групата „${group.name}“ е обновена. Създадени са ${group.sessions.length} занятия.` : `Групата „${group.name}“ е създадена с ${group.sessions.length} занятия.`);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Групата не беше запазена.');
    } finally { setBusy(false); }
  }

  const current = editing && editing !== 'new' ? editing : undefined;

  return (
    <>
      {notice ? <p className={styles.inlineSuccess} role="status">{notice}</p> : null}
      {groups.length > 0 ? (
        <ul className={styles.groupList}>
          {groups.map((group) => (
            <li key={group.id} className={styles.groupCard}>
              <div className={styles.groupHead}>
                <strong>{group.name}</strong>
                {!locked ? <button type="button" className={styles.quiet} onClick={() => open(group)}><Icon path={icons.edit} />Промени</button> : null}
              </div>
              <span className={styles.muted}>{summarizeSchedule(group.schedule_pattern)}</span>
              <span className={styles.muted}>{group.sessions.length} занятия · до {group.capacity_max} деца{group.location_id ? ` · ${locations.find((item) => item.id === group.location_id)?.name ?? ''}` : ''}</span>
              {group.conflicts.map((conflict, index) => {
                const view = conflictPresentation(conflict);
                return <span key={index} className={view.tone === 'danger' ? styles.conflictDanger : styles.conflictWarning}><Icon path={icons.warn} /><span><strong>{view.label}.</strong> {view.message}</span></span>;
              })}
            </li>
          ))}
        </ul>
      ) : null}

      {locked ? null : editing ? (
        <form key={current?.id ?? 'new'} className={styles.subform} onSubmit={submit} aria-label={current ? `Промяна на ${current.name}` : 'Нова група'}>
          <strong className={styles.label}>{current ? `Промяна на „${current.name}“` : 'Нова група'}</strong>
          {current ? <p className={styles.hint}>Записът пресъздава бъдещите занятия по новия график.</p> : null}
          <div className={styles.twoFields}>
            <Field id="g-name" label="Име на групата"><input id="g-name" name="name" className={styles.input} required maxLength={120} defaultValue={current?.name ?? (groups.length ? `Група ${groups.length + 1}` : 'Група 1')} /></Field>
            <Field id="g-location" label="Място">
              <select id="g-location" name="location_id" className={styles.input} defaultValue={current?.location_id ?? defaultLocationId ?? ''}>
                <option value="">Ще бъде уточнено</option>
                {locations.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
              </select>
            </Field>
          </div>
          <div className={styles.twoFields}>
            <Field id="g-start" label="От дата"><input id="g-start" name="start_date" type="date" className={styles.input} required defaultValue={current?.schedule_pattern.start_date ?? ''} /></Field>
            <Field id="g-end" label="До дата"><input id="g-end" name="end_date" type="date" className={styles.input} required defaultValue={current?.schedule_pattern.end_date ?? ''} /></Field>
          </div>
          <div className={styles.field}>
            <span className={styles.label} id="g-frequency">Колко често</span>
            <div className={styles.chips} role="group" aria-labelledby="g-frequency">
              <button type="button" className={`${styles.chip} ${frequency === 'weekly' ? styles.chipOn : ''}`} aria-pressed={frequency === 'weekly'} onClick={() => setFrequency('weekly')}>Всяка седмица</button>
              <button type="button" className={`${styles.chip} ${frequency === 'biweekly' ? styles.chipOn : ''}`} aria-pressed={frequency === 'biweekly'} onClick={() => setFrequency('biweekly')}>През седмица</button>
            </div>
          </div>
          <fieldset className={styles.fieldset}>
            <legend className={styles.label}>Дни и часове</legend>
            {slots.map((slot, index) => (
              <div key={index} className={styles.slotRow}>
                <select className={styles.input} aria-label={`Ден ${index + 1}`} value={slot.weekday} onChange={(event) => setSlots(slots.map((item, at) => at === index ? { ...item, weekday: Number(event.target.value) } : item))}>
                  {weekdays.map((label, day) => <option key={label} value={day + 1}>{label}</option>)}
                </select>
                <input className={styles.input} type="time" aria-label={`Начален час ${index + 1}`} required value={slot.starts_at} onChange={(event) => setSlots(slots.map((item, at) => at === index ? { ...item, starts_at: event.target.value } : item))} />
                <select className={styles.input} aria-label={`Продължителност ${index + 1}`} value={slot.duration_minutes} onChange={(event) => setSlots(slots.map((item, at) => at === index ? { ...item, duration_minutes: Number(event.target.value) } : item))}>
                  {[...new Set([...durations, slot.duration_minutes])].sort((a, b) => a - b).map((minutes) => <option key={minutes} value={minutes}>{minutes} мин.</option>)}
                </select>
                {slots.length > 1 ? <button type="button" className={styles.iconButton} aria-label={`Премахни ден ${index + 1}`} onClick={() => setSlots(slots.filter((_, at) => at !== index))}><Icon path={icons.trash} /></button> : null}
              </div>
            ))}
            <button type="button" className={styles.quiet} onClick={() => setSlots([...slots, defaultSlot()])}><Icon path={icons.plus} />Добави ден</button>
          </fieldset>
          <div className={styles.twoFields}>
            <Field id="g-min" label="Минимум деца"><input id="g-min" name="capacity_min" type="number" min={1} className={styles.input} defaultValue={current?.capacity_min ?? 1} /></Field>
            <Field id="g-max" label="Максимум деца"><input id="g-max" name="capacity_max" type="number" min={1} className={styles.input} required defaultValue={current?.capacity_max ?? 12} /></Field>
          </div>
          {instructors.length > 0 ? (
            <fieldset className={styles.fieldset}>
              <legend className={styles.label}>Преподаватели в групата</legend>
              <div className={styles.chips}>
                {instructors.map((item) => <label key={item.id} className={styles.toggle}><input type="checkbox" name="instructor_ids" value={item.id} defaultChecked={current?.instructor_ids.includes(item.id)} />{item.public_name}</label>)}
              </div>
            </fieldset>
          ) : null}
          <details className={styles.details}>
            <summary>Почивни дни и допълнителни занятия</summary>
            <Field id="g-excluded" label="Дати без занятие" hint="По една дата на ред, например 2026-12-24.">
              <textarea id="g-excluded" className={`${styles.input} ${styles.textareaSmall}`} value={excluded} onChange={(event) => setExcluded(event.target.value)} />
            </Field>
            <fieldset className={styles.fieldset}>
              <legend className={styles.label}>Допълнителни занятия</legend>
              {extraDates.map((item, index) => (
                <div key={index} className={styles.slotRow}>
                  <input className={styles.input} type="date" aria-label={`Дата ${index + 1}`} value={item.date} onChange={(event) => setExtraDates(extraDates.map((row, at) => at === index ? { ...row, date: event.target.value } : row))} />
                  <input className={styles.input} type="time" aria-label={`Час ${index + 1}`} value={item.starts_at} onChange={(event) => setExtraDates(extraDates.map((row, at) => at === index ? { ...row, starts_at: event.target.value } : row))} />
                  <select className={styles.input} aria-label={`Продължителност на допълнително занятие ${index + 1}`} value={item.duration_minutes} onChange={(event) => setExtraDates(extraDates.map((row, at) => at === index ? { ...row, duration_minutes: Number(event.target.value) } : row))}>
                    {[...new Set([...durations, item.duration_minutes])].sort((a, b) => a - b).map((minutes) => <option key={minutes} value={minutes}>{minutes} мин.</option>)}
                  </select>
                  <button type="button" className={styles.iconButton} aria-label={`Премахни допълнително занятие ${index + 1}`} onClick={() => setExtraDates(extraDates.filter((_, at) => at !== index))}><Icon path={icons.trash} /></button>
                </div>
              ))}
              <button type="button" className={styles.quiet} onClick={() => setExtraDates([...extraDates, { date: '', starts_at: '16:00', duration_minutes: 60 }])}><Icon path={icons.plus} />Добави занятие извън графика</button>
            </fieldset>
          </details>
          {error ? <p className={styles.fieldError} role="alert">{error}</p> : null}
          <div className={styles.subformActions}>
            <button type="submit" className={styles.primary} disabled={busy}>{busy ? 'Запазване…' : current ? 'Запази и обнови графика' : 'Създай групата'}</button>
            {groups.length > 0 ? <button type="button" className={styles.quiet} onClick={() => setEditing(undefined)}>Откажи</button> : null}
          </div>
        </form>
      ) : (
        <button type="button" className={styles.secondary} onClick={() => open()}><Icon path={icons.plus} />Добави група</button>
      )}
      <p className={styles.hint}>Пробни занимания и еднократни посещения по групите се добавят в <Link href={`/dashboard/activities/${activityId}/visit-offers`}>Предложения за посещение</Link>.</p>
    </>
  );
}
