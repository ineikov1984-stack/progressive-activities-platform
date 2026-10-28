'use client';

import Link from 'next/link';
import { FormEvent, useEffect, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import { AuthenticationRequiredError, loadDashboard } from '@/features/dashboard/api';
import { ActivityDraft, createActivity, loadCatalogWorkspace } from './api';
import { activityStatusLabel, slugPreview } from './presentation';
import styles from './activity-editor.module.css';

export function ActivityList() {
  const router = useRouter(); const started = useRef(false);
  const [organizationId, setOrganizationId] = useState(''); const [activities, setActivities] = useState<ActivityDraft[]>();
  const [title, setTitle] = useState(''); const [error, setError] = useState(''); const [saving, setSaving] = useState(false);
  useEffect(() => { if (started.current) return; started.current = true; loadDashboard().then(async (dashboard) => {
    setOrganizationId(dashboard.organization.id); setActivities((await loadCatalogWorkspace(dashboard.organization.id)).activities);
  }).catch((reason: unknown) => { if (reason instanceof AuthenticationRequiredError) router.replace('/login'); else setError(reason instanceof Error ? reason.message : 'Каталогът не беше зареден.'); }); }, [router]);
  async function create(event: FormEvent) { event.preventDefault(); if (!organizationId) return; setSaving(true); setError(''); try { const activity = await createActivity(organizationId, { title, category: 'Друго', summary: 'Добавете кратко описание на дейността.', age_label: 'Добавете възраст' }); router.push(`/dashboard/activities/${activity.id}`); } catch (reason) { setError(reason instanceof Error ? reason.message : 'Дейността не беше създадена.'); setSaving(false); } }
  return <main className={styles.page}>
    <nav className={styles.panel} aria-label="Управление на каталога"><Link href="/dashboard/open-days">Кампании „Отворени врати“</Link></nav>
    <header className={styles.pageHeader}><div><p className={styles.eyebrow}>Каталог на организацията</p><h1>Дейности</h1><p>Създавайте красиви страници, групи, графици и цени в евро.</p></div><Link className={styles.secondaryButton} href="/dashboard">Към таблото</Link></header>
    <section className={styles.createCard}><h2>Нова дейност</h2><form className={styles.inlineForm} onSubmit={create}><label htmlFor="new-activity">Име на дейността</label><input id="new-activity" value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Например: Шах за начинаещи" required/><span className={styles.slugHint}>Адрес: {slugPreview(title) || 'ще се създаде автоматично'}</span><button disabled={saving}>{saving ? 'Създаване…' : 'Създай чернова'}</button></form></section>
    {error ? <p className={styles.error} role="alert">{error}</p> : null}
    <section className={styles.activityGrid} aria-label="Създадени дейности">{activities === undefined ? <p>Зареждане…</p> : activities.length === 0 ? <div className={styles.empty}><h2>Първата ви дейност започва оттук</h2><p>Въведете име по-горе, а после ще ви преведем през останалите стъпки.</p></div> : activities.map((activity) => <article className={styles.activityCard} key={activity.id}><span className={`${styles.status} ${activity.status === 'published' ? styles.success : ''}`}>{activityStatusLabel(activity.status)}</span><h2>{activity.title}</h2><p>{activity.summary}</p><dl><div><dt>Категория</dt><dd>{activity.category}</dd></div><div><dt>Възраст</dt><dd>{activity.age_label}</dd></div></dl><Link href={`/dashboard/activities/${activity.id}`}>Редактирай →</Link></article>)}</section>
  </main>;
}
