import Image from 'next/image';
import Link from 'next/link';
import type { CSSProperties } from 'react';
import type { PublicCatalog } from './api';
import { publicMediaUrl } from './api';
import { availabilityLabel, formatPublicPrice, summarizePublicSchedule } from './presentation';
import styles from './catalog.module.css';

export function CatalogPage({ catalog }: { catalog: PublicCatalog }) {
  const accent = { '--catalog-accent': catalog.organization.accent_color ?? '#216653' } as CSSProperties;

  return <main className={styles.publicShell} style={accent}>
    <header className={styles.catalogHeader}>
      <div className={styles.brandRow}>
        {catalog.organization.logo_url && <Image className={styles.logo} src={publicMediaUrl(catalog.organization.logo_url)!} alt="" width={64} height={64} unoptimized />}
        <span>{catalog.organization.public_name}</span>
      </div>
      <p className={styles.eyebrow}>КАТАЛОГ С ДЕЙНОСТИ</p>
      <h1>Открийте занимание, което вдъхновява</h1>
      {catalog.organization.intro && <p className={styles.lead}>{catalog.organization.intro}</p>}
    </header>

    {catalog.activities.length === 0 ? <section className={styles.emptyState}><h2>Скоро ще има нови дейности</h2><p>Организацията подготвя своя каталог.</p></section> :
      <section className={styles.cardGrid} aria-label="Дейности">
        {catalog.activities.map((activity) => <article className={styles.activityCard} key={activity.slug}>
          <div className={styles.cardVisual}>
            {activity.hero_image_url ? <Image src={publicMediaUrl(activity.hero_image_url)!} alt="" fill sizes="(max-width: 720px) 100vw, 33vw" unoptimized /> : <div className={styles.imagePlaceholder} aria-hidden="true">✦</div>}
            <span className={styles.categoryPill}>{activity.category}</span>
            {activity.price && <span className={styles.pricePill}>{formatPublicPrice(activity.price)}</span>}
          </div>
          <div className={styles.cardBody}>
            <div className={styles.factRow}><span>{activity.age_label}</span><span>{summarizePublicSchedule(activity.schedule)}</span></div>
            <span className={activity.availability.status === 'limited' ? styles.limitedBadge : styles.availableBadge}>{availabilityLabel(activity.availability)}</span>
            <h2>{activity.title}</h2>
            <p>{activity.summary}</p>
            <Link className={styles.textLink} href={`/o/${catalog.organization.slug}/activities/${activity.slug}`}>Научи повече <span aria-hidden="true">→</span></Link>
          </div>
        </article>)}
      </section>}
  </main>;
}
