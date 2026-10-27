'use client';

import { useEffect, useState } from 'react';
import { loadOrganizationApplication, OrganizationApplication } from './api';
import styles from './status-card.module.css';

const statusCopy: Record<OrganizationApplication['status'], { label: string; detail: string; icon: string }> = {
  applicant: { label: 'Чернова', detail: 'Допълнете данните и изпратете заявлението за преглед.', icon: '✎' },
  review: { label: 'В процес на преглед', detail: 'Екипът на платформата проверява подадената информация.', icon: '◷' },
  approved: { label: 'Одобрена', detail: 'Организацията е одобрена и очаква активиране.', icon: '✓' },
  active: { label: 'Активна', detail: 'Организацията има пълен достъп до разрешените функции.', icon: '✓' },
  restricted: { label: 'Ограничен достъп', detail: 'Достъпът е само за преглед, докато се изпълнят посочените стъпки.', icon: '!' },
  suspended: { label: 'Временно спряна', detail: 'Свържете се с екипа на платформата за съдействие.', icon: '×' },
};

export function OrganizationStatusCard({ organizationId }: { organizationId: string }) {
  const [application, setApplication] = useState<OrganizationApplication>();
  const [error, setError] = useState<string>();

  useEffect(() => {
    let active = true;
    loadOrganizationApplication(organizationId)
      .then((result) => { if (active) setApplication(result); })
      .catch(() => { if (active) setError('Статусът на организацията не можа да бъде зареден.'); });
    return () => { active = false; };
  }, [organizationId]);

  if (error) return <p className={styles.error} role="status">{error}</p>;
  if (!application) return <section className={styles.card} aria-busy="true">Зареждане на статуса…</section>;

  const copy = statusCopy[application.status];
  return (
    <section className={`${styles.card} ${styles[application.status]}`} aria-labelledby="organization-status-title">
      <div className={styles.heading}>
        <span className={styles.icon} aria-hidden="true">{copy.icon}</span>
        <div>
          <p className={styles.eyebrow}>Статус на организацията</p>
          <h2 id="organization-status-title">{copy.label}</h2>
        </div>
        <span className={styles.version}>Версия {application.review_version}</span>
      </div>
      <p>{copy.detail}</p>
      {application.public_review_reason ? (
        <div className={styles.guidance}>
          <strong>Обяснение</strong>
          <p>{application.public_review_reason}</p>
        </div>
      ) : null}
      {application.next_action ? (
        <div className={styles.guidance}>
          <strong>Какво следва</strong>
          <p>{application.next_action}</p>
        </div>
      ) : null}
    </section>
  );
}
