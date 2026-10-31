'use client';

import { useEffect, useId, useRef, useState } from 'react';
import { type DetailsTarget, EnrollmentApiError, revealContact, type RevealedContact, type RevealFrom } from './api';
import { revealErrorText } from './presentation';
import styles from './enrollments.module.css';

/**
 * The parent's phone and e-mail: the number to read out and "Обади се", the e-mail and "Пиши". The phone link is the
 * main button on a phone and secondary on a computer (CSS).
 */
export function ContactFacts({ contact, childName }: { contact: RevealedContact; childName: string }) {
  return (
    <>
      <dl className={styles.facts}>
        <div>
          <dt>Телефон</dt>
          <dd className={contact.phone_display ? styles.phoneValue : undefined}>{contact.phone_display ?? 'Родителят още не е добавил телефон.'}</dd>
        </div>
        <div>
          <dt>Имейл</dt>
          <dd>{contact.email}</dd>
        </div>
      </dl>
      {contact.email_verified ? null : <p className={styles.contactHint}>Имейлът още не е потвърден от родителя — може да е грешен. По-сигурно е да се обадите.</p>}
      <div className={styles.actions}>
        {contact.phone ? <a className={`${styles.primaryLink} ${styles.callLink}`} href={`tel:${contact.phone}`}>Обади се<span className={styles.srOnly}> на родителя на {childName}</span></a> : null}
        {contact.email ? <a className={styles.secondaryLink} href={`mailto:${contact.email}`}>Пиши<span className={styles.srOnly}> на родителя на {childName}</span></a> : null}
      </div>
    </>
  );
}

type Props = {
  organizationId: string;
  target: DetailsTarget;
  from: RevealFrom;
  childName: string;
  hasPhone: boolean;
  hasEmail: boolean;
  /** The list of a group says it once, above the children, not under each one. */
  showNote?: boolean;
  /** The parent's name with the contact — where it was not on the screen before (the card of a child in "График"). */
  showName?: boolean;
};

/** The words for a reveal being recorded (the glossary). */
export const REVEAL_NOTE = 'Ще остане отбелязано, че сте видели телефона.';

/**
 * "Покажи телефона" (docs/product/team-rights.md, т. 6, 10): for everyone but the owner the phone and the e-mail come
 * only after the button, and every reveal is recorded. The answer stays in this component's memory only — never in
 * the browser's storage, the address or a log — and is gone when the panel or the row closes.
 */
export function ContactReveal({ organizationId, target, from, childName, hasPhone, hasEmail, showNote = true, showName = false }: Props) {
  const hint = useId();
  const shown = useRef<HTMLDivElement>(null);
  const failed = useRef<HTMLParagraphElement>(null);
  const [contact, setContact] = useState<RevealedContact>();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<{ text: string; final: boolean }>();

  // The button is gone once the contact is shown: the focus goes to the contact, so it is not lost (WCAG 2.4.3).
  useEffect(() => {
    if (contact) shown.current?.focus();
  }, [contact]);

  // A refusal that takes the button's place takes its focus too.
  useEffect(() => {
    if (error?.final) failed.current?.focus();
  }, [error]);

  async function reveal() {
    setBusy(true);
    setError(undefined);
    try {
      setContact(await revealContact(organizationId, target, from));
    } catch (reason) {
      const status = reason instanceof EnrollmentApiError ? reason.status : 0;
      // Too many in a short time, or the contact is no longer there: the message takes the button's place.
      setError({ text: revealErrorText(status), final: status === 429 || status === 404 || status === 403 });
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      {/* Stays in the page from the start, so the change is announced (WCAG 4.1.3). */}
      <p className={styles.srOnly} role="status">{contact ? (contact.phone ? 'Телефонът е показан.' : 'Имейлът е показан.') : ''}</p>
      {contact ? (
        <div ref={shown} tabIndex={-1} className={styles.reveal} role="group" aria-label={`Контакт на родителя на ${childName}`}>
          {showName && contact.name ? <p className={styles.contactName}>{contact.name}</p> : null}
          <ContactFacts contact={contact} childName={childName} />
          {showNote ? <p className={styles.small}>{REVEAL_NOTE}</p> : null}
        </div>
      ) : (
        <div className={styles.reveal}>
          {hasPhone ? null : <p className={styles.muted}>Родителят още не е добавил телефон.</p>}
          {error ? <p ref={failed} tabIndex={-1} className={styles.error} role="alert">{error.text}</p> : null}
          {(hasPhone || hasEmail) && !error?.final ? (
            <>
              <div className={styles.actions}>
                <button type="button" className={styles.secondary} disabled={busy} aria-busy={busy} aria-describedby={showNote ? hint : undefined} onClick={reveal}>
                  {busy ? 'Показване…' : hasPhone ? 'Покажи телефона' : 'Покажи имейла'}
                  <span className={styles.srOnly}> на родителя на {childName}</span>
                </button>
              </div>
              {showNote ? <p id={hint} className={styles.small}>{REVEAL_NOTE}</p> : null}
            </>
          ) : null}
        </div>
      )}
    </>
  );
}
