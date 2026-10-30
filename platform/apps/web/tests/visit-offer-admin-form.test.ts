import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { VisitOfferForm } from '../src/features/catalog/admin/visit-offer-editor';
import { newVisitOfferDraft } from '../src/features/catalog/admin/presentation';
import type { OpenDayCampaign, PriceOption } from '../src/features/catalog/admin/api';

const render = (step: number, errors = {}) => renderToStaticMarkup(createElement(VisitOfferForm, {
  draft: { ...newVisitOfferDraft('activity-1'), type: 'open_day', fee_type: 'paid', description: 'Въведено описание' }, groups: [], campaigns: [], activityTitle: 'Шахмат', step, errors, busy: false, blocked: false,
  status: 'draft', onChange: () => {}, onStep: () => {}, onSave: () => {}, onPublish: () => {},
}));
describe('visit offer editor form', () => {
  it.each(['type', 'fee_type', 'booking_deadline_hours', 'waitlist_enabled'])('renders an accessible inline protected-field error for %s', (key) => {
    const html = renderToStaticMarkup(createElement(VisitOfferForm, {
      draft: { ...newVisitOfferDraft('activity-1'), type: 'open_day' }, groups: [], campaigns: [], activityTitle: 'Шахмат', step: 4, errors: { [key]: 'Потвърдените посещения не позволяват тази промяна.' }, busy: false, blocked: false,
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
  it('renders five accessible steps and keeps optional guidance visible after typing', () => {
    const html = render(0);
    expect(html.match(/aria-current="step"/g)).toHaveLength(1);
    expect(html.match(/aria-controls="visit-step-\d"/g)).toHaveLength(5);
    expect(html).toContain('Стъпка 1 от 5');
    for (const retired of ['visit-field-cancellation', 'visit-field-terms_version', 'visit-field-trial_limit', 'visit-field-single_visit', 'visit-field-unlimited', 'visit-field-no_show']) expect(html).not.toContain(retired);
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
    const html = render(4, { session_ids: 'Има конфликт в графика.', fee_reason: 'Обяснете таксата.' });
    expect(html).toContain('role="alert"');
    expect(html).toContain('Има конфликт в графика.');
    expect(html).toContain('id="error-fee_reason"');
    expect(html).toMatch(/disabled=""[^>]*>Публикувай предложението/);
    expect(html).toContain('Запази черновата');
  });
  it('shows the step-6 price and the visit terms of a trial read only, with a link back to step 6', () => {
    const price: PriceOption = { enrollment_mode: 'trial', unit: 'session', amount_cents: 0, is_available: true };
    const terms = { kind: 'visit' as const, kindLabel: 'Посещение', name: 'Стандартни', presetLabel: 'Стандартни', own: true, violations: [], lines: [{ title: 'Отказ', text: 'Поне 24 часа преди — не плащате.' }, { title: 'Неявяване', text: 'Плаща се цялата сума.' }] };
    const html = renderToStaticMarkup(createElement(VisitOfferForm, {
      draft: newVisitOfferDraft('activity-1'), groups: [], campaigns: [], activityTitle: 'Шахмат', step: 3, errors: {}, busy: false, blocked: false,
      status: 'draft', price, terms, onChange: () => {}, onStep: () => {}, onSave: () => {}, onPublish: () => {},
    }));
    expect(html).toContain('Безплатно');
    expect(html).toContain('От стъпка 6 „Цени и записване“.');
    expect(html).toContain('href="/dashboard/activities/activity-1?step=6"');
    expect(html).toContain('Условията на организацията — Стандартни');
    expect(html).toContain('Плаща се цялата сума.');
    expect(html).toContain('Пробното е веднъж на дете за тази дейност');
    expect(html).not.toContain('id="visit-field-fee_type"');
    expect(html).not.toContain('id="visit-field-amount_cents"');
  });
  it('names the missing step-6 price of a single visit and still shows the open-day fee fields', () => {
    const missing = renderToStaticMarkup(createElement(VisitOfferForm, {
      draft: { ...newVisitOfferDraft('activity-1'), type: 'single' }, groups: [], campaigns: [], activityTitle: 'Шахмат', step: 3, errors: {}, busy: false, blocked: false,
      status: 'draft', terms: null, onChange: () => {}, onStep: () => {}, onSave: () => {}, onPublish: () => {},
    }));
    expect(missing).toContain('Добавете цена „Еднократно посещение“ в стъпка 6 „Цени и записване“.');
    expect(missing).toContain('Условията не се заредиха');
    const openDay = render(3);
    expect(openDay).toContain('id="visit-field-fee_type"');
    expect(openDay).toContain('Важат, ако денят е с цена.');
  });
});
