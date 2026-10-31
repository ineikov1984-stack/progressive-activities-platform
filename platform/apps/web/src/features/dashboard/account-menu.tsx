'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { type KeyboardEvent, useEffect, useRef, useState } from 'react';
import { signOut } from '@/features/team/api';
import { roleLabel } from '@/features/team/presentation';
import type { DashboardOrganization, DashboardUser } from './api';
import styles from './dashboard-shell.module.css';

function initials(name: string): string {
  return name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toLocaleUpperCase('bg-BG') || '?';
}

/** "Изход": the session ends and the login opens; a failure is said in words. */
export function useLeave(): { leave: () => Promise<void>; leaving: boolean; failed: boolean } {
  const router = useRouter();
  const [leaving, setLeaving] = useState(false);
  const [failed, setFailed] = useState(false);
  async function leave() {
    setLeaving(true); setFailed(false);
    try {
      await signOut();
      router.replace('/login');
    } catch {
      setFailed(true);
      setLeaving(false);
    }
  }
  return { leave, leaving, failed };
}

/**
 * The circle with the initials at the top right opens "Моят профил" and "Изход" (DECISIONS 31.10.2026 „Моят профил и
 * заместник“). A disclosure, not a menu widget: Tab moves through it, Esc and a click outside close it, and the focus
 * goes back to the circle.
 */
export function AccountMenu({ user, organization }: { user: DashboardUser; organization: DashboardOrganization }) {
  const [open, setOpen] = useState(false);
  const wrapper = useRef<HTMLDivElement>(null);
  const toggle = useRef<HTMLButtonElement>(null);
  const first = useRef<HTMLAnchorElement>(null);
  const { leave, leaving, failed } = useLeave();

  useEffect(() => {
    if (!open) return;
    first.current?.focus();
    function outside(event: MouseEvent) {
      if (wrapper.current && event.target instanceof Node && !wrapper.current.contains(event.target)) setOpen(false);
    }
    document.addEventListener('mousedown', outside);
    return () => document.removeEventListener('mousedown', outside);
  }, [open]);

  function keys(event: KeyboardEvent<HTMLDivElement>) {
    if (event.key !== 'Escape' || !open) return;
    event.preventDefault();
    setOpen(false);
    toggle.current?.focus();
  }

  return (
    <div ref={wrapper} className={styles.account} onKeyDown={keys}>
      <button ref={toggle} type="button" className={styles.accountButton} aria-expanded={open} aria-controls="account-panel" onClick={() => setOpen((value) => !value)}>
        <span className={styles.avatar} aria-hidden="true">{initials(user.name)}</span>
        <span className="sr-only">Моят профил и изход — {user.name}</span>
        <svg className={styles.accountChevron} viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
      </button>
      {open ? (
        <div id="account-panel" className={styles.accountPanel}>
          <div className={styles.accountWho}>
            <strong>{user.name}</strong>
            <span>{user.email}</span>
            <span>{roleLabel(organization.access?.role ?? organization.role)} · {organization.name}</span>
          </div>
          <Link ref={first} className={styles.accountItem} href="/dashboard/me" onClick={() => setOpen(false)}>Моят профил</Link>
          <button type="button" className={styles.accountItem} disabled={leaving} aria-busy={leaving} onClick={leave}>{leaving ? 'Изход…' : 'Изход'}</button>
          {failed ? <p className={styles.accountError} role="alert">Изходът не стана. Опитайте отново.</p> : null}
        </div>
      ) : null}
    </div>
  );
}

/** In the ☰ menu on a phone: the card with the name opens "Моят профил". */
export function ProfileCard({ user, organization, onOpen }: { user: DashboardUser; organization: DashboardOrganization; onOpen: () => void }) {
  return (
    <Link className={styles.profileCard} href="/dashboard/me" onClick={onOpen}>
      <span className={styles.profileAvatar} aria-hidden="true">{initials(user.name)}</span>
      <span className={styles.profileText}>
        <strong>{user.name}</strong>
        <small>{roleLabel(organization.access?.role ?? organization.role)} · Моят профил</small>
      </span>
      <span aria-hidden="true">›</span>
    </Link>
  );
}

/** "Изход" at the bottom of the ☰ menu on a phone. */
export function LeaveButton() {
  const { leave, leaving, failed } = useLeave();
  return (
    <>
      <button type="button" className={styles.leaveButton} disabled={leaving} aria-busy={leaving} onClick={leave}>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l-5-5 5-5M5 12h11M14 4h5v16h-5" /></svg>
        <span>{leaving ? 'Изход…' : 'Изход'}</span>
      </button>
      {failed ? <p className={styles.leaveError} role="alert">Изходът не стана. Опитайте отново.</p> : null}
    </>
  );
}
