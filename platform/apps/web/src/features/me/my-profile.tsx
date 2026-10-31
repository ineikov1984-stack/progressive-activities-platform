'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { type FormEvent, useEffect, useRef, useState } from 'react';
import { loadTeaching, type TeachingGroup } from '@/features/dashboard/api';
import { useDashboardContext } from '@/features/dashboard/dashboard-context';
import { signOut } from '@/features/team/api';
import { focusLater, initials, roleLabel } from '@/features/team/presentation';
import { accessSummary } from '@/features/team/rights';
import { AccessLists } from '@/features/team/role-access';
import { ProfileApiError, updateOwnContact } from './api';
import { canOpenGroups, type ContactErrors, contactErrors, groupCountText, myGroups, ownGrants, ownScope, phoneText, scopeText } from './presentation';
import styles from './me.module.css';

type Notify = (text: string) => void;

/** "Моите данни": the name and the phone the person changes; the e-mail is read only. */
function DetailsCard({ onNotice }: { onNotice: Notify }) {
  const { user, organization, updateUser } = useDashboardContext();
  const role = organization.access?.role ?? organization.role;
  const [editing, setEditing] = useState(false);
  const [values, setValues] = useState({ name: user.name, phone: phoneText(user.phone) });
  const [errors, setErrors] = useState<ContactErrors>({});
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  function start() {
    setValues({ name: user.name, phone: phoneText(user.phone) });
    setErrors({}); setError(''); setEditing(true);
    focusLater('me-name');
  }

  function stop() {
    setEditing(false); setErrors({}); setError('');
    focusLater('me-edit');
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const found = contactErrors(values);
    if (found.name || found.phone) {
      setErrors(found);
      focusLater(found.name ? 'me-name' : 'me-phone');
      return;
    }
    setBusy(true); setError(''); setErrors({});
    try {
      const saved = await updateOwnContact(organization.id, { name: values.name.trim(), phone: values.phone.trim() });
      updateUser?.(saved);
      setEditing(false);
      onNotice('Данните са запазени. Екипът вижда новия телефон.');
      focusLater('me-edit');
    } catch (reason) {
      const fields = reason instanceof ProfileApiError ? reason.fieldErrors : {};
      const shown: ContactErrors = { name: fields.name?.[0], phone: fields.phone?.[0] };
      setErrors(shown);
      setError(reason instanceof Error && reason.message ? reason.message : 'Данните не бяха запазени.');
      if (shown.name || shown.phone) focusLater(shown.name ? 'me-name' : 'me-phone');
    } finally {
      setBusy(false);
    }
  }

  const describe = (field: keyof ContactErrors) => (errors[field] ? `me-${field}-error` : undefined);
  return (
    <section className={`${styles.card} ${styles.details}`} aria-labelledby="me-details">
      <div className={styles.person}>
        <span className={styles.avatar} aria-hidden="true">{initials(user.name)}</span>
        <div className={styles.personText}>
          <h2 id="me-details">{user.name}</h2>
          <p className={styles.muted}>{roleLabel(role)} · {organization.name}</p>
        </div>
        {editing ? null : <button id="me-edit" type="button" className={styles.secondary} onClick={start}>Промени<span className="sr-only"> името и телефона</span></button>}
      </div>
      {editing ? (
        <form className={styles.form} onSubmit={submit} noValidate>
          <div className={styles.field}>
            <label htmlFor="me-name">Име и фамилия</label>
            <input id="me-name" className={styles.input} autoComplete="name" value={values.name} required
              aria-invalid={errors.name ? true : undefined} aria-describedby={describe('name')}
              onChange={(event) => { setValues((current) => ({ ...current, name: event.target.value })); setErrors((current) => ({ ...current, name: undefined })); }} />
            {errors.name ? <p id="me-name-error" className={styles.fieldError}>{errors.name}</p> : null}
          </div>
          <div className={styles.field}>
            <label htmlFor="me-phone">Телефон</label>
            <input id="me-phone" className={styles.input} type="tel" inputMode="tel" autoComplete="tel" value={values.phone} required
              aria-invalid={errors.phone ? true : undefined} aria-describedby={describe('phone')}
              onChange={(event) => { setValues((current) => ({ ...current, phone: event.target.value })); setErrors((current) => ({ ...current, phone: undefined })); }} />
            {errors.phone ? <p id="me-phone-error" className={styles.fieldError}>{errors.phone}</p> : null}
          </div>
          {error ? <p className={styles.error} role="alert">{error}</p> : null}
          <p className={styles.small}>Новият телефон вижда и екипът на {organization.name}.</p>
          <div className={styles.actions}>
            <button type="submit" className={styles.primary} disabled={busy} aria-busy={busy}>{busy ? 'Запазване…' : 'Запази'}</button>
            <button type="button" className={styles.secondary} onClick={stop}>Отказ</button>
          </div>
        </form>
      ) : null}
      <dl className={styles.facts}>
        {editing ? null : <div><dt>Телефон</dt><dd>{user.phone ? phoneText(user.phone) : 'Още не сте добавили телефон.'}</dd></div>}
        <div><dt>Имейл</dt><dd>{user.email}</dd></div>
      </dl>
      <p className={styles.small}>С имейла влизате в системата. Имейлът сменя собственикът на организацията.</p>
    </section>
  );
}

/** "Моите групи" (`GET /me/teaching`): by activity, the person's part and the next session; a group opens its children. */
function GroupsCard({ teaching, failed, onRetry }: { teaching: TeachingGroup[] | undefined; failed: boolean; onRetry: () => void }) {
  const { organization } = useDashboardContext();
  const linked = canOpenGroups(organization.permissions);
  const activities = teaching ? myGroups(teaching) : [];
  return (
    <section className={`${styles.card} ${styles.groups}`} aria-labelledby="me-groups" aria-busy={!teaching && !failed}>
      <div className={styles.cardHead}>
        <h2 id="me-groups">Моите групи</h2>
        {teaching && teaching.length > 0 ? <span className={styles.small}>{groupCountText(teaching.length)}</span> : null}
      </div>
      {failed ? (
        <div className={styles.stack}>
          <p className={styles.error} role="alert">Групите не се заредиха. Проверете връзката.</p>
          <div className={styles.actions}><button type="button" className={styles.secondary} onClick={onRetry}>Опитай отново</button></div>
        </div>
      ) : !teaching ? (
        <span className={styles.skeleton} />
      ) : teaching.length === 0 ? (
        <p className={styles.muted}>Не водите групи в {organization.name}. Когато ви добавят в група, тя ще се появи тук.</p>
      ) : (
        activities.map((item) => (
          <div key={item.activity} className={styles.activity}>
            <h3>{item.activity}</h3>
            <ul className={styles.groupList}>
              {item.groups.map((group) => (
                <li key={group.id} className={styles.group}>
                  <span className={styles.groupName}>
                    {linked ? <Link className={styles.groupLink} href={group.href}>{group.name}<span className="sr-only"> — децата в групата</span></Link> : group.name}
                  </span>
                  <span className={`${styles.part} ${styles[`part_${group.tone}`]}`}>{group.part}</span>
                  <span className={styles.groupDetail}>{group.detail}</span>
                </li>
              ))}
            </ul>
          </div>
        ))
      )}
      {/* "Търся заместник" comes with "График": no button that does nothing until then. */}
      <p className={styles.later}>Заместник ще можете да поискате оттук, когато влезе графикът.</p>
    </section>
  );
}

/** "Какво можете": read only, from the role and the added rights — the same words as "Екип". */
function RightsCard({ teaches }: { teaches: boolean }) {
  const { organization } = useDashboardContext();
  const role = organization.access?.role ?? organization.role;
  const scope = ownScope(role, organization.access);
  const grants = ownGrants(role, organization.access);
  const selected = (organization.access?.groups.length ?? 0) + (organization.access?.activities.length ?? 0);
  return (
    <section className={`${styles.card} ${styles.rights}`} aria-labelledby="me-rights">
      <div className={styles.cardHead}>
        <h2 id="me-rights">Какво можете</h2>
        <span className={styles.badge}>само за четене</span>
      </div>
      <p>Роля: <strong className={styles.strong}>{roleLabel(role)}</strong> · {scopeText(scope)}</p>
      <AccessLists summary={accessSummary({ role, scope, grants, teaches, selected })} when="now" />
      {role === 'organization_owner' ? null : <p className={styles.small}>Правата дава собственикът или администраторът.</p>}
    </section>
  );
}

/** "Сигурност": the way out now; the password with the protection of the login. */
function SecurityCard() {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  async function leave() {
    setBusy(true); setError('');
    try {
      await signOut();
      router.replace('/login');
    } catch {
      setError('Изходът не стана. Проверете връзката и опитайте отново.');
      setBusy(false);
    }
  }

  return (
    <section className={`${styles.card} ${styles.security}`} aria-labelledby="me-security">
      <div className={styles.cardHead}>
        <h2 id="me-security">Сигурност</h2>
        <button type="button" className={styles.secondary} disabled={busy} aria-busy={busy} onClick={leave}>{busy ? 'Изход…' : 'Изход'}</button>
      </div>
      {error ? <p className={styles.error} role="alert">{error}</p> : null}
      <p className={styles.muted}>Смяна на парола — скоро.</p>
    </section>
  );
}

/**
 * "Моят профил" (DECISIONS 31.10.2026 „Моят профил и заместник“; макет „Моят профил (учител)“): for every person of a
 * team — the details, their groups, what they can do, and the way out. On a phone one under another; wider, the
 * details and security on the left, the groups and the rights on the right.
 */
export function MyProfile() {
  const { organization } = useDashboardContext();
  const [attempt, setAttempt] = useState(0);
  const [loaded, setLoaded] = useState<{ key: string; groups: TeachingGroup[] }>();
  const [failed, setFailed] = useState<string>();
  const [notice, setNotice] = useState<{ text: string }>();
  const noticeRef = useRef<HTMLParagraphElement>(null);
  const key = `${organization.id}|${attempt}`;

  useEffect(() => {
    let active = true;
    loadTeaching(organization.id)
      .then((groups) => { if (active) setLoaded({ key, groups }); })
      .catch(() => { if (active) setFailed(key); });
    return () => { active = false; };
  }, [organization.id, key]);

  useEffect(() => {
    if (!notice) return;
    noticeRef.current?.focus();
  }, [notice]);

  const teaching = loaded?.key === key ? loaded.groups : undefined;
  return (
    <div className={styles.page}>
      <h1>Моят профил</h1>
      <p ref={noticeRef} tabIndex={-1} className={notice ? styles.ok : 'sr-only'} role="status">{notice?.text ?? ''}</p>
      <div className={styles.columns}>
        <div className={styles.column}>
          <DetailsCard onNotice={(text) => setNotice({ text })} />
          <SecurityCard />
        </div>
        <div className={styles.column}>
          <GroupsCard teaching={teaching} failed={failed === key} onRetry={() => setAttempt((value) => value + 1)} />
          <RightsCard teaches={(teaching?.length ?? 0) > 0} />
        </div>
      </div>
    </div>
  );
}
