'use client';

import { FormEvent, useEffect, useState } from 'react';
import {
  createOrganizationApplicant,
  loadOperatorApplications,
  OrganizationApplication,
  reviewOrganizationApplication,
  StaleApplicationError,
} from '../application/api';
import styles from './operator-console.module.css';

const labels: Record<OrganizationApplication['status'], string> = {
  applicant: 'Чернова', review: 'За преглед', approved: 'Одобрена', active: 'Активна',
  restricted: 'Ограничена', suspended: 'Спряна',
};

const decisions: Partial<Record<OrganizationApplication['status'], Array<{ value: OrganizationApplication['status']; label: string }>>> = {
  applicant: [{ value: 'review', label: 'Приеми за преглед' }],
  review: [{ value: 'approved', label: 'Одобри' }, { value: 'applicant', label: 'Върни за корекции' }, { value: 'suspended', label: 'Спри' }],
  approved: [{ value: 'active', label: 'Активирай' }, { value: 'suspended', label: 'Спри' }],
  active: [{ value: 'restricted', label: 'Ограничи' }, { value: 'suspended', label: 'Спри' }],
  restricted: [{ value: 'active', label: 'Възстанови' }, { value: 'suspended', label: 'Спри' }],
  suspended: [{ value: 'review', label: 'Отвори отново' }],
};

export function OperatorConsole() {
  const [applications, setApplications] = useState<OrganizationApplication[]>([]);
  const [selectedId, setSelectedId] = useState<string>();
  const [error, setError] = useState<string>();
  const [notice, setNotice] = useState<string>();
  const [ownerToken, setOwnerToken] = useState<string>();
  const [loading, setLoading] = useState(true);
  const selected = applications.find((item) => item.id === selectedId) ?? applications[0];

  async function refresh() {
    const result = await loadOperatorApplications();
    setApplications(result);
    setSelectedId((current) => current && result.some((item) => item.id === current) ? current : result[0]?.id);
  }

  useEffect(() => {
    loadOperatorApplications()
      .then((result) => {
        setApplications(result);
        setSelectedId(result[0]?.id);
      })
      .catch((reason: unknown) => setError(reason instanceof Error ? reason.message : 'Опашката не можа да бъде заредена.'))
      .finally(() => setLoading(false));
  }, []);

  async function createApplicant(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const formElement = event.currentTarget;
    const form = new FormData(formElement);
    setError(undefined); setNotice(undefined); setOwnerToken(undefined);
    try {
      const result = await createOrganizationApplicant(
        String(form.get('name') ?? ''), String(form.get('slug') ?? ''), String(form.get('owner_email') ?? ''),
      );
      setApplications((current) => [result.application, ...current]);
      setSelectedId(result.application.id);
      setOwnerToken(result.ownerInvitationToken);
      setNotice('Кандидатурата е създадена. Предайте еднократния код по защитен канал.');
      formElement.reset();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Кандидатурата не беше създадена.');
    }
  }

  async function decide(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!selected) return;
    const form = new FormData(event.currentTarget);
    const toStatus = String(form.get('to_status')) as OrganizationApplication['status'];
    if (!window.confirm(`Потвърждавате промяна на статуса на „${selected.name}“ на „${labels[toStatus]}“?`)) return;
    setError(undefined); setNotice(undefined);
    try {
      const updated = await reviewOrganizationApplication(selected.id, {
        reviewVersion: selected.review_version,
        toStatus,
        publicReason: String(form.get('public_reason') ?? '') || undefined,
        nextAction: String(form.get('next_action') ?? '') || undefined,
        internalNote: String(form.get('internal_note') ?? '') || undefined,
      });
      setApplications((current) => current.map((item) => item.id === updated.id ? updated : item));
      setNotice(`Статусът е променен на „${labels[updated.status]}“.`);
    } catch (reason) {
      if (reason instanceof StaleApplicationError) {
        await refresh();
        setError('Друг администратор вече е променил кандидатурата. Показваме актуалните данни.');
      } else {
        setError(reason instanceof Error ? reason.message : 'Решението не беше записано.');
      }
    }
  }

  return (
    <main className={styles.shell}>
      <header className={styles.header}>
        <div><p>Платформена администрация</p><h1>Кандидатури на организации</h1></div>
        <a href="/dashboard">← Към основното табло</a>
      </header>
      {error ? <p className={styles.error} role="alert">{error}</p> : null}
      {notice ? <p className={styles.notice} role="status">{notice}</p> : null}
      <section className={styles.layout}>
        <aside className={styles.queue} aria-label="Опашка за разглеждане">
          <div className={styles.queueHeading}><h2>Опашка</h2><span>{applications.length}</span></div>
          {loading ? <p>Зареждане…</p> : applications.length === 0 ? <p>Няма кандидатури.</p> : (
            <ul>{applications.map((application) => (
              <li key={application.id}>
                <button type="button" className={selected?.id === application.id ? styles.selected : ''} onClick={() => setSelectedId(application.id)}>
                  <strong>{application.name}</strong><span>{labels[application.status]} · версия {application.review_version}</span>
                </button>
              </li>
            ))}</ul>
          )}
        </aside>

        <section className={styles.workspace}>
          {selected ? (
            <article className={styles.reviewCard}>
              <div className={styles.titleRow}><div><p>Избрана организация</p><h2>{selected.name}</h2></div><span>{labels[selected.status]}</span></div>
              <dl>
                <div><dt>Тип</dt><dd>{selected.organization_type || 'Не е посочен'}</dd></div>
                <div><dt>Регистрационен номер</dt><dd>{selected.registration_identifier || 'Не е посочен'}</dd></div>
                <div><dt>Контакт</dt><dd>{selected.public_contact_email || 'Не е посочен'}</dd></div>
              </dl>
              <form onSubmit={decide} className={styles.form}>
                <label htmlFor="to-status">Решение</label>
                <select id="to-status" name="to_status" required>{(decisions[selected.status] ?? []).map((decision) => <option key={decision.value} value={decision.value}>{decision.label}</option>)}</select>
                <label htmlFor="public-reason">Публично обяснение</label>
                <textarea id="public-reason" name="public_reason" rows={3} placeholder="Вижда се от организацията при корекция, ограничаване или спиране." />
                <label htmlFor="next-action">Какво трябва да направи организацията</label>
                <textarea id="next-action" name="next_action" rows={3} />
                <label htmlFor="internal-note">Вътрешна бележка</label>
                <textarea id="internal-note" name="internal_note" rows={3} aria-describedby="private-note-help" />
                <small id="private-note-help">Вижда се само от платформените администратори.</small>
                <button type="submit">Запиши решение</button>
              </form>
            </article>
          ) : <div className={styles.empty}><h2>Изберете кандидатура</h2><p>Тук ще видите данните и възможните решения.</p></div>}

          <article className={styles.createCard}>
            <h2>Нова кандидатура</h2>
            <form onSubmit={createApplicant} className={styles.form}>
              <label htmlFor="new-name">Име на организацията</label><input id="new-name" name="name" required />
              <label htmlFor="new-slug">Адрес в системата</label><input id="new-slug" name="slug" pattern="[A-Za-z0-9_-]+" required />
              <label htmlFor="owner-email">Имейл на първия собственик</label><input id="owner-email" name="owner_email" type="email" required />
              <button type="submit">Създай и генерирай покана</button>
            </form>
            {ownerToken ? <div className={styles.token}><strong>Еднократен код</strong><code>{ownerToken}</code><p>Кодът няма да бъде показан отново.</p></div> : null}
          </article>
        </section>
      </section>
    </main>
  );
}
