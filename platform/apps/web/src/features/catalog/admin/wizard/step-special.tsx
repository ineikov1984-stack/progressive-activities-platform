'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { type ActivityGroup, loadVisitOffers, type VisitOffer } from '../api';
import { formatEur } from '../presentation';
import { Icon, icons } from './ui';
import styles from './wizard.module.css';

type Props = {
  organizationId: string;
  activityId: string;
  groups: ActivityGroup[];
  /** The activity has a trial or a single visit price (step 6). */
  hasVisits: boolean;
};

const statuses: Record<VisitOffer['status'], string> = { draft: 'Чернова', published: 'Публикувано', closed: 'Приключено', cancelled: 'Отменено' };
const kinds: Record<VisitOffer['type'], string> = { trial: 'пробно', single: 'еднократно', open_day: 'отворени врати' };

function priceFact(offer: VisitOffer): string | undefined {
  // A trial or single visit is priced from step 6 (docs/product/visits.md, т. 8a); an open day has its own fee.
  if (offer.type !== 'open_day') return offer.price ? (offer.price.amount_cents === 0 ? 'безплатно' : formatEur(offer.price.amount_cents)) : 'без цена от стъпка 6';
  return offer.fee_type === 'free' ? 'безплатно' : `${offer.fee_type === 'from_price' ? 'от ' : ''}${formatEur(offer.amount_cents)}`;
}

export function offerFacts(offer: VisitOffer, groups: ActivityGroup[]): string {
  const price = priceFact(offer);
  const group = groups.find((item) => item.id === offer.group_id)?.name;
  const places = `${offer.visit_limit} ${offer.visit_limit === 1 ? 'място' : 'места'}`;
  return [group, kinds[offer.type], price, places].filter(Boolean).join(' · ');
}

/** Step 8 "Специални посещения" (docs/product/visits.md, т. 8): optional, for the special cases only. */
export function StepSpecial({ organizationId, activityId, groups, hasVisits }: Props) {
  const [offers, setOffers] = useState<VisitOffer[] | undefined>();
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    let alive = true;
    loadVisitOffers(organizationId)
      .then((all) => { if (alive) setOffers(all.filter((offer) => offer.activity_id === activityId)); })
      .catch(() => { if (alive) setFailed(true); });
    return () => { alive = false; };
  }, [organizationId, activityId]);

  return (
    <div className={styles.special}>
      {hasVisits ? (
        <div className={styles.specialReady}>
          <Icon path={icons.check} />
          <span><strong>Пробните и еднократните посещения вече работят</strong>Цените са от стъпка 6, а датите — от графика на групите, които ги приемат (стъпка 7). Тази стъпка е само за особени случаи — можете да я пропуснете.</span>
        </div>
      ) : (
        <div className={styles.tip}><Icon path={icons.info} /><span>За пробни или еднократни посещения във всяко занятие добавете „Пробно занимание“ или „Еднократно посещение“ в стъпка 6. Тази стъпка е само за особени случаи.</span></div>
      )}

      <div className={styles.specialGrid}>
        <section className={styles.specialCard} aria-labelledby="special-open-day">
          <h3 id="special-open-day">Ден на отворените врати</h3>
          <p>Избрани дати, в които всеки може да дойде — безплатно или с цена. Получавате отделна страница, която да споделите.</p>
          <p className={styles.muted}>Например: „Отворени врати — 14 и 15 ноември“.</p>
          <Link className={styles.secondary} href="/dashboard/open-days">Създай ден на отворените врати</Link>
        </section>
        <section className={styles.specialCard} aria-labelledby="special-dates">
          <h3 id="special-dates">Посещения само на определени дати</h3>
          <p>Когато не искате посещения във всяко занятие — например само в първата седмица на месеца или само в една група.</p>
          <p className={styles.muted}>Изберете датите, местата и цената.</p>
          <Link className={styles.secondary} href={`/dashboard/activities/${activityId}/visit-offers`}>Избери дати за посещения</Link>
        </section>
      </div>

      <section className={styles.special} aria-labelledby="special-made">
        <h3 id="special-made" className={styles.subheading}>Вече създадени</h3>
        {failed ? <p className={styles.inlineError} role="alert">Не успяхме да заредим създадените посещения. Опитайте отново по-късно.</p>
          : offers === undefined ? <div className={styles.madeSkeleton} aria-hidden="true" />
            : offers.length === 0 ? <p className={styles.muted}>Още няма специални посещения за тази дейност.</p>
              : (
                <ul className={styles.madeList}>
                  {offers.map((offer) => (
                    <li key={offer.id} className={styles.madeRow}>
                      <strong>{offer.title}</strong>
                      <span>{offerFacts(offer, groups)}</span>
                      <span className={`${styles.badge} ${offer.status === 'published' ? styles.badgeOn : ''}`}>{statuses[offer.status]}</span>
                      <Link href={`/dashboard/activities/${activityId}/visit-offers?offer=${encodeURIComponent(offer.id)}`} aria-label={`Промени „${offer.title}“`}>Промени</Link>
                    </li>
                  ))}
                </ul>
              )}
      </section>
    </div>
  );
}
