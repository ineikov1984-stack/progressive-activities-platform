'use client';

import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { ReactNode, useEffect, useMemo, useRef, useState } from 'react';
import {
  AuthenticationRequiredError,
  loadOrganizationContext,
  type DashboardOrganization,
  type DashboardUser,
} from './api';
import { DashboardContext } from './dashboard-context';
import {
  dashboardNavigation,
  dashboardNavigationStorageKey,
  defaultDashboardNavigationCollapsed,
  parseDashboardNavigationPreference,
  type DashboardIconName,
} from './navigation';
import styles from './dashboard-shell.module.css';

type DashboardShellProps = { children: ReactNode };

type ShellIconName = DashboardIconName | 'menu' | 'close' | 'bell' | 'eye' | 'chevron-left' | 'chevron-right';

const iconPaths: Record<ShellIconName, string[]> = {
  home: ['M3 10.5 12 3l9 7.5', 'M5 9.5V21h14V9.5', 'M9 21v-7h6v7'],
  activities: ['m12 3 1.6 4.4L18 9l-4.4 1.6L12 15l-1.6-4.4L6 9l4.4-1.6L12 3Z', 'm5 15 .9 2.1L8 18l-2.1.9L5 21l-.9-2.1L2 18l2.1-.9L5 15Z'],
  calendar: ['M4 5h16v16H4z', 'M8 3v4', 'M16 3v4', 'M4 10h16', 'M8 14h2', 'M14 14h2'],
  enrollments: ['M9 11h6', 'M9 15h4', 'M6 3h12v18H6z', 'm15 7 1 1 2-2'],
  'open-days': ['M5 21V4l10-1v18', 'M15 21h4V5h-4', 'M11 12h.01'],
  finance: ['M3 7h18v13H3z', 'M3 10h18', 'M7 16h4', 'M16 14h2'],
  analytics: ['M4 20V10', 'M10 20V4', 'M16 20v-7', 'M22 20H2'],
  integrations: ['M8 3H5a2 2 0 0 0-2 2v3', 'M16 3h3a2 2 0 0 1 2 2v3', 'M8 21H5a2 2 0 0 1-2-2v-3', 'M16 21h3a2 2 0 0 0 2-2v-3', 'M8 8h8v8H8z'],
  locations: ['M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z', 'M12 10a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z'],
  team: ['M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2', 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z', 'M22 21v-2a4 4 0 0 0-3-3.87', 'M16 3.13a4 4 0 0 1 0 7.75'],
  media: ['M3 5h18v14H3z', 'm3 16 5-5 4 4 3-3 6 6', 'M15 9h.01'],
  menu: ['M4 7h16', 'M4 12h16', 'M4 17h16'],
  close: ['M6 6l12 12', 'M18 6 6 18'],
  bell: ['M6 8a6 6 0 0 1 12 0c0 7 3 8 3 8H3s3-1 3-8', 'M10 20a2 2 0 0 0 4 0'],
  eye: ['M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z', 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z'],
  'chevron-left': ['m15 6-6 6 6 6'],
  'chevron-right': ['m9 6 6 6-6 6'],
  settings: ['M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z', 'M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.12 3.67-.08-.03a1.7 1.7 0 0 0-1.79.23l-.52.3a1.7 1.7 0 0 0-.82 1.7V23H9.53v-.19a1.7 1.7 0 0 0-.82-1.7l-.52-.3a1.7 1.7 0 0 0-1.79-.23l-.08.03-2.12-3.67.06-.06A1.7 1.7 0 0 0 4.6 15l-.3-.52a1.7 1.7 0 0 0-1.53-.9H2v-4.16h.77a1.7 1.7 0 0 0 1.53-.9l.3-.52a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.12-3.67.08.03a1.7 1.7 0 0 0 1.79-.23l.52-.3a1.7 1.7 0 0 0 .82-1.7V1h4.94v.19a1.7 1.7 0 0 0 .82 1.7l.52.3a1.7 1.7 0 0 0 1.79.23l.08-.03 2.12 3.67-.06.06A1.7 1.7 0 0 0 19.4 8l.3.52a1.7 1.7 0 0 0 1.53.9H22v4.16h-.77a1.7 1.7 0 0 0-1.53.9l-.3.52Z'],
};

function DashboardIcon({ name }: { name: ShellIconName }) {
  return <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">{iconPaths[name].map((path) => <path d={path} key={path} />)}</svg>;
}

function initials(name?: string) {
  return name?.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase() || 'PA';
}

export function DashboardShell({ children }: DashboardShellProps) {
  const router = useRouter();
  const pathname = usePathname();
  const started = useRef(false);
  const [user, setUser] = useState<DashboardUser>();
  const [organization, setOrganization] = useState<DashboardOrganization>();
  const [organizations, setOrganizations] = useState<DashboardOrganization[]>([]);
  const [error, setError] = useState('');
  const [isCollapsed, setIsCollapsed] = useState(false);
  const [isMobileOpen, setIsMobileOpen] = useState(false);
  const groups = useMemo(() => dashboardNavigation(pathname), [pathname]);

  useEffect(() => {
    if (started.current) return;
    started.current = true;
    loadOrganizationContext().then((context) => {
      setUser(context.user);
      setOrganization(context.organization);
      setOrganizations(context.organizations);
      const key = dashboardNavigationStorageKey(context.user.id, context.organization.id);
      const saved = parseDashboardNavigationPreference(window.localStorage.getItem(key));
      setIsCollapsed(saved ?? defaultDashboardNavigationCollapsed(window.innerWidth));
    }).catch((reason: unknown) => {
      if (reason instanceof AuthenticationRequiredError) router.replace('/login');
      else setError(reason instanceof Error ? reason.message : 'Администрацията не беше заредена.');
    });
  }, [router]);

  function toggleCollapsed() {
    const next = !isCollapsed;
    setIsCollapsed(next);
    if (user && organization) {
      window.localStorage.setItem(
        dashboardNavigationStorageKey(user.id, organization.id),
        next ? 'collapsed' : 'expanded',
      );
    }
  }

  if (error) {
    return <main className={styles.centerState}><h1>Администрацията не се зареди</h1><p role="alert">{error}</p><button type="button" onClick={() => window.location.reload()}>Опитай отново</button></main>;
  }

  if (!user || !organization) {
    return <div data-admin-shell className={styles.shell} aria-busy="true">
      <aside className={styles.sidebar} aria-hidden="true">
        <div className={styles.brand}><span className={styles.mark}>PA</span><span className={styles.brandText}><strong>Progressive</strong><small>Activities</small></span></div>
        <div className={styles.navigation}>{[0, 1, 2, 3, 4].map((row) => <span className={styles.skeletonNav} key={row} />)}</div>
      </aside>
      <section className={styles.workspace}>
        <header className={styles.topbar}><span className={styles.skeleton} style={{ width: '14rem', height: '1.5rem' }} /></header>
        <div className={styles.skeletonContent}>
          <span className="sr-only" role="status">Зареждане на администрацията…</span>
          <span className={styles.skeleton} style={{ width: '40%', height: '2.25rem' }} />
          <span className={styles.skeleton} style={{ width: '60%', height: '1rem' }} />
          <div className={styles.skeletonGrid}>{[0, 1, 2, 3].map((card) => <span className={styles.skeleton} key={card} style={{ height: '8.5rem' }} />)}</div>
        </div>
      </section>
    </div>;
  }

  const contextValue = { user, organization, organizations };

  return <DashboardContext.Provider value={contextValue}>
    <div data-admin-shell className={`${styles.shell} ${isCollapsed ? styles.collapsed : ''} ${isMobileOpen ? styles.mobileOpen : ''}`}>
      <button className={styles.backdrop} type="button" aria-label="Затвори менюто" onClick={() => setIsMobileOpen(false)} />
      <aside className={styles.sidebar}>
        <div className={styles.brand}><span className={styles.mark}>PA</span><span className={styles.brandText}><strong>Progressive</strong><small>Activities</small></span><button className={styles.mobileClose} type="button" aria-label="Затвори менюто" onClick={() => setIsMobileOpen(false)}><DashboardIcon name="close" /></button></div>
        <nav className={styles.navigation} aria-label="Административна навигация">
          {groups.map((group) => <div className={styles.navGroup} key={group.id}>
            <p className={styles.groupLabel}>{group.label}</p>
            {group.items.map((item) => <Link className={`${styles.navItem} ${item.isActive ? styles.active : ''}`} data-label={item.label} href={item.href ?? '/dashboard'} key={item.id} aria-current={item.isActive ? 'page' : undefined} onClick={() => setIsMobileOpen(false)}><DashboardIcon name={item.icon} /><span>{item.label}</span></Link>)}
          </div>)}
        </nav>
        <button className={styles.collapseButton} type="button" onClick={toggleCollapsed} aria-label={isCollapsed ? 'Разгъни менюто' : 'Свий менюто'}><DashboardIcon name={isCollapsed ? 'chevron-right' : 'chevron-left'} /><span>{isCollapsed ? 'Разгъни менюто' : 'Свий менюто'}</span></button>
      </aside>
      <section className={styles.workspace}>
        <header className={styles.topbar}>
          <button className={styles.mobileMenu} type="button" aria-label="Отвори менюто" onClick={() => setIsMobileOpen(true)}><DashboardIcon name="menu" /></button>
          <div className={styles.organization}><span className={styles.organizationIcon} aria-hidden="true">{initials(organization.name)}</span><span><small>Организация</small><strong>{organization.name}</strong></span></div>
          <div className={styles.topActions}><Link className={styles.publicLink} href={`/o/${organization.slug}`}><DashboardIcon name="eye" />Публичен профил</Link><button className={styles.notification} type="button" aria-label="Известия"><DashboardIcon name="bell" /></button><span className={styles.avatar} aria-label={user.name}>{initials(user.name)}</span></div>
        </header>
        <div className={styles.content}>{children}</div>
      </section>
    </div>
  </DashboardContext.Provider>;
}
