'use client';

import Image from 'next/image';
import Link from 'next/link';
import { FormEvent, useEffect, useMemo, useRef, useState } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { useDashboardContext } from '@/features/dashboard/dashboard-context';
import { ActivityDraft, createActivity, loadCatalogWorkspace } from './api';
import { usePrivateMedia } from './private-image';
import { activityCategories, activityCompleteness, activityStatusLabel, formatEur, placeholderAge, placeholderSummary } from './presentation';
import styles from './activity-list.module.css';

type Filter = 'all' | 'published' | 'draft' | 'archived';

const filters: { id: Filter; label: string }[] = [
  { id: 'all', label: 'Всички' },
  { id: 'published', label: 'Публикувани' },
  { id: 'draft', label: 'Чернови' },
  { id: 'archived', label: 'Архив' },
];


const unitLabels: Record<string, string> = { month: '/ месец', session: '/ занимание', period: '/ период', package: '/ пакет' };

function priceLabel(activity: ActivityDraft): string | undefined {
  const options = activity.offering?.price_options ?? [];
  if (options.length === 0) return undefined;
  // A free trial shows only when there is no other price (docs/product/visits.md, т. 5.5).
  const cheapest = [...options].sort((a, b) => Number(a.amount_cents === 0) - Number(b.amount_cents === 0) || a.amount_cents - b.amount_cents)[0];
  if (cheapest.amount_cents === 0) return 'Безплатно';
  const amount = formatEur(cheapest.amount_cents);
  return `${options.length > 1 ? 'от ' : ''}${amount} ${unitLabels[cheapest.unit] ?? ''}`.trim();
}

function metaLine(activity: ActivityDraft): string {
  return [
    activity.category,
    activity.age_label && activity.age_label !== placeholderAge ? activity.age_label : undefined,
    priceLabel(activity),
  ].filter(Boolean).join(' · ');
}

function ActivityCard({ activity, organizationId, organizationSlug }: { activity: ActivityDraft; organizationId: string; organizationSlug: string }) {
  const completeness = activityCompleteness(activity);
  const isComplete = completeness.done === completeness.total;
  const cover = usePrivateMedia(organizationId, activity.hero_image_path);
  const editHref = `/dashboard/activities/${activity.id}`;
  const statusClass = activity.status === 'published' ? styles.badgeAvailable : activity.status === 'archived' ? styles.badgeMuted : styles.badgeDraft;
  const progressText = isComplete
    ? 'Всички стъпки са попълнени'
    : `${completeness.done} от ${completeness.total} стъпки${completeness.next ? ` · следва „${completeness.next.label}“` : ''}`;

  return (
    <article className={styles.card}>
      <div className={styles.cover} aria-hidden="true">
        {cover ? <Image src={cover} alt="" fill sizes="112px" unoptimized /> : <span>{activity.title.slice(0, 1).toUpperCase()}</span>}
      </div>
      <div className={styles.main}>
        <div className={styles.head}>
          <div className={styles.titleBlock}>
            <h2><Link href={editHref}>{activity.title}</Link></h2>
            <p className={styles.meta}>{metaLine(activity)}</p>
          </div>
          <span className={`${styles.badge} ${statusClass}`}>{activityStatusLabel(activity.status)}</span>
        </div>
        <div className={styles.progress}>
          <span className={styles.bar} role="progressbar" aria-label="Завършеност" aria-valuemin={0} aria-valuemax={completeness.total} aria-valuenow={completeness.done}>
            <span className={isComplete ? styles.barDone : styles.barPartial} style={{ width: `${Math.round((completeness.done / completeness.total) * 100)}%` }} />
          </span>
          <span>{progressText}</span>
        </div>
        <div className={styles.actions}>
          {activity.status === 'draft'
            ? <Link className={styles.primarySmall} href={editHref}>Продължи</Link>
            : <Link className={styles.secondarySmall} href={editHref}>Редактирай</Link>}
          {activity.status === 'published'
            ? <Link className={styles.quietSmall} href={`/o/${organizationSlug}/activities/${activity.slug}`}>Преглед като родител</Link>
            : null}
        </div>
      </div>
    </article>
  );
}

export function ActivityList() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const { organization } = useDashboardContext();
  const createInput = useRef<HTMLInputElement>(null);
  const [activities, setActivities] = useState<ActivityDraft[]>();
  const [filter, setFilter] = useState<Filter>('all');
  const [query, setQuery] = useState('');
  const [isCreating, setIsCreating] = useState(() => searchParams.get('new') === '1');
  const [title, setTitle] = useState('');
  const [category, setCategory] = useState<string>(activityCategories[0]);
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    let active = true;
    loadCatalogWorkspace(organization.id)
      .then((workspace) => { if (active) setActivities(workspace.activities); })
      .catch((reason: unknown) => { if (active) setError(reason instanceof Error ? reason.message : 'Дейностите не се заредиха.'); });
    return () => { active = false; };
  }, [organization.id]);

  useEffect(() => { if (isCreating) createInput.current?.focus(); }, [isCreating]);

  const counts = useMemo(() => ({
    all: activities?.length ?? 0,
    published: activities?.filter((item) => item.status === 'published').length ?? 0,
    draft: activities?.filter((item) => item.status === 'draft').length ?? 0,
    archived: activities?.filter((item) => item.status === 'archived').length ?? 0,
  }), [activities]);

  const visible = useMemo(() => {
    const needle = query.trim().toLocaleLowerCase('bg');
    return (activities ?? [])
      .filter((item) => filter === 'all' || item.status === filter)
      .filter((item) => !needle || item.title.toLocaleLowerCase('bg').includes(needle) || item.category.toLocaleLowerCase('bg').includes(needle));
  }, [activities, filter, query]);

  async function create(event: FormEvent) {
    event.preventDefault();
    setSaving(true);
    setError('');
    try {
      const activity = await createActivity(organization.id, { title: title.trim(), category, summary: placeholderSummary, age_label: placeholderAge });
      router.push(`/dashboard/activities/${activity.id}`);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Дейността не беше създадена.');
      setSaving(false);
    }
  }

  return (
    <div className={styles.page}>
      <header className={styles.header}>
        <div className={styles.heading}>
          <p className={styles.eyebrow}>Каталог на организацията</p>
          <h1>Дейности</h1>
          <p className={styles.muted}>Създавайте дейности, групи и цени. Родителите виждат само публикуваните.</p>
        </div>
        {!isCreating ? (
          <button type="button" className={styles.primary} onClick={() => setIsCreating(true)}>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
            Създай нова дейност
          </button>
        ) : null}
      </header>

      {isCreating ? (
        <form className={styles.create} onSubmit={create} aria-labelledby="create-title">
          <div className={styles.createHead}>
            <h2 id="create-title">Нова дейност</h2>
            <button type="button" className={styles.quietSmall} onClick={() => setIsCreating(false)}>Откажи</button>
          </div>
          <div className={styles.field}>
            <label htmlFor="new-activity">Име на дейността</label>
            <input id="new-activity" ref={createInput} value={title} onChange={(event) => setTitle(event.target.value)} placeholder="Например: Шахмат за начинаещи" required maxLength={120} />
            <span className={styles.hint}>Кратко и разбираемо за родител. Всичко може да се промени по-късно.</span>
          </div>
          <fieldset className={styles.field}>
            <legend>Категория</legend>
            <div className={styles.chips}>
              {activityCategories.map((item) => (
                <label key={item} className={`${styles.chip} ${category === item ? styles.chipOn : ''}`}>
                  <input type="radio" name="category" value={item} checked={category === item} onChange={() => setCategory(item)} />
                  {item}
                </label>
              ))}
            </div>
          </fieldset>
          <div className={styles.createFoot}>
            <span className={styles.hint}>След това ще попълните аудиторията, описанието, снимките, цените и групите.</span>
            <button className={styles.primary} disabled={saving || !title.trim()} aria-busy={saving}>{saving ? 'Създаване…' : 'Продължи към детайлите'}</button>
          </div>
        </form>
      ) : null}

      {error ? <p className={styles.error} role="alert">{error}</p> : null}

      <div className={styles.toolbar}>
        <div className={styles.tabs} role="group" aria-label="Филтър по статус">
          {filters.map((item) => (
            <button key={item.id} type="button" className={`${styles.tab} ${filter === item.id ? styles.tabOn : ''}`} aria-pressed={filter === item.id} onClick={() => setFilter(item.id)}>
              {item.label} <span>{counts[item.id]}</span>
            </button>
          ))}
        </div>
        <label className={styles.search}>
          <span className="sr-only">Търсене в дейностите</span>
          <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="M20 20l-3.5-3.5" /></svg>
          <input type="search" value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Търсене по име или категория" />
        </label>
      </div>

      <section aria-label="Дейности" aria-busy={activities === undefined} className={styles.grid}>
        {activities === undefined
          ? [0, 1, 2, 3].map((item) => <span className={styles.skeleton} key={item} />)
          : activities.length === 0
            ? (
              <div className={styles.empty}>
                <h2>Създайте първата си дейност</h2>
                <p>Въведете име и категория, а после ще ви преведем през останалите стъпки.</p>
                {!isCreating ? <button type="button" className={styles.primary} onClick={() => setIsCreating(true)}>Създай нова дейност</button> : null}
              </div>
            )
            : visible.length === 0
              ? <p className={styles.muted}>Няма дейности, които отговарят на избраното.</p>
              : visible.map((activity) => <ActivityCard key={activity.id} activity={activity} organizationId={organization.id} organizationSlug={organization.slug} />)}
      </section>
    </div>
  );
}
