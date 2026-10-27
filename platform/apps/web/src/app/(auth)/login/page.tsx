import type { Metadata } from 'next';
import Link from 'next/link';
import { LoginForm } from '@/features/auth/login-form';
import styles from './page.module.css';

export const metadata: Metadata = {
  title: 'Вход | Progressive Activities',
};

export default function LoginPage() {
  return (
    <main className={styles.page}>
      <section className={styles.intro} aria-labelledby="login-heading">
        <Link className={styles.brand} href="/" aria-label="Progressive Activities — начало">
          <span className={styles.mark} aria-hidden="true">P</span>
          <span>Progressive Activities</span>
        </Link>
        <div>
          <p className={styles.eyebrow}>Семейства · училища · партньори</p>
          <h1 id="login-heading">Вход в платформата</h1>
          <p className={styles.lead}>
            Управлявайте занимания, записвания и посещения от едно сигурно място.
          </p>
        </div>
        <p className={styles.privacy}>Личните данни се обработват с повишена защита и само за необходимите цели.</p>
      </section>

      <section className={styles.panel} aria-label="Форма за вход">
        <div className={styles.card}>
          <p className={styles.cardTitle}>Добре дошли</p>
          <p className={styles.cardCopy}>Използвайте имейла, с който е създаден профилът ви.</p>
          <LoginForm />
          <p className={styles.help}>Нямате достъп? Обърнете се към администратора на вашата организация.</p>
        </div>
      </section>
    </main>
  );
}
