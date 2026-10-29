'use client';

import { type FormEvent, useState } from 'react';
import { createInstructor, createLocation, type InstructorProfile, type Location } from '../api';
import type { OfferingDraft } from './offering';
import { Field, Icon, icons } from './ui';
import styles from './wizard.module.css';

type Props = {
  organizationId: string;
  offering: OfferingDraft;
  locations: Location[];
  instructors: InstructorProfile[];
  instructorIntro: string;
  locked: boolean;
  onOffering: (changes: Partial<OfferingDraft>) => void;
  onIntro: (value: string) => void;
  onLocation: (location: Location) => void;
  onInstructor: (instructor: InstructorProfile) => void;
};

const locationTypeLabels: Record<Location['type'], string> = { physical: 'На място', online: 'Онлайн', tbc: 'Ще бъде уточнено' };

function text(form: FormData, name: string): string { return String(form.get(name) ?? '').trim(); }

export function StepPeople({ organizationId, offering, locations, instructors, instructorIntro, locked, onOffering, onIntro, onLocation, onInstructor }: Props) {
  const [addingLocation, setAddingLocation] = useState(false);
  const [addingInstructor, setAddingInstructor] = useState(false);
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');

  async function addLocation(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setBusy('location'); setError('');
    try {
      const location = await createLocation(organizationId, {
        name: text(form, 'name'),
        type: text(form, 'type') as Location['type'],
        space_usage: text(form, 'space_usage') as Location['space_usage'],
        address: text(form, 'address') || null,
        map_url: text(form, 'map_url') || null,
        public_directions: text(form, 'public_directions') || null,
        is_public: true,
      });
      onLocation(location);
      onOffering({ location_id: location.id });
      setAddingLocation(false);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Мястото не беше добавено.');
    } finally { setBusy(''); }
  }

  async function addInstructor(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setBusy('instructor'); setError('');
    try {
      const instructor = await createInstructor(organizationId, {
        public_name: text(form, 'public_name'),
        role_title: text(form, 'role_title') || null,
        bio: text(form, 'bio') || null,
        is_public: true,
      });
      onInstructor(instructor);
      onOffering({ lead_instructor_profile_id: instructor.id });
      setAddingInstructor(false);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Преподавателят не беше добавен.');
    } finally { setBusy(''); }
  }

  return (
    <>
      {error ? <p className={styles.inlineError} role="alert">{error}</p> : null}

      <fieldset className={styles.fieldset} disabled={locked}>
        <legend className={styles.label}>Къде се провежда</legend>
        <div className={styles.choiceGrid}>
          {locations.map((location) => (
            <label key={location.id} className={`${styles.choice} ${offering.location_id === location.id ? styles.choiceOn : ''}`}>
              <input type="radio" name="location" className="sr-only" checked={offering.location_id === location.id} onChange={() => onOffering({ location_id: location.id })} />
              <Icon path={icons.pin} />
              <span className={styles.choiceText}><strong>{location.name}</strong><span>{location.address || locationTypeLabels[location.type]}</span></span>
            </label>
          ))}
          <label className={`${styles.choice} ${offering.location_id === null ? styles.choiceOn : ''}`}>
            <input type="radio" name="location" className="sr-only" checked={offering.location_id === null} onChange={() => onOffering({ location_id: null })} />
            <span className={styles.choiceText}><strong>Ще бъде уточнено</strong><span>Родителите ще видят „Мястото ще бъде уточнено“</span></span>
          </label>
        </div>
      </fieldset>
      {!locked ? (addingLocation ? (
        <form className={styles.subform} onSubmit={addLocation} aria-label="Ново място">
          <strong className={styles.label}>Ново място</strong>
          <Field id="loc-name" label="Име"><input id="loc-name" name="name" className={styles.input} required maxLength={120} placeholder="Например: Зала по изкуства, ОУ „Христо Ботев“" /></Field>
          <div className={styles.twoFields}>
            <Field id="loc-type" label="Вид"><select id="loc-type" name="type" className={styles.input} defaultValue="physical"><option value="physical">На място</option><option value="online">Онлайн</option><option value="tbc">Ще бъде уточнено</option></select></Field>
            <Field id="loc-usage" label="Ползване"><select id="loc-usage" name="space_usage" className={styles.input} defaultValue="exclusive"><option value="exclusive">Само за нас</option><option value="shared">Споделено с други</option></select></Field>
          </div>
          <Field id="loc-address" label="Адрес" hint="Родителите виждат адреса и разстоянието от дома или училището."><input id="loc-address" name="address" className={styles.input} placeholder="бул. „България“ 100, София" /></Field>
          <Field id="loc-map" label="Връзка към карта (по избор)"><input id="loc-map" name="map_url" type="url" className={styles.input} placeholder="https://maps.google.com/…" /></Field>
          <Field id="loc-dir" label="Как се стига (по избор)"><textarea id="loc-dir" name="public_directions" className={`${styles.input} ${styles.textareaSmall}`} placeholder="Входът е откъм двора, етаж 2." /></Field>
          <div className={styles.subformActions}>
            <button type="submit" className={styles.primary} disabled={busy === 'location'}>{busy === 'location' ? 'Добавяне…' : 'Добави мястото'}</button>
            <button type="button" className={styles.quiet} onClick={() => setAddingLocation(false)}>Откажи</button>
          </div>
        </form>
      ) : <button type="button" className={styles.quiet} onClick={() => setAddingLocation(true)}><Icon path={icons.plus} />Добави ново място</button>) : null}

      <fieldset className={styles.fieldset} disabled={locked}>
        <legend className={styles.label}>Водещ преподавател</legend>
        <div className={styles.choiceGrid}>
          {instructors.map((instructor) => (
            <label key={instructor.id} className={`${styles.choice} ${offering.lead_instructor_profile_id === instructor.id ? styles.choiceOn : ''}`}>
              <input type="radio" name="instructor" className="sr-only" checked={offering.lead_instructor_profile_id === instructor.id} onChange={() => onOffering({ lead_instructor_profile_id: instructor.id })} />
              <Icon path={icons.person} />
              <span className={styles.choiceText}><strong>{instructor.public_name}</strong><span>{instructor.role_title || 'Преподавател'}</span></span>
            </label>
          ))}
          <label className={`${styles.choice} ${offering.lead_instructor_profile_id === null ? styles.choiceOn : ''}`}>
            <input type="radio" name="instructor" className="sr-only" checked={offering.lead_instructor_profile_id === null} onChange={() => onOffering({ lead_instructor_profile_id: null })} />
            <span className={styles.choiceText}><strong>Ще изберем по-късно</strong><span>Може да се добави преди публикуване</span></span>
          </label>
        </div>
      </fieldset>
      {!locked ? (addingInstructor ? (
        <form className={styles.subform} onSubmit={addInstructor} aria-label="Нов преподавател">
          <strong className={styles.label}>Нов преподавател</strong>
          <div className={styles.twoFields}>
            <Field id="ins-name" label="Име, както да го виждат родителите"><input id="ins-name" name="public_name" className={styles.input} required maxLength={120} /></Field>
            <Field id="ins-role" label="Роля (по избор)"><input id="ins-role" name="role_title" className={styles.input} placeholder="Например: треньор по шах" /></Field>
          </div>
          <Field id="ins-bio" label="Кратко представяне (по избор)"><textarea id="ins-bio" name="bio" className={`${styles.input} ${styles.textareaSmall}`} placeholder="Опит, квалификации и подход към децата." /></Field>
          <div className={styles.subformActions}>
            <button type="submit" className={styles.primary} disabled={busy === 'instructor'}>{busy === 'instructor' ? 'Добавяне…' : 'Добави преподавателя'}</button>
            <button type="button" className={styles.quiet} onClick={() => setAddingInstructor(false)}>Откажи</button>
          </div>
        </form>
      ) : <button type="button" className={styles.quiet} onClick={() => setAddingInstructor(true)}><Icon path={icons.plus} />Добави нов преподавател</button>) : null}

      <fieldset className={styles.fieldsetPlain} disabled={locked}>
        <Field id="w-instructor-intro" label="Няколко думи за преподавателя в тази дейност (по избор)" hint="Например: „Спокоен и подкрепящ подход към всяко дете“.">
          <textarea id="w-instructor-intro" className={`${styles.input} ${styles.textareaSmall}`} value={instructorIntro} onChange={(event) => onIntro(event.target.value)} />
        </Field>
      </fieldset>
    </>
  );
}
