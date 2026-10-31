'use client';

import Link from 'next/link';
import { type FormEvent, useEffect, useRef, useState } from 'react';
import { login } from '@/features/auth/api';
import {
  type AcceptedInvitation, type InvitationSummary, joinByInvitation, lookupInvitation, registerByInvitation, type SignedInUser, signedInUser, signOut,
  TeamApiError,
} from './api';
import { tokenFromHash, welcomeText } from './presentation';
import styles from './invite.module.css';

type GuestMode = 'register' | 'login';
type View =
  | { kind: 'loading' }
  | { kind: 'missing' }
  | { kind: 'gone'; why: 'invalid' | 'expired' | 'accepted' }
  | { kind: 'guest'; invitation: InvitationSummary; mode: GuestMode; notice: string }
  | { kind: 'signed_in'; invitation: InvitationSummary; user: SignedInUser }
  | { kind: 'welcome'; accepted: AcceptedInvitation };

/** This tab only: a reload or the login keeps the invitation, and the token leaves the address bar. */
const STORAGE_KEY = 'progressive:invite-token';
const untilFormat = new Intl.DateTimeFormat('bg-BG', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Europe/Sofia' });

/**
 * The token is in the fragment (`/invite#…`), which the browser never sends to a server. It is read here once, kept in
 * this tab's session storage and taken out of the address and its history entry; it is never put in a query string.
 */
function takeToken(): string | null {
  const fromLink = tokenFromHash(window.location.hash);
  if (fromLink) {
    try {
      window.sessionStorage.setItem(STORAGE_KEY, fromLink);
      window.history.replaceState(null, '', `${window.location.pathname}${window.location.search}`);
    } catch {
      // Without storage the fragment stays, so a reload still finds the invitation.
    }
    return fromLink;
  }
  try {
    return tokenFromHash(window.sessionStorage.getItem(STORAGE_KEY) ?? '');
  } catch {
    return null;
  }
}

function forgetToken(): void {
  try {
    window.sessionStorage.removeItem(STORAGE_KEY);
  } catch {
    // Nothing was kept.
  }
}

/** Not found (a link sent again, a wrong one) or expired: the invitation no longer works. */
function goneView(reason: unknown): View | null {
  if (!(reason instanceof TeamApiError)) return null;
  if (reason.status === 404) return { kind: 'gone', why: 'invalid' };
  if (reason.status === 410) return { kind: 'gone', why: 'expired' };
  return null;
}

async function open(token: string | null): Promise<View> {
  if (!token) return { kind: 'missing' };
  let invitation: InvitationSummary;
  try {
    invitation = await lookupInvitation(token);
  } catch (reason) {
    const gone = goneView(reason);
    if (!gone) throw reason;
    forgetToken();
    return gone;
  }
  if (invitation.status !== 'pending') {
    forgetToken();
    return { kind: 'gone', why: invitation.status === 'accepted' ? 'accepted' : 'expired' };
  }
  const user = await signedInUser();
  return user ? { kind: 'signed_in', invitation, user } : { kind: 'guest', invitation, mode: 'register', notice: '' };
}

const fieldMessages = (reason: unknown): Record<string, string> => (reason instanceof TeamApiError
  ? Object.fromEntries(Object.entries(reason.fieldErrors).map(([field, messages]) => [field, messages[0] ?? '']))
  : {});
const failure = (reason: unknown, fallback: string) => (reason instanceof Error && reason.message ? reason.message : fallback);

function Field({ id, name, label, type, autoComplete, error, hint }: {
  id: string; name: string; label: string; type: 'text' | 'email' | 'password'; autoComplete: string; error?: string; hint?: string;
}) {
  const described = [hint ? `${id}-hint` : '', error ? `${id}-error` : ''].filter(Boolean).join(' ') || undefined;
  return (
    <div className={styles.field}>
      <label htmlFor={id}>{label}</label>
      <input id={id} name={name} className={styles.input} type={type} autoComplete={autoComplete} required
        aria-invalid={error ? true : undefined} aria-describedby={described} {...(type === 'email' ? { inputMode: 'email' as const } : {})} />
      {hint ? <p id={`${id}-hint`} className={styles.hint}>{hint}</p> : null}
      {error ? <p id={`${id}-error`} className={styles.fieldError}>{error}</p> : null}
    </div>
  );
}

/**
 * "/invite" (docs/product/team.md, т. 7.4; board „Покана — приемане“): a new person makes a profile from the e-mail's
 * link, someone with a profile logs in, a signed-in person accepts or signs out. The organization is the invitation's.
 */
export function InvitePage() {
  const token = useRef<string | null>(null);
  const heading = useRef<HTMLHeadingElement>(null);
  const shownStep = useRef('loading');
  const [view, setView] = useState<View>({ kind: 'loading' });
  const [loadError, setLoadError] = useState('');
  const [attempt, setAttempt] = useState(0);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    let active = true;
    if (token.current === null) token.current = takeToken();
    open(token.current)
      .then((next) => { if (active) setView(next); })
      .catch(() => { if (active) setLoadError('Поканата не се зареди. Проверете връзката и опитайте отново.'); });
    return () => { active = false; };
  }, [attempt]);

  // A new step of the page (also "Влезте" / "Създай нов профил") takes the focus to its heading; not the first one,
  // when the page has just opened.
  const step = view.kind === 'guest' ? `guest:${view.mode}` : view.kind;
  useEffect(() => {
    const previous = shownStep.current;
    shownStep.current = step;
    if (previous !== 'loading' && previous !== step) heading.current?.focus();
  }, [step]);

  function switchMode(mode: GuestMode) {
    if (view.kind !== 'guest') return;
    setError(''); setFieldErrors({});
    setView({ ...view, mode, notice: '' });
  }

  async function accept(invitation: InvitationSummary) {
    if (!token.current) return;
    try {
      const accepted = await joinByInvitation(token.current);
      forgetToken();
      setView({ kind: 'welcome', accepted });
    } catch (reason) {
      const gone = goneView(reason);
      if (gone) { forgetToken(); setView(gone); return; }
      // Signed in by now: the page says as whom, so a wrong profile can sign out.
      const user = await signedInUser().catch(() => null);
      if (user) setView({ kind: 'signed_in', invitation, user });
      setError(failure(reason, 'Поканата не беше приета.'));
    }
  }

  async function register(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (view.kind !== 'guest' || !token.current) return;
    const form = new FormData(event.currentTarget);
    const name = String(form.get('name') ?? '').trim();
    const password = String(form.get('password') ?? '');
    const confirmation = String(form.get('password_confirmation') ?? '');
    const found: Record<string, string> = {};
    if (!name) found.name = 'Напишете името си.';
    if (password.length < 10) found.password = 'Паролата трябва да е поне 10 знака.';
    else if (password !== confirmation) found.password_confirmation = 'Паролите не съвпадат.';
    setError('');
    setFieldErrors(found);
    if (Object.keys(found).length > 0) return;

    setBusy(true);
    try {
      const accepted = await registerByInvitation(token.current, name, password, confirmation);
      forgetToken();
      setView({ kind: 'welcome', accepted });
    } catch (reason) {
      const gone = goneView(reason);
      if (gone) { forgetToken(); setView(gone); return; }
      if (reason instanceof TeamApiError && (reason.status === 403 || reason.status === 409)) {
        // 403: the inviter's link only joins an existing profile; 409: the address already has one.
        setView({
          ...view, mode: 'login', notice: reason.status === 403
            ? 'Тази връзка е за човек, който вече има профил. Влезте с имейла, на който е изпратена поканата. Нов профил се създава само от връзката в писмото.'
            : 'Вече имате профил с този имейл. Влезте, за да приемете поканата.',
        });
        return;
      }
      setFieldErrors(fieldMessages(reason));
      setError(failure(reason, 'Профилът не беше създаден.'));
    } finally {
      setBusy(false);
    }
  }

  async function signInAndAccept(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (view.kind !== 'guest') return;
    const form = new FormData(event.currentTarget);
    const email = String(form.get('email') ?? '').trim();
    const password = String(form.get('password') ?? '');
    const found: Record<string, string> = {};
    if (!email) found.email = 'Въведете имейла си.';
    if (!password) found.password = 'Въведете паролата си.';
    setError('');
    setFieldErrors(found);
    if (Object.keys(found).length > 0) return;

    setBusy(true);
    try {
      await login({ email, password });
    } catch (reason) {
      setError(failure(reason, 'Грешен имейл или парола.'));
      setBusy(false);
      return;
    }
    await accept(view.invitation);
    setBusy(false);
  }

  async function acceptSignedIn() {
    if (view.kind !== 'signed_in') return;
    setBusy(true); setError('');
    await accept(view.invitation);
    setBusy(false);
  }

  async function leave() {
    if (view.kind !== 'signed_in') return;
    setBusy(true); setError('');
    try {
      await signOut();
      setView({ kind: 'guest', invitation: view.invitation, mode: 'login', notice: '' });
    } catch (reason) {
      setError(failure(reason, 'Изходът не беше успешен. Опитайте отново.'));
    } finally {
      setBusy(false);
    }
  }

  const title = (text: string) => <h1 id="invite-heading" ref={heading} tabIndex={-1}>{text}</h1>;
  const errorLine = error ? <p className={styles.error} role="alert">{error}</p> : null;
  const toLogin = <Link className={styles.secondary} href="/login">Към входа</Link>;

  function content() {
    if (view.kind === 'loading') {
      return loadError ? (
        <>
          <p className={styles.kicker}>Покана</p>
          {title('Поканата не се зареди')}
          <p className={styles.error} role="alert">{loadError}</p>
          <button type="button" className={styles.secondary} onClick={() => { setLoadError(''); setAttempt((value) => value + 1); }}>Опитай отново</button>
        </>
      ) : (
        <>
          <p className={styles.kicker}>Покана</p>
          {title('Отваряме поканата…')}
          <span className={styles.skeleton} />
          <span className={`${styles.skeleton} ${styles.skeletonTall}`} />
        </>
      );
    }
    if (view.kind === 'missing') {
      return (
        <>
          <p className={styles.kicker}>Покана</p>
          {title('Липсва връзката от поканата')}
          <p className={styles.text}>Отворете отново връзката от писмото с поканата. Ако сте я копирали, копирайте я цялата.</p>
          {toLogin}
        </>
      );
    }
    if (view.kind === 'gone') {
      return view.why === 'accepted' ? (
        <>
          <p className={styles.kicker}>Покана</p>
          {title('Поканата вече е приета')}
          <p className={styles.text}>Ако сте я приели вие, влезте в системата. Ако не сте — поискайте нова покана от организацията, която ви е поканила.</p>
          <Link className={styles.primary} href="/login">Към входа</Link>
        </>
      ) : (
        <>
          <p className={styles.kicker}>Покана</p>
          {title('Поканата вече не важи')}
          <p className={styles.text}>Изтекла е или е изпратена наново. Поискайте нова покана от организацията, която ви е поканила.</p>
          {toLogin}
        </>
      );
    }
    if (view.kind === 'welcome') {
      const { organization, membership } = view.accepted;
      return (
        <>
          <p className={styles.kicker}>Готово</p>
          {title(`Добре дошли в ${organization.name}`)}
          <p className={styles.text}>{welcomeText(membership.role)}</p>
          <Link className={styles.primary} href={`/dashboard?organization=${encodeURIComponent(organization.id)}`}>Към началото</Link>
        </>
      );
    }

    const { invitation } = view;
    const facts = (
      <div className={styles.facts}>
        <span>Роля: <strong>{invitation.role_label}</strong></span>
        <span>Важи до {untilFormat.format(new Date(invitation.expires_at))}</span>
      </div>
    );
    const head = <><p className={styles.kicker}>Покана</p>{title(`Покана за екипа на ${invitation.organization_name}`)}{facts}</>;

    if (view.kind === 'signed_in') {
      return (
        <>
          {head}
          <p className={styles.text}>Вече сте влезли като <strong>{view.user.name}</strong>.</p>
          {errorLine}
          <button type="button" className={styles.primary} disabled={busy} aria-busy={busy} onClick={acceptSignedIn}>{busy ? 'Приемане…' : 'Приеми поканата'}</button>
          <button type="button" className={styles.secondary} disabled={busy} onClick={leave}>Това не съм аз — изход</button>
        </>
      );
    }

    return view.mode === 'register' ? (
      <>
        {head}
        {view.notice ? <p className={styles.notice} role="status">{view.notice}</p> : null}
        <p className={styles.text}>Създайте профил, за да приемете поканата. Профилът ще е с имейла, на който я получихте.</p>
        <form className={styles.form} onSubmit={register} noValidate>
          <Field id="invite-name" name="name" label="Име и фамилия" type="text" autoComplete="name" error={fieldErrors.name} />
          <Field id="invite-password" name="password" label="Парола" type="password" autoComplete="new-password" hint="Поне 10 знака." error={fieldErrors.password} />
          <Field id="invite-password-again" name="password_confirmation" label="Повторете паролата" type="password" autoComplete="new-password" error={fieldErrors.password_confirmation} />
          {errorLine}
          <button type="submit" className={styles.primary} disabled={busy} aria-busy={busy}>{busy ? 'Създаване…' : 'Създай профил и приеми'}</button>
        </form>
        <p className={styles.switchLine}>Вече имате профил? <button type="button" className={styles.linkButton} onClick={() => switchMode('login')}>Влезте</button></p>
      </>
    ) : (
      <>
        {head}
        {view.notice ? <p className={styles.notice} role="status">{view.notice}</p> : null}
        <p className={styles.text}>Влезте с имейла, на който е изпратена поканата.</p>
        <form className={styles.form} onSubmit={signInAndAccept} noValidate>
          <Field id="invite-login-email" name="email" label="Имейл" type="email" autoComplete="email" error={fieldErrors.email} />
          <Field id="invite-login-password" name="password" label="Парола" type="password" autoComplete="current-password" error={fieldErrors.password} />
          {errorLine}
          <button type="submit" className={styles.primary} disabled={busy} aria-busy={busy}>{busy ? 'Влизане…' : 'Влез и приеми'}</button>
        </form>
        <p className={styles.hint}>Нямате профил? Отворете връзката от писмото с поканата — само от нея се създава нов профил.</p>
        <p className={styles.switchLine}><button type="button" className={styles.linkButton} onClick={() => switchMode('register')}>Създай нов профил</button></p>
      </>
    );
  }

  return (
    <main className={styles.page}>
      <Link className={styles.brand} href="/"><span className={styles.mark} aria-hidden="true">PA</span>Progressive Activities</Link>
      <section className={styles.card} aria-labelledby="invite-heading" aria-busy={view.kind === 'loading' && !loadError}>
        {content()}
      </section>
    </main>
  );
}
