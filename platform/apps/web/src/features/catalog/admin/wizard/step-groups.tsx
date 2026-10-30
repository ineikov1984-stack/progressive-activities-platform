'use client';

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

/** One of the activity's ways of enrolling (step 6), named with its price. */
export type GroupMode = { value: string; label: string; price: string };

type Props = {
  organizationId: string;
  activityId: string;
  /** The activity's ways of enrolling; a group takes some or all of them. */
  modes: GroupMode[];
  ageLabel: string;
  groups: ActivityGroup[];
  locations: Location[];
  instructors: InstructorProfile[];
  defaultLocationId: string | null;
  locked: boolean;
  onGroup: (group: ActivityGroup) => void;
};

const visitModes = ['trial', 'single'];

/** Whether the group takes trials or single visits (docs/product/visits.md). */
function takesVisits(modes: GroupMode[], takes: string[] | null): boolean {
  return modes.some((mode) => visitModes.includes(mode.value) && (takes === null || takes.includes(mode.value)));
}

function modesText(modes: GroupMode[], takes: string[] | null): string {
  if (takes === null) return 'всички начини';
  return modes.filter((mode) => takes.includes(mode.value)).map((mode) => mode.label.toLocaleLowerCase('bg')).join(', ') || 'няма избран начин';
}

export function StepGroups({ organizationId, activityId, modes, ageLabel, groups, locations, instructors, defaultLocationId, locked, onGroup }: Props) {
  const [editing, setEditing] = useState<ActivityGroup | 'new' | undefined>(groups.length === 0 ? 'new' : undefined);
  const [slots, setSlots] = useState<Slot[]>([defaultSlot()]);
  const [extraDates, setExtraDates] = useState<ExtraDate[]>([]);
  // Days without a session, each picked from a calendar (stored as YYYY-MM-DD).
  const [excluded, setExcluded] = useState<string[]>([]);
  const [frequency, setFrequency] = useState<'weekly' | 'biweekly'>('weekly');
  const [approval, setApproval] = useState<'manual' | 'automatic'>('manual');
  // null — every way of enrolling the activity has, including ones added later.
  const [takes, setTakes] = useState<string[] | null>(null);
  // Children on a trial or single visit in one session: every free place, or up to a number.
  const [visitLimit, setVisitLimit] = useState(false);
  const [visitPlaces, setVisitPlaces] = useState('2');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  function open(group?: ActivityGroup) {
    setEditing(group ?? 'new');
    setSlots(group?.schedule_pattern.slots?.length ? group.schedule_pattern.slots : [defaultSlot()]);
    setExtraDates(group?.schedule_pattern.added_dates ?? []);
    setExcluded(group?.schedule_pattern.excluded_dates ?? []);
    setFrequency(group?.schedule_pattern.frequency ?? 'weekly');
    setApproval(group?.enrollment_approval_mode ?? 'manual');
    setTakes(group?.enrollment_modes ?? null);
    setVisitLimit(group?.visit_places != null);
    setVisitPlaces(String(group?.visit_places ?? 2));
    setError(''); setNotice('');
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const existing = editing && editing !== 'new' ? editing : undefined;
    const value = (name: string) => String(form.get(name) ?? '').trim();
    const chosen = takes === null ? null : modes.map((mode) => mode.value).filter((mode) => takes.includes(mode));
    if (chosen !== null && chosen.length === 0) { setError('Изберете поне един начин на записване за групата.'); return; }
    const visits = takesVisits(modes, takes);
    const places = Number(visitPlaces);
    const capacity = Number(value('capacity_max'));
    if (visits && visitLimit && (!Number.isInteger(places) || places < 1 || places > 500)) { setError('Въведете колко деца на посещение може да има в едно занятие — например 2.'); return; }
    if (visits && visitLimit && capacity > 0 && places > capacity) { setError('Местата за посещения не може да са повече от максимума деца в групата.'); return; }
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
        enrollment_approval_mode: approval,
        // All ways chosen means "all", so a way added later in step 6 applies to the group too.
        enrollment_modes: chosen === null || chosen.length === modes.length ? null : chosen,
        // Kept as it is when the group takes no visits.
        ...(visits ? { visit_places: visitLimit ? places : null } : {}),
        schedule_pattern: buildSchedulePattern({ startDate: value('start_date'), endDate: value('end_date'), frequency, slots, excludedDates: excluded.filter(Boolean).join('\n'), addedDates: extraDates.filter((item) => item.date) }),
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
              <span className={styles.muted}>{group.enrollment_approval_mode === 'automatic' ? 'Заявките се одобряват автоматично, ако има място' : 'Заявките се одобряват от екипа'}</span>
              {modes.length > 1 ? <span className={styles.muted}>Записване: {modesText(modes, group.enrollment_modes ?? null)}</span> : null}
              {takesVisits(modes, group.enrollment_modes ?? null) ? <span className={styles.muted}>{group.visit_places != null ? `Посещения: до ${group.visit_places} ${group.visit_places === 1 ? 'дете' : 'деца'} в едно занятие` : 'Посещения: при всяко свободно място'}</span> : null}
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
          {modes.length > 1 ? (
            <fieldset className={styles.fieldset}>
              <legend className={styles.label}>Начини на записване в тази група</legend>
              <p className={styles.hint}>Например начинаещите — с месечен абонамент и еднократно посещение, а напредналите — само за определен период, за да не пропускат материал.</p>
              <div className={styles.choiceGrid}>
                {modes.map((mode) => {
                  const on = takes === null || takes.includes(mode.value);
                  return (
                    <label key={mode.value} className={`${styles.choice} ${on ? styles.choiceOn : ''}`}>
                      <input
                        type="checkbox"
                        checked={on}
                        onChange={(event) => {
                          const current = takes ?? modes.map((item) => item.value);
                          setTakes(event.target.checked ? [...current, mode.value] : current.filter((item) => item !== mode.value));
                        }}
                      />
                      <span className={styles.choiceText}><strong>{mode.label}</strong><span>{mode.price}</span></span>
                    </label>
                  );
                })}
              </div>
            </fieldset>
          ) : null}
          {takesVisits(modes, takes) ? (
            <fieldset className={styles.fieldset}>
              <legend className={styles.label}>Места за пробни и еднократни посещения в едно занятие</legend>
              <div className={styles.choiceGrid}>
                <label className={`${styles.choice} ${!visitLimit ? styles.choiceOn : ''}`}>
                  <input type="radio" name="visit_places_mode" checked={!visitLimit} onChange={() => setVisitLimit(false)} />
                  <span className={styles.choiceText}><strong>Всички свободни места</strong><span>Ако в занятието има свободно място, родителят може да запише дете за посещение.</span></span>
                </label>
                <label className={`${styles.choice} ${visitLimit ? styles.choiceOn : ''}`}>
                  <input type="radio" name="visit_places_mode" checked={visitLimit} onChange={() => setVisitLimit(true)} />
                  <span className={styles.choiceText}><strong>Ограничен брой</strong><span>Така посетителите не изпълват групата.</span></span>
                </label>
              </div>
              {visitLimit ? (
                <Field id="g-visit-places" label="Най-много деца на посещение в едно занятие">
                  <input id="g-visit-places" type="number" min={1} max={500} inputMode="numeric" className={`${styles.input} ${styles.narrowInput}`} required value={visitPlaces} onChange={(event) => setVisitPlaces(event.target.value)} />
                </Field>
              ) : null}
            </fieldset>
          ) : null}
          <fieldset className={styles.fieldset}>
            <legend className={styles.label}>Как се одобряват заявките</legend>
            <div className={styles.choiceGrid}>
              <label className={`${styles.choice} ${approval === 'manual' ? styles.choiceOn : ''}`}>
                <input type="radio" name="approval" value="manual" checked={approval === 'manual'} onChange={() => setApproval('manual')} />
                <span className={styles.choiceText}><strong>Ръчно от екипа</strong><span>Всяка заявка чака вашето решение.</span></span>
              </label>
              <label className={`${styles.choice} ${approval === 'automatic' ? styles.choiceOn : ''}`}>
                <input type="radio" name="approval" value="automatic" checked={approval === 'automatic'} onChange={() => setApproval('automatic')} />
                <span className={styles.choiceText}><strong>Автоматично, ако има място</strong><span>Детето се записва веднага. При пълна група заявката отива в чакащите. Удобно за записване за целия срок.</span></span>
              </label>
            </div>
            <p className={styles.hint}>Може да се промени и по-късно, също и след публикуване — в „Записвания“ → „Групи“.</p>
          </fieldset>
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
            <fieldset className={styles.fieldset}>
              <legend className={styles.label}>Дати без занятие</legend>
              <p className={styles.hint}>Например празници и ваканции — в тези дни занятие няма.</p>
              {excluded.map((date, index) => (
                <div key={index} className={styles.slotRow}>
                  <input className={styles.input} type="date" aria-label={`Дата без занятие ${index + 1}`} value={date} onChange={(event) => setExcluded(excluded.map((item, at) => at === index ? event.target.value : item))} />
                  <button type="button" className={styles.iconButton} aria-label={`Премахни дата без занятие ${index + 1}`} onClick={() => setExcluded(excluded.filter((_, at) => at !== index))}><Icon path={icons.trash} /></button>
                </div>
              ))}
              <button type="button" className={styles.quiet} onClick={() => setExcluded([...excluded, ''])}><Icon path={icons.plus} />Добави дата без занятие</button>
            </fieldset>
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
      <p className={styles.hint}>Пробните и еднократните посещения са с цените от стъпка 6 — родителят избира дата от графика на групата. Ден на отворените врати и посещения само на определени дати — в стъпка 8 „Специални посещения“.</p>
    </>
  );
}
