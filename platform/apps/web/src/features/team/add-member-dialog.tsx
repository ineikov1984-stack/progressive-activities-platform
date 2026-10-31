'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { type FormEvent, type MouseEvent, type SyntheticEvent, useEffect, useRef, useState } from 'react';
import { loadStaffGroups } from '@/features/enrollment/api';
import { addMember, type SentInvitation, TeamApiError, type TeamMember } from './api';
import { focusLater, invitableRoles, roleHints, roleLabels, type TeamRole } from './presentation';
import {
  accessSummary, allowedScopes, applyPartner, cardAreas, emptyDraft, grantableFor, type PickerGroup, payloadFromDraft, rightRows, type RightsDraft,
  type RightsErrors, rightsErrors, roleAs, sameDraft, selectionSummary, withEnd, withRight, withScope,
} from './rights';
import { GroupPicker, RightAreas, ScopeField } from './rights-editor';
import { AccessLists } from './role-access';
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
 * "Добави в екипа" (docs/product/team.md, т. 10; docs/product/team-rights.md, т. 10): name and phone are required, the
 * e-mail is optional. A teacher may stay a profile without a login; every other role logs in, so for it the invitation
 * is on and the e-mail is required. Under the role — what the person will be able to do, and the additional rights,
 * given only together with the invitation; the person, the invitation and the rights are one request.
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
  const [draft, setDraft] = useState<RightsDraft>(() => emptyDraft('instructor'));
  const [extrasOpen, setExtrasOpen] = useState(false);
  const [extrasErrors, setExtrasErrors] = useState<RightsErrors>({ rights: {} });
  const [groups, setGroups] = useState<PickerGroup[]>();
  const [errors, setErrors] = useState<Partial<Record<Field, string>>>({});
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState<Done>();
  const [leaving, setLeaving] = useState(false);
  const invites = role !== 'instructor' || inviteTeacher;
  const extras = invites && !sameDraft(draft, emptyDraft(role));
  // Something typed or chosen would be lost: a click outside the panel or Esc asks first.
  const dirty = !done && (Object.values(values).some((value) => value.trim() !== '') || extras);
  const grantable = grantableFor(actorRole, role);
  const rowsInput = { role, draft, grantable, teaches: role === 'instructor', actorIsOwner: actorRole === 'organization_owner' };
  const selection = selectionSummary(draft.targets, groups, {});

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

  // Another role starts from its own rights: what was added for the previous one does not carry over.
  function pickRole(next: TeamRole) {
    setRole(next);
    setDraft(emptyDraft(next));
    setExtrasErrors({ rights: {} });
  }

  function changeDraft(next: RightsDraft) {
    setDraft(next);
    setExtrasErrors({ rights: {} });
    if (next.scope === 'groups' && !groups) {
      loadStaffGroups(organizationId).then(setGroups).catch(() => setGroups([]));
    }
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const found: Partial<Record<Field, string>> = {};
    if (values.full_name.trim() === '') found.full_name = 'Въведете име и фамилия.';
    if (values.phone.trim() === '') found.phone = 'Въведете телефон.';
    if (invites && values.email.trim() === '') found.email = 'Въведете имейл, на който да изпратим поканата.';
    const first = (Object.keys(found) as Field[])[0];
    if (first) {
      setErrors(found);
      focusField(first);
      return;
    }
    if (extras && draft.scope === 'groups' && draft.targets.length === 0) {
      setExtrasErrors({ rights: {}, groups: 'Изберете поне една група.' });
      setExtrasOpen(true);
      focusLater('add-groups');
      return;
    }

    setBusy(true);
    setError('');
    setErrors({});
    requestKey.current ??= newRequestKey();
    const email = values.email.trim();
    const access = extras ? payloadFromDraft(role, draft) : undefined;
    let added: { member: TeamMember; sent: SentInvitation | null };
    try {
      added = await addMember(organizationId, {
        full_name: values.full_name.trim(), phone: values.phone.trim(), email: email === '' ? null : email, teaches: role === 'instructor',
        ...(invites ? { invitation: { role, email } } : {}),
        ...(access ? { access } : {}),
      }, requestKey.current);
    } catch (reason) {
      // Nobody is added when any part is refused: the person, the invitation or the rights.
      const fields = reason instanceof TeamApiError ? reason.fieldErrors : {};
      const shown: Partial<Record<Field, string>> = {};
      for (const field of ['full_name', 'phone', 'email'] as Field[]) if (fields[field]?.[0]) shown[field] = fields[field][0];
      const rest = Object.fromEntries(Object.entries(fields).filter(([field]) => !['full_name', 'phone', 'email'].includes(field)));
      const mapped = rightsErrors(rest, access ?? { grants: [] });
      setErrors(shown);
      setExtrasErrors(mapped);
      if (Object.keys(mapped.rights).length > 0 || mapped.groups || mapped.scope || mapped.template) setExtrasOpen(true);
      setError(mapped.general ?? (reason instanceof Error && reason.message ? reason.message : 'Записът не беше добавен. Проверете интернет връзката и опитайте отново.'));
      const invalid = (Object.keys(shown) as Field[])[0];
      if (invalid) focusField(invalid);
      setBusy(false);
      return;
    }

    onAdded();
    setBusy(false);
    if (!invites) {
      // A teacher without a login: the profile is where the groups are added.
      router.push(`/dashboard/team/${encodeURIComponent(added.member.id)}?tab=groups&added=1`);
      return;
    }
    setDone({ member: added.member, sent: added.sent, inviteError: added.sent ? '' : 'Поканата не беше изпратена.' });
  }

  function addAnother() {
    requestKey.current = null;
    setValues(emptyValues);
    setRole('instructor');
    setInviteTeacher(false);
    setDraft(emptyDraft('instructor'));
    setExtrasOpen(false);
    setExtrasErrors({ rights: {} });
    setDone(undefined);
    focusField('full_name');
  }

  const describe = (field: Field) => (errors[field] ? `add-${field}-error` : undefined);

  return (
    <dialog ref={dialog} className={styles.drawer} aria-labelledby="add-member-title" onClose={onClose} onCancel={escape} onClick={outside}>
      <div className={styles.drawerInner}>
        <header className={styles.drawerHead}>
          <div className={styles.heading}>
            <h2 id="add-member-title" ref={heading} tabIndex={-1}>{done ? `${done.member.full_name} е в екипа` : 'Добави в екипа'}</h2>
            {done ? null : <p className={styles.muted}>Задължителни са името и телефонът, а при покана — и имейлът.</p>}
          </div>
          <button type="button" className={styles.secondary} onClick={() => dialog.current?.close()}>Затвори</button>
        </header>

        <div className={styles.drawerBody}>
          {leaving ? (
            <div className={styles.confirm} role="group" aria-labelledby="add-leave-title">
              <p id="add-leave-title">Да се откажете ли? Въведеното ще се изгуби.</p>
              <div className={styles.actions}>
                <button id="add-keep" type="button" className={styles.primary} onClick={() => { setLeaving(false); focusField('full_name'); }}>Продължи попълването</button>
                <button type="button" className={styles.secondary} onClick={() => dialog.current?.close()}>Да, затвори</button>
              </div>
            </div>
          ) : null}
          {done ? (
            <>
              {done.sent ? <SentInvitationNotice sent={done.sent} /> : (
                <p className={styles.error} role="alert">{done.member.full_name} е в екипа, но поканата не беше изпратена: {done.inviteError} Изпратете я отново от профила.</p>
              )}
              <div className={styles.actions}>
                <Link className={styles.primary} href={`/dashboard/team/${encodeURIComponent(done.member.id)}`}>Към профила</Link>
                <button type="button" className={styles.secondary} onClick={addAnother}>Добави още някого</button>
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
                    <input type="radio" name="add-role" value={option} checked={role === option} onChange={() => pickRole(option)} />
                    <span className={styles.choiceText}>{choiceLabels[option] ?? roleLabels[option]}<span>{roleHints[option]}</span></span>
                  </label>
                ))}
              </fieldset>

              {/* What the person will be able to do: it changes with the role, the scope and the additions. */}
              <section className={styles.roleCard} aria-labelledby="add-as" aria-live="polite">
                {invites ? (
                  <>
                    <p id="add-as" className={styles.roleAs}>{roleAs(role)}</p>
                    <AccessLists summary={accessSummary({ role, scope: draft.scope, grants: draft.grants, teaches: role === 'instructor', selected: draft.targets.length })} when="later" />
                    {role === 'instructor' ? <p className={styles.small}>Своите групи — където е водещ, помощник или заместник; добавяте ги в профила.</p> : null}
                  </>
                ) : (
                  <>
                    <p id="add-as" className={styles.roleAs}>Няма достъп</p>
                    <p className={styles.small}>Без покана няма да влиза в системата. Своите групи — където е водещ или помощник — добавяте в профила.</p>
                  </>
                )}
              </section>

              {invites ? (
                <div className={styles.extras}>
                  <details className={styles.more} open={extrasOpen} onToggle={(event) => setExtrasOpen(event.currentTarget.open)}>
                    <summary aria-describedby="add-extras-hint">Допълнителни права (по избор)</summary>
                    <div className={styles.moreBody}>
                      {extrasOpen ? <p id="add-extras-hint" className={styles.small}>Например да вижда телефона на родителя, ако трябва спешно да се обади.</p> : null}
                      <ScopeField idPrefix="add" allowed={allowedScopes(role)} value={draft.scope} error={extrasErrors.scope}
                        onChange={(scope) => changeDraft(withScope(role, draft, scope))} />
                      {draft.scope === 'groups' ? (
                        <div id="add-groups" tabIndex={-1} className={styles.selection} role="group" aria-label="Избрани групи">
                          {selection.count > 0 ? <p className={styles.small}>Избрани: {selection.text}</p> : null}
                          {extrasErrors.groups ? <p className={styles.fieldError}>{extrasErrors.groups}</p> : null}
                          {groups ? <GroupPicker idPrefix="add" groups={groups} selected={draft.targets} onChange={(targets) => changeDraft({ ...draft, targets })} />
                            : <span className={styles.skeleton} aria-label="Зареждане на групите" />}
                        </div>
                      ) : null}
                      {role === 'viewer' && grantable.includes('enrollment.enroll') && grantable.includes('attendance.mark') ? (
                        <div className={styles.actions}>
                          <button type="button" className={styles.secondary} onClick={() => changeDraft(applyPartner(draft))}>Попълни като „Партньор от училище“</button>
                        </div>
                      ) : null}
                      <RightAreas idPrefix="add" areas={cardAreas.map((area) => ({ area, rows: rightRows(area, rowsInput) }))} errors={extrasErrors.rights} startOpen={false}
                        onToggle={(key, on) => changeDraft(withRight(role, draft, key, on))} onEnd={(key, lastDay) => changeDraft(withEnd(draft, key, lastDay))} />
                      {extrasErrors.template ? <p className={styles.fieldError}>{extrasErrors.template}</p> : null}
                      <p className={styles.small}>{actorRole === 'organization_owner'
                        ? 'Финансите се дават след добавянето, от „Права“ в профила, с потвърждение.'
                        : 'Финансите дава само собственикът.'}</p>
                    </div>
                  </details>
                  {/* Under the folded line; once open, the same sentence leads the rights. */}
                  {extrasOpen ? null : <p id="add-extras-hint" className={styles.small}>Например да вижда телефона на родителя, ако трябва спешно да се обади.</p>}
                </div>
              ) : (
                <div className={styles.extras}>
                  <p className={styles.extrasOff}>+ Допълнителни права (по избор)</p>
                  <p className={styles.small}>Правата се дават заедно с поканата.</p>
                </div>
              )}

              <label className={styles.switch}>
                <input type="checkbox" role="switch" checked={invites} disabled={role !== 'instructor'} onChange={(event) => setInviteTeacher(event.target.checked)} aria-describedby="add-invite-hint" />
                <span className={styles.choiceText}>Изпрати покана за достъп
                  <span id="add-invite-hint">{role === 'instructor'
                    ? 'Ще получи имейл с връзка. Без покана всичко работи, но присъствията отбелязвате вие.'
                    : 'Тази роля изисква достъп, затова поканата е задължителна. Ще получи имейл с връзка.'}</span>
                </span>
              </label>

              {error ? <p className={styles.error} role="alert">{error}</p> : null}
              <div className={styles.formActions}>
                <button type="button" className={styles.secondary} onClick={() => dialog.current?.close()}>Отказ</button>
                <button type="submit" className={styles.primary} disabled={busy} aria-busy={busy}>
                  {busy ? 'Добавяне…' : invites ? 'Добави и изпрати покана' : 'Добави'}
                </button>
              </div>
            </form>
          )}
        </div>
      </div>
    </dialog>
  );
}
