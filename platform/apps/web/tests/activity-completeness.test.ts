import { describe, expect, it } from 'vitest';
import type { ActivityDraft } from '../src/features/catalog/admin/api';
import { activityCompleteness } from '../src/features/catalog/admin/presentation';

const base: ActivityDraft = {
  id: 'a1', slug: 'shah', status: 'draft', version: 1, title: 'Шах', category: 'Друго',
  summary: 'Добавете кратко описание на дейността.', age_label: 'Добавете възраст',
  hero_image_path: null, sections: {}, offering: null,
};

describe('activity completeness', () => {
  it('treats the quick-create placeholders as missing and points to the first gap', () => {
    const result = activityCompleteness(base);
    expect(result).toMatchObject({ done: 0, total: 7 });
    expect(result.next?.label).toBe('Основна информация');
  });

  it('counts a fully described activity as complete', () => {
    const result = activityCompleteness({
      ...base,
      category: 'Наука и технологии',
      summary: 'Шахматни занимания за логическо мислене.',
      age_label: '6–8 г.',
      hero_image_path: 'catalog/x.png',
      sections: { about: 'Подробно описание.' },
      offering: {
        id: 'o1', version: 1, location_id: 'l1', lead_instructor_profile_id: 'i1', terms: 'Общи условия',
        price_options: [{ enrollment_mode: 'subscription', unit: 'month', amount_cents: 4500 }],
      },
    });
    expect(result).toMatchObject({ done: 7, total: 7 });
    expect(result.next).toBeUndefined();
  });

  it('names the next missing step after the finished ones', () => {
    const result = activityCompleteness({ ...base, category: 'Музика', summary: 'Вокална група.', age_label: '9–13 г.' });
    expect(result.done).toBe(2);
    expect(result.next?.label).toBe('Представяне и ползи');
  });
});
