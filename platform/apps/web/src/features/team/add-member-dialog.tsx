'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { type FormEvent, type MouseEvent, type SyntheticEvent, useEffect, useRef, useState } from 'react';
import { addMember, inviteMember, type SentInvitation, TeamApiError, type TeamMember } from './api';
import { focusLater, invitableRoles, roleAccess, roleHints, roleLabels, type TeamRole } from './presentation';
import { SentInvitationNotice } from './sent-invitation';
import styles from './team.module.css';

type Props = { organizationId: string; actorRole: string; onClose: () => void; onAdded: () => void };
type Values = { full_name: string; phone: string; email: string };
type Field = keyof Values;
type Done = { member: TeamMember; sent: SentInvitation | null; inviteError: string };

const emptyValues: Values = { full_name: '', phone: '', email: '' };
const choiceLabels: Partial<Record<TeamRole, string>> = { instructor: 'Преподавател или треньор' };

/** A key for one "Добави" of this panel: a retry or a double tap adds the person once. */
function newRequestKey(): string {
  return typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
    ? crypto.randomUUID()
    : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
}

/**
 * "Добави човек" (docs/product/team.md, т. 10): name and phone are required, the e-mail is optional. A teacher may stay
 * a profile without a login; every other role logs in, so for it the invitation is on and the e-mail is required.
 * A modal side panel: the heading takes the focus, Esc and "Затвори" close it.
 */
export function AddMemberDialog({ organizationId, actorRole, onClose, onAdded }: Props) {
  const router = useRouter();
  const dialog = useRef<HTMLDialogElement>(null);
  const heading = useRef<HTMLHeadingElement>(null);
  const requestKey = useRef<string | null>(null);
  const [values, setValues] = useState<Values>(emptyValues);
  const [role, setRole] = useState<TeamRole>('instructor');
  const [inviteTeacher, setInviteTeacher] = useState(false);
  const [errors, setErrors] = useState<Partial<Record<Field, string>>>({});
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState<Done>();
  const [leaving, setLeaving] = useState(false);
  const invites = role !== 'instructor' || inviteTeacher;
  // Something typed would be lost: a click outside the panel or Esc asks first.
  const dirty = !done && Object.values(values).some((value) => value.trim() !== '');
  const access = roleAccess(role);

  useEffect(() => {
    const element = dialog.current;
    if (element && !element.open) element.showModal();
    heading.current?.focus();
  }, []);

  useEffect(() => {
    if (done) heading.current?.focus();
  }, [done]);

  function askToLeave() {
    setLeaving(true);
    focusLater('add-keep');
  }

  function outside(event: MouseEvent<HTMLDialogElement>) {
    if (event.target !== dialog.current) return;
    if (dirty) askToLeave();
    else dialog.current?.close();
  }

  function escape(event: SyntheticEvent<HTMLDialogElement>) {
    if (!dirty) return;
    event.preventDefault();
    askToLeave();
  }

  function change(field: Field, value: string) {
    setValues((current) => ({ ...current, [field]: value }));
    setErrors((current) => ({ ...current, [field]: undefined }));
  }

  const focusField = (field: Field) => focusLater(`add-${field}`);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const found: Partial<Record<Field, string>> = {};
    if (values.full_name.trim() === '') found.full_name = 'Въведете името.';
    if (values.phone.trim() === '') found.phone = 'Въведете телефон.';
    if (invites && values.email.trim() === '') found.email = 'Въведете имейл, на който да изпратим поканата.';
    const first = (Object.keys(found) as Field[])[0];
    if (first) {
      setErrors(found);
      focusField(first);
      return;
    }

    setBusy(true);
    setError('');
    setErrors({});
    requestKey.current ??= newRequestKey();
    const email = values.email.trim();
    let member: TeamMember;
    try {
      member = await addMember(organizationId, {
        full_name: values.full_name.trim(), phone: values.phone.trim(), email: email === '' ? null : email, teaches: role === 'instructor',
      }, requestKey.current);
    } catch (reason) {
      const fields = reason instanceof TeamApiError ? reason.fieldErrors : {};
      const shown: Partial<Record<Field, string>> = {};
      for (const field of ['full_name', 'phone', 'email'] as Field[]) if (fields[field]?.[0]) shown[field] = fields[field][0];
      setErrors(shown);
      setError(reason instanceof Error && reason.message ? reason.message : 'Човекът не беше добавен. Проверете връзката и опитайте отново.');
      const invalid = (Object.keys(shown) as Field[])[0];
      if (invalid) focusField(invalid);
      setBusy(false);
      return;
    }

    onAdded();
    if (!invites) {
      // A teacher without a login: the profile is where the groups are added.
      router.push(`/dashboard/team/${encodeURIComponent(member.id)}?tab=groups&added=1`);
      return;
    }
    try {
      setDone({ member, sent: await inviteMember(organizationId, member.id, role, email), inviteError: '' });
    } catch (reason) {
      setDone({ member, sent: null, inviteError: reason instanceof Error ? reason.message : 'Поканата не беше изпратена.' });
    } finally {
      setBusy(false);
    }
  }

  function addAnother() {
    requestKey.current = null;
    setValues(emptyValues);
    setRole('instructor');
    setInviteTeacher(false);
    setDone(undefined);
    focusField('full_name');
  }

  const describe = (field: Field) => (errors[field] ? `add-${field}-error` : undefined);

  return (
    <dialog ref={dialog} className={styles.drawer} aria-labelledby="add-member-title" onClose={onClose} onCancel={escape} onClick={outside}>
      <div className={styles.drawerInner}>
        <header className={styles.drawerHead}>
          <div className={styles.heading}>
            <h2 id="add-member-title" ref={heading} tabIndex={-1}>{done ? `${done.member.full_name} е в екипа` : 'Добави човек в екипа'}</h2>
            {done ? null : <p className={styles.muted}>Само името и телефонът са задължителни.</p>}
          </div>
          <button type="button" className={styles.secondary} onClick={() => dialog.current?.close()}>Затвори</button>
        </header>

        <div className={styles.drawerBody}>
          {leaving ? (
            <div className={styles.confirm} role="group" aria-labelledby="add-leave-title">
              <p id="add-leave-title">Да откажа ли? Въведеното ще се загуби.</p>
              <div className={styles.actions}>
                <button id="add-keep" type="button" className={styles.primary} onClick={() => { setLeaving(false); focusField('full_name'); }}>Продължи попълването</button>
                <button type="button" className={styles.secondary} onClick={() => dialog.current?.close()}>Да, откажи</button>
              </div>
            </div>
          ) : null}
          {done ? (
            <>
              {done.sent ? <SentInvitationNotice sent={done.sent} /> : (
                <p className={styles.error} role="alert">Човекът е добавен, но поканата не тръгна: {done.inviteError} Изпратете я пак от профила.</p>
              )}
              <div className={styles.actions}>
                <Link className={styles.primary} href={`/dashboard/team/${encodeURIComponent(done.member.id)}`}>Към профила</Link>
                <button type="button" className={styles.secondary} onClick={addAnother}>Добави още един</button>
              </div>
            </>
          ) : (
            <form className={styles.form} onSubmit={submit} noValidate>
              <div className={styles.fieldGrid}>
                <div className={`${styles.field} ${styles.wide}`}>
                  <label htmlFor="add-full_name">Име и фамилия</label>
                  <input id="add-full_name" className={styles.input} autoComplete="off" value={values.full_name} onChange={(event) => change('full_name', event.target.value)}
                    aria-invalid={errors.full_name ? true : undefined} aria-describedby={describe('full_name')} required />
                  {errors.full_name ? <p id="add-full_name-error" className={styles.fieldError}>{errors.full_name}</p> : null}
                </div>
                <div className={styles.field}>
                  <label htmlFor="add-phone">Телефон</label>
                  <input id="add-phone" className={styles.input} type="tel" inputMode="tel" autoComplete="off" value={values.phone} onChange={(event) => change('phone', event.target.value)}
                    aria-invalid={errors.phone ? true : undefined} aria-describedby={describe('phone')} required />
                  {errors.phone ? <p id="add-phone-error" className={styles.fieldError}>{errors.phone}</p> : null}
                </div>
                <div className={styles.field}>
                  <label htmlFor="add-email">{invites ? 'Имейл' : 'Имейл (по избор)'}</label>
                  <input id="add-email" className={styles.input} type="email" inputMode="email" autoComplete="off" value={values.email} onChange={(event) => change('email', event.target.value)}
                    aria-invalid={errors.email ? true : undefined} aria-describedby={describe('email')} required={invites} />
                  {errors.email ? <p id="add-email-error" className={styles.fieldError}>{errors.email}</p> : null}
                </div>
              </div>

              <fieldset className={styles.choices}>
                <legend>Какво ще прави</legend>
                {invitableRoles(actorRole).map((option) => (
                  <label className={styles.choice} key={option}>
                    <input type="radio" name="add-role" value={option} checked={role === option} onChange={() => setRole(option)} />
                    <span className={styles.choiceText}>{choiceLabels[option] ?? roleLabels[option]}<span>{roleHints[option]}</span></span>
                  </label>
                ))}
              </fieldset>

              <label className={styles.switch}>
                <input type="checkbox" role="switch" checked={invites} disabled={role !== 'instructor'} onChange={(event) => setInviteTeacher(event.target.checked)} aria-describedby="add-invite-hint" />
                <span className={styles.choiceText}>Покани да влиза в системата
                  <span id="add-invite-hint">{role === 'instructor'
                    ? 'Ще получи имейл с връзка. Без вход профилът също работи — тогава екипът отбелязва вместо него.'
                    : 'Тази роля работи само с вход, затова поканата е задължителна. Ще получи имейл с връзка.'}</span>
                </span>
              </label>

              <div className={styles.access} aria-live="polite">
                <p><strong>Ще вижда:</strong> {access.sees}</p>
                <p><strong>Няма да вижда:</strong> {access.hidden}</p>
              </div>

              {error ? <p className={styles.error} role="alert">{error}</p> : null}
              <div className={styles.formActions}>
                <button type="button" className={styles.secondary} onClick={() => dialog.current?.close()}>Отказ</button>
                <button type="submit" className={styles.primary} disabled={busy} aria-busy={busy}>
                  {busy ? 'Добавяне…' : invites ? 'Добави и изпрати поканата' : 'Добави човека'}
                </button>
              </div>
            </form>
          )}
        </div>
      </div>
    </dialog>
  );
}
