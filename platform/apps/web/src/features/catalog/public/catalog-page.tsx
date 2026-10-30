import Image from 'next/image';
import Link from 'next/link';
import type { CSSProperties } from 'react';
import type { PublicCatalog } from './api';
import { publicMediaUrl } from './api';
import { audienceLabel, cardPriceLabel, meaningfulText, publicBadges, safeAccent, summarizePublicSchedule } from './presentation';
import { PublicMedia } from './category-illustration';
import styles from './catalog.module.css';

export function CatalogPage({ catalog }: { catalog: PublicCatalog }) {
  const { organization, activities } = catalog;
  const accent = { '--catalog-accent': safeAccent(organization.accent_color) } as CSSProperties;
  return <main className={styles.publicShell} style={accent}>
    <header className={styles.catalogHeader}>
      <div className={styles.brandRow}>
        {publicMediaUrl(organization.logo_url) && <Image className={styles.logo} src={publicMediaUrl(organization.logo_url)!} alt="" width={52} height={52} unoptimized />}
        <span>{organization.public_name}</span>
      </div>
      <p className={styles.eyebrow}>СВЯТ ОТ ВЪЗМОЖНОСТИ</p>
      <h1>Открийте занимание, което ще вдъхнови детето ви</h1>
      {meaningfulText(organization.intro) && <p className={styles.lead}>{organization.intro!.trim()}</p>}
    </header>
    {activities.length === 0 ? <section className={styles.emptyState}><h2>Скоро ще има нови дейности</h2><p>Организацията подготвя своя каталог.</p></section> :
      <section className={styles.cardGrid} aria-label="Дейности">
        {activities.map((activity) => <article className={styles.activityCard} key={activity.slug}>
          <div className={styles.cardVisual}><PublicMedia url={activity.hero_image_url} title={activity.title} category={activity.category} /></div>
          <div className={styles.cardBody}>
            <div className={styles.badgeRow} data-status-badges>{publicBadges(activity).map((label, index) => <span className={index === 0 ? styles.statusBadge : styles.specialBadge} data-status={index === 0 ? activity.availability.status : undefined} key={label}>{label}</span>)}</div>
            <p className={styles.categoryLabel}>{activity.category}</p>
            <h2>{activity.title}</h2>
            {audienceLabel(activity) && <p className={styles.audience}>{audienceLabel(activity)}</p>}
            {meaningfulText(activity.summary) && <p className={styles.cardSummary}>{activity.summary.trim()}</p>}
            <div className={styles.cardPractical}><strong className={styles.cardPrice}>{cardPriceLabel(activity.price)}</strong><p>{summarizePublicSchedule(activity.schedule)}</p></div>
            <Link className={styles.textLink} href={activity.special_offer_url || `/o/${organization.slug}/activities/${activity.slug}`}>{activity.special_offer_url ? 'Към деня на отворените врати' : 'Повече за дейността'} <span aria-hidden="true">→</span></Link>
          </div>
        </article>)}
      </section>}
  </main>;
}
