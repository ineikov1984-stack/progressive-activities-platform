'use client';

import { FormEvent, useEffect, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import {
  AuthenticationRequiredError,
  createInvitation,
  DashboardData,
  DashboardInvitation,
  loadDashboard,
} from './api';
import styles from '@/app/dashboard/page.module.css';

const roleLabels: Record<string, string> = {
  platform_admin: 'Платформен администратор',
  organization_owner: 'Собственик на организация',
  organization_admin: 'Администратор на организация',
  coordinator: 'Координатор',
  instructor: 'Преподавател / треньор',
  finance: 'Финанси',
  viewer: 'Само преглед',
};

const permissionLabels: Record<string, string> = {
  create: 'Създаване',
  update: 'Редактиране',
  invite: 'Покани',
  finance: 'Финанси',
  view: 'Преглед',
};

const invitationStatusLabels: Record<string, string> = {
  pending: 'Очаква приемане',
  accepted: 'Приета',
  expired: 'Изтекла',
};

export function Dashboard() {
  const router = useRouter();
  const hasStartedLoading = useRef(false);
  const [data, setData] = useState<DashboardData>();
  const [error, setError] = useState<string>();
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [oneTimeToken, setOneTimeToken] = useState<string>();

  useEffect(() => {
    if (hasStartedLoading.current) {
      return;
    }

    hasStartedLoading.current = true;
    loadDashboard()
      .then(setData)
      .catch((reason: unknown) => {
        if (reason instanceof AuthenticationRequiredError) {
          router.replace('/login');
          return;
        }

        setError(reason instanceof Error ? reason.message : 'Не успяхме да заредим административното табло.');
      });
  }, [router]);

  async function handleInvitation(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!data) {
      return;
    }

    const invitationForm = event.currentTarget;
    setError(undefined);
    setOneTimeToken(undefined);
    setIsSubmitting(true);

    const form = new FormData(invitationForm);

    try {
      const result = await createInvitation(
        data.organization.id,
        String(form.get('email') ?? ''),
        String(form.get('role') ?? 'instructor'),
      );
      setData((current) => current ? {
        ...current,
        invitations: [result.invitation, ...current.invitations],
      } : current);
      setOneTimeToken(result.token);
      invitationForm.reset();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Поканата не беше създадена.');
    } finally {
      setIsSubmitting(false);
    }
  }

  if (!data && !error) {
    return <main className={styles.centerState}><p>Зареждане на администрацията…</p></main>;
  }

  if (!data) {
    return (
      <main className={styles.centerState}>
        <h1>Администрация</h1>
        <p role="alert">{error}</p>
        <button type="button" onClick={() => window.location.reload()}>Опитай отново</button>
      </main>
    );
  }

  return (
    <main className={styles.shell}>
      <aside className={styles.sidebar}>
        <div className={styles.brand}>
          <span className={styles.mark}>P</span>
          <span>Progressive Activities</span>
        </div>
        <nav aria-label="Административна навигация">
          <a className={styles.activeNav} href="#overview">Общ преглед</a>
          <a href="#team">Екип и покани</a>
          <span>Дейности <small>следващ етап</small></span>
          <span>Одит <small>Задача 6</small></span>
        </nav>
        <p className={styles.demoBadge}>Локална демо среда</p>
      </aside>

      <section className={styles.content}>
        <header className={styles.topbar}>
          <div>
            <p className={styles.eyebrow}>Активна организация</p>
            <h1>Администрация</h1>
          </div>
          <div className={styles.identity}>
            <strong>{data.user.name}</strong>
            <span>{data.user.email}</span>
          </div>
        </header>

        <section id="overview" className={styles.hero}>
          <div>
            <p className={styles.eyebrow}>Работно пространство</p>
            <h2>{data.organization.name}</h2>
            <p>Това табло показва реалните възможности, изградени до момента.</p>
          </div>
          <div className={styles.roleCard}>
            <span>Текуща роля</span>
            <strong>{roleLabels[data.organization.role] ?? data.organization.role}</strong>
          </div>
        </section>

        <section className={styles.grid} aria-label="Обобщение">
          <article className={styles.card}>
            <span className={styles.cardLabel}>Организации</span>
            <strong className={styles.metric}>{data.organizations.length}</strong>
            <p>Активни работни пространства за този профил</p>
          </article>
          <article className={styles.card}>
            <span className={styles.cardLabel}>Покани</span>
            <strong className={styles.metric}>{data.invitations.length}</strong>
            <p>Създадени покани в активната организация</p>
          </article>
          <article className={styles.card}>
            <span className={styles.cardLabel}>Сигурност</span>
            <strong className={styles.ready}>Tenant защита</strong>
            <p>Достъпът е ограничен до избраната организация</p>
          </article>
        </section>

        <section className={styles.card}>
          <div className={styles.sectionHeading}>
            <div>
              <p className={styles.eyebrow}>Разрешения</p>
              <h2>Какво може този профил</h2>
            </div>
          </div>
          <div className={styles.permissions}>
            {data.organization.permissions.map((permission) => (
              <span key={permission}>✓ {permissionLabels[permission] ?? permission}</span>
            ))}
          </div>
        </section>

        <section id="team" className={styles.teamGrid}>
          <article className={styles.card}>
            <div className={styles.sectionHeading}>
              <div>
                <p className={styles.eyebrow}>Екип</p>
                <h2>Нова покана</h2>
              </div>
            </div>
            <form className={styles.form} onSubmit={handleInvitation}>
              <label htmlFor="invitation-email">Имейл на нов член</label>
              <input id="invitation-email" name="email" type="email" required />
              <label htmlFor="invitation-role">Роля</label>
              <select id="invitation-role" name="role" defaultValue="instructor">
                <option value="organization_admin">Администратор</option>
                <option value="coordinator">Координатор</option>
                <option value="instructor">Преподавател / треньор</option>
                <option value="finance">Финанси</option>
                <option value="viewer">Само преглед</option>
              </select>
              <button type="submit" disabled={isSubmitting}>
                {isSubmitting ? 'Създаване…' : 'Изпрати покана'}
              </button>
            </form>
            {oneTimeToken ? (
              <div className={styles.tokenNotice}>
                <strong>Token за еднократно приемане</strong>
                <code>{oneTimeToken}</code>
                <p>В production той ще се изпраща чрез защитено съобщение и няма да се показва повторно.</p>
              </div>
            ) : null}
            {error ? <p className={styles.error} role="alert">{error}</p> : null}
          </article>

          <article className={styles.card}>
            <div className={styles.sectionHeading}>
              <div>
                <p className={styles.eyebrow}>Последни</p>
                <h2>Покани</h2>
              </div>
              <span>{data.invitations.length}</span>
            </div>
            {data.invitations.length === 0 ? (
              <p className={styles.empty}>Все още няма създадени покани.</p>
            ) : (
              <ul className={styles.invitationList}>
                {data.invitations.map((invitation: DashboardInvitation) => (
                  <li key={invitation.id}>
                    <div>
                      <strong>{invitation.email}</strong>
                      <span>{roleLabels[invitation.role] ?? invitation.role}</span>
                    </div>
                    <span className={`${styles.status} ${styles[invitation.status] ?? ''}`}>
                      {invitationStatusLabels[invitation.status] ?? invitation.status}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </article>
        </section>
      </section>
    </main>
  );
}
