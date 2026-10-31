'use client';

import { useId, useRef, useState } from 'react';
import type { SentInvitation } from './api';
import styles from './team.module.css';

/**
 * "Изпратихме покана на …" with the inviter's link for copying (docs/product/team.md, т. 7.3). The link is shown only
 * here, right after sending, and joins only an existing profile; a new person makes theirs from the e-mail.
 */
export function SentInvitationNotice({ sent }: { sent: SentInvitation }) {
  const id = useId();
  const input = useRef<HTMLInputElement>(null);
  const [copied, setCopied] = useState<'' | 'done' | 'by_hand'>('');

  async function copy() {
    try {
      await navigator.clipboard.writeText(sent.link);
      setCopied('done');
    } catch {
      // No clipboard (an older browser, a page without https): the link is selected to be copied by hand.
      input.current?.focus();
      input.current?.select();
      setCopied('by_hand');
    }
  }

  return (
    <div className={styles.copy}>
      <p className={styles.ok}>Изпратихме покана на {sent.invitation.email}.</p>
      <label className={styles.label} htmlFor={id}>Връзка за копиране</label>
      <div className={styles.copyRow}>
        <input id={id} ref={input} className={styles.input} value={sent.link} readOnly aria-describedby={`${id}-hint`} onFocus={(event) => event.currentTarget.select()} />
        <button type="button" className={styles.secondary} onClick={copy}>Копирай връзката</button>
      </div>
      <p id={`${id}-hint`} className={styles.small}>Връзката работи само за човек, който вече има профил в системата. Нов човек създава профила си от връзката в писмото.</p>
      <p className={copied ? styles.muted : 'sr-only'} role="status">
        {copied === 'done' ? 'Връзката е копирана.' : copied === 'by_hand' ? 'Връзката е избрана — копирайте я ръчно.' : ''}
      </p>
    </div>
  );
}
