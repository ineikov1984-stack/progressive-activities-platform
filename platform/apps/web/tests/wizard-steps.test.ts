import { describe, expect, it } from 'vitest';
import type { ActivityDraft } from '../src/features/catalog/admin/api';
import { completedSteps, contentBlocker, savedAgo, stepByNumber, wizardSteps } from '../src/features/catalog/admin/wizard/steps';

const activity = (changes: Partial<ActivityDraft> = {}): ActivityDraft => ({
  id: 'a', slug: 'a', status: 'draft', version: 1, title: 'Роботика', category: 'Наука и технологии',
  summary: 'Децата сглобяват и програмират роботи.', age_label: '7–10 г.', hero_image_path: null, sections: {}, offering: null, ...changes,
});

describe('wizard steps', () => {
  it('has nine numbered steps and falls back to the first', () => {
    expect(wizardSteps.map((step) => step.number)).toEqual([1, 2, 3, 4, 5, 6, 7, 8, 9]);
    expect(stepByNumber('4').id).toBe('media');
    expect(stepByNumber('99').id).toBe('basics');
    expect(stepByNumber(null).id).toBe('basics');
  });

  it('marks steps done from the activity and its groups', () => {
    const done = completedSteps(activity({ sections: { about: 'Разказ' } }), []);
    expect([...done].sort()).toEqual(['audience', 'basics', 'story']);
    expect(completedSteps(activity(), [{ id: 'g' } as never]).has('groups')).toBe(true);
  });

  it('explains in Bulgarian why content cannot be saved yet', () => {
    expect(contentBlocker({ title: ' ', category: 'x', summary: 'y', age_label: 'z', sections: {}, offering: null })).toMatch(/име/);
    expect(contentBlocker({ title: 'a', category: 'x', summary: 'y'.repeat(221), age_label: 'z', sections: {}, offering: null })).toMatch(/220/);
    expect(contentBlocker({ title: 'a', category: 'x', summary: 'y', age_label: 'z', sections: {}, offering: null })).toBeUndefined();
    expect(contentBlocker({ title: 'a', category: 'x', summary: '', age_label: '', sections: {}, offering: null })).toBeUndefined();
  });

  it('describes when the draft was saved', () => {
    expect(savedAgo(undefined, 0)).toBe('Чернова');
    expect(savedAgo(1000, 3000)).toBe('Запазено току-що');
    expect(savedAgo(0, 30_000)).toBe('Запазено преди 30 сек.');
    expect(savedAgo(0, 180_000)).toBe('Запазено преди 3 мин.');
  });
});

describe('sectionsPayload', () => {
  it('sends cleared sections as null and trims the rest', async () => {
    const { sectionsPayload } = await import('../src/features/catalog/admin/wizard/steps');
    expect(sectionsPayload({ about: '  Разказ ', benefits: ['Екип', ' '], methodology: '' })).toEqual({
      about: 'Разказ', benefits: ['Екип'], methodology: null, participation_terms: null, faq: null, gallery: null, instructor_intro: null,
    });
  });
});

describe('placeholders', () => {
  it('shows placeholders as empty fields and stores them back when left empty', async () => {
    const { contentFromActivity, storedBasics } = await import('../src/features/catalog/admin/wizard/steps');
    const content = contentFromActivity(activity({ summary: 'Добавете кратко описание на дейността.', age_label: 'Добавете възраст' }));
    expect(content.summary).toBe('');
    expect(content.age_label).toBe('');
    expect(storedBasics(content)).toMatchObject({ summary: 'Добавете кратко описание на дейността.', age_label: 'Добавете възраст' });
    expect(storedBasics({ ...content, summary: ' Текст ' }).summary).toBe('Текст');
  });
});
