import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { VisitOfferForm } from '../src/features/catalog/admin/visit-offer-editor';
import { newVisitOfferDraft } from '../src/features/catalog/admin/presentation';
import type { OpenDayCampaign } from '../src/features/catalog/admin/api';

const render = (step: number, errors = {}) => renderToStaticMarkup(createElement(VisitOfferForm, {
  draft: { ...newVisitOfferDraft('activity-1'), fee_type: 'paid', description: 'Въведено описание' }, groups: [], campaigns: [], activityTitle: 'Шахмат', step, errors, busy: false, blocked: false,
  status: 'draft', onChange: () => {}, onStep: () => {}, onSave: () => {}, onPublish: () => {},
}));
describe('visit offer editor form', () => {
  it.each(['type', 'fee_type', 'cancellation_outcome', 'waitlist_enabled', 'unlimited_single_visits'])('renders an accessible inline protected-field error for %s', (key) => {
    const html = renderToStaticMarkup(createElement(VisitOfferForm, {
      draft: { ...newVisitOfferDraft('activity-1'), type: 'single' }, groups: [], campaigns: [], activityTitle: 'Шахмат', step: 5, errors: { [key]: 'Потвърдените посещения не позволяват тази промяна.' }, busy: false, blocked: false,
      status: 'published', onChange: () => {}, onStep: () => {}, onSave: () => {}, onPublish: () => {},
    }));
    expect(html).toContain(`id="visit-field-${key}"`);
    expect(html).toContain(`aria-describedby="error-${key}"`);
    expect(html).toContain(`id="error-${key}"`);
  });
  it('locks the membership selector when the current campaign is cancelled', () => {
    const campaign: OpenDayCampaign = { id: 'cancelled-1', title: 'Отменена кампания', slug: 'cancelled', description: null, starts_on: null, ends_on: null, image_path: null, status: 'cancelled', version: 2, published_at: null, visit_offers: [] };
    const html = renderToStaticMarkup(createElement(VisitOfferForm, {
      draft: { ...newVisitOfferDraft('activity-1'), campaign_id: campaign.id }, groups: [], campaigns: [campaign], activityTitle: 'Шахмат', step: 0, errors: {}, busy: false, blocked: false,
      status: 'draft', onChange: () => {}, onStep: () => {}, onSave: () => {}, onPublish: () => {},
    }));
    expect(html).toMatch(/<select[^>]*disabled=""[^>]*id="visit-field-campaign_id"/);
    expect(html).toContain('Кампанията е отменена');
    expect(html).toContain('не може да се премества или отделя');
  });
  it('renders six accessible steps and keeps optional guidance visible after typing', () => {
    const html = render(0);
    expect(html.match(/aria-current="step"/g)).toHaveLength(1);
    expect(html.match(/aria-controls="visit-step-\d"/g)).toHaveLength(6);
    expect(html).toContain('Въведено описание');
    expect(html).toContain('Например: Децата ще изпробват');
    expect(html).toMatch(/disabled=""[^>]*>Генерирай с AI/);
    expect(html).toContain('AI помощта още не е включена');
  });
  it('shows real empty-state guidance instead of invented session choices', () => {
    const html = render(1);
    expect(html).toContain('Няма създадени групи');
    expect(html).toContain('график');
  });
  it('renders field-linked blocking errors and prevents publishing invalid drafts', () => {
    const html = render(5, { session_ids: 'Има конфликт в графика.', fee_reason: 'Обяснете таксата.' });
    expect(html).toContain('role="alert"');
    expect(html).toContain('Има конфликт в графика.');
    expect(html).toContain('id="error-fee_reason"');
    expect(html).toMatch(/disabled=""[^>]*>Публикувай предложението/);
    expect(html).toContain('Запази черновата');
  });
});
