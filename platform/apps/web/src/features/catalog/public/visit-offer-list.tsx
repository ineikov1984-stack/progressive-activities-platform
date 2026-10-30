'use client';

import { useId, useState } from 'react';
import { describeTerms } from '../../payment-terms/rules';
import type { PublicGroup, PublicLocation, PublicVisitOffer } from './api';
import { availabilityLabel, enrollmentCta, meaningfulText, navigationAction, offerAction, visitPriceLabel, visitSchedule } from './presentation';
import styles from './catalog.module.css';

export function PublicLocationDetails({ location }: { location?: PublicLocation | null }) {
  const navigation = navigationAction(location);
  return <div className={styles.locationBlock}>
    <small>ЛОКАЦИЯ</small><strong>{meaningfulText(location?.name) || 'Все още не е обявено'}</strong>
    {location?.type === 'physical' && meaningfulText(location.address) && <span>{location.address!.trim()}</span>}
    {meaningfulText(location?.public_directions) && <span>{location!.public_directions!.trim()}</span>}
    {navigation && <a href={navigation.href} target="_blank" rel="noreferrer">{navigation.label} ↗</a>}
  </div>;
}

/** "Такси, отсъствия и отказ" of a visit: cancellation and no-show first, the rest on request (т. 8a.10). */
function VisitTerms({ terms, same }: { terms: PublicVisitOffer['payment_terms']; same: boolean }) {
  if (same) return <p className={styles.sameTerms}>Същите такси, отсъствия и отказ.</p>;
  const lines = describeTerms(terms.billing_kind, terms.rules);
  return <div className={styles.visitTerms}>
    <strong>Такси, отсъствия и отказ</strong>
    <dl>{lines.slice(0, 2).map((line) => <div key={line.title}><dt>{line.title}:</dt> <dd>{line.text}</dd></div>)}</dl>
    {lines.length > 2 && <details><summary>Пълни условия</summary><dl>{lines.slice(2).map((line) => <div key={line.title}><dt>{line.title}:</dt> <dd>{line.text}</dd></div>)}</dl></details>}
  </div>;
}

export function VisitOfferList({ offers, groups, headingLevel = 3 }: { offers: PublicVisitOffer[]; groups?: PublicGroup[]; headingLevel?: 3 | 4 }) {
  const [selectedKey, setSelectedKey] = useState<string | null>(null);
  const name = useId();
  const multiple = offers.length > 1;
  const selected = offers.find((offer) => `${offer.visit_offer_id}:${offer.session_id}` === selectedKey);
  const Heading = headingLevel === 4 ? 'h4' : 'h3';
  return <div>
    {multiple && <p className={styles.chooserHint}>Изберете конкретно посещение, за да прегледате възможността за участие. Изборът не запазва място.</p>}
    <fieldset className={styles.offerFieldset}>
      <legend className={styles.srOnly}>{multiple ? 'Изберете посещение' : 'Подробности за посещението'}</legend>
      <div className={styles.offerGrid}>{offers.map((offer, index) => {
        const key = `${offer.visit_offer_id}:${offer.session_id}`;
        // A location belongs to this offer only through a real public session relation.
        const group = groups?.find((item) => item.sessions?.some((session) => session.id === offer.session_id));
        const state = offerAction(offer.action);
        return <article className={styles.offerCard} key={key} data-selected={selectedKey === key}>
          <div className={styles.offerOverview}>
          <span className={styles.statusBadge} data-status={offer.availability.status}>{availabilityLabel({ status: offer.availability.status, remaining_places: offer.availability.remaining_offer_places })}</span>
          <Heading>{offer.title}</Heading>
          {meaningfulText(offer.audience) && <p className={styles.audience}>{offer.audience!.trim()}</p>}
          <p className={styles.offerSchedule}><time dateTime={offer.starts_at}>{visitSchedule(offer)}</time></p>
          {meaningfulText(offer.description) && <p>{offer.description!.trim()}</p>}
          <strong className={styles.offerPrice}>{visitPriceLabel(offer)}</strong>
          {meaningfulText(offer.fee_reason) && <p>{offer.fee_reason!.trim()}</p>}
          </div>
          <div className={styles.offerDetails}>
          <dl className={styles.offerTerms}>
            <div><dt>Записване</dt><dd>До {offer.booking_deadline_hours} часа преди началото</dd></div>
          </dl>
          <VisitTerms terms={offer.payment_terms} same={index > 0 && offers[index - 1].payment_terms.version === offer.payment_terms.version} />
          {offer.type === 'trial' && <p className={styles.trialRule}>Пробното е веднъж на дете за тази дейност в учебната година.</p>}
          {groups && <PublicLocationDetails location={group?.location} />}
          <p className={styles.actionState}>{state.label}</p>
          {multiple && offer.action !== 'closed' && <label className={styles.offerChoice}>
            <input type="radio" name={name} value={key} checked={selectedKey === key} onChange={() => setSelectedKey(key)} />
            <span>Избирам: {offer.title}<small>{visitSchedule(offer)}</small></span>
          </label>}
          </div>
        </article>;
      })}</div>
    </fieldset>
    {selected && <div role="status" className={styles.selectionNotice}><strong>Избрано посещение: {selected.title}</strong><p>{visitSchedule(selected)} · {visitPriceLabel(selected)}</p><p>{offerAction(selected.action).label}. {enrollmentCta}. Не е изпратена заявка и не е запазено място.</p></div>}
  </div>;
}
