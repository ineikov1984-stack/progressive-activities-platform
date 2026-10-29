import type { ActivityDraft, ActivityGroup, ActivitySections } from '../api';
import { checkOffering, type OfferingDraft, offeringFromActivity } from './offering';
import { activityCompleteness, compactOptionalSections, placeholderAge, placeholderSummary } from '../presentation';

export type WizardStepId = 'basics' | 'audience' | 'story' | 'media' | 'people' | 'price' | 'groups' | 'trials' | 'review';

export type WizardStep = {
  id: WizardStepId;
  number: number;
  title: string;
  note: string;
  optional?: boolean;
};

export const wizardSteps: WizardStep[] = [
  { id: 'basics', number: 1, title: 'Основна информация', note: 'Име, категория, описание' },
  { id: 'audience', number: 2, title: 'Аудитория', note: 'Възраст или класове' },
  { id: 'story', number: 3, title: 'Представяне и ползи', note: 'Какво правят децата и какво печелят' },
  { id: 'media', number: 4, title: 'Снимки и галерия', note: 'Основна снимка и галерия' },
  { id: 'people', number: 5, title: 'Места и преподаватели', note: 'Къде и с кого' },
  { id: 'price', number: 6, title: 'Цени и записване', note: 'Цени и условия' },
  { id: 'groups', number: 7, title: 'Групи и график', note: 'Дни, часове, места' },
  { id: 'trials', number: 8, title: 'Пробни и еднократни', note: 'По избор', optional: true },
  { id: 'review', number: 9, title: 'Преглед и публикуване', note: 'Проверка преди родителите' },
];

export function stepByNumber(value: string | null | undefined): WizardStep {
  const number = Number(value);
  return wizardSteps.find((step) => step.number === number) ?? wizardSteps[0];
}

/** Which steps are complete, from the same rules as the activity list progress bar. */
export function completedSteps(activity: ActivityDraft, groups: ActivityGroup[]): Set<WizardStepId> {
  const done = new Set<WizardStepId>();
  for (const step of activityCompleteness(activity).steps) {
    if (!step.done) continue;
    if (step.id === 'basics' || step.id === 'audience' || step.id === 'story' || step.id === 'media' || step.id === 'people' || step.id === 'price') done.add(step.id);
  }
  if (groups.length > 0) done.add('groups');
  if (activity.status === 'published') done.add('review');
  return done;
}

/** Content edited in the wizard. Everything else on the activity stays as it is. */
export type WizardContent = Pick<ActivityDraft, 'title' | 'category' | 'summary' | 'age_label'> & { sections: ActivitySections; offering: OfferingDraft | null };

/** Placeholder texts from a new activity are shown as empty fields, never as real text. */
export function contentFromActivity(activity: ActivityDraft): WizardContent {
  return {
    title: activity.title,
    category: activity.category,
    summary: activity.summary === placeholderSummary ? '' : activity.summary,
    age_label: activity.age_label === placeholderAge ? '' : activity.age_label,
    sections: activity.sections ?? {},
    offering: offeringFromActivity(activity),
  };
}

/** The activity as it will be once the current edits are saved (for progress and preview). */
export function draftActivity(activity: ActivityDraft, content: WizardContent): ActivityDraft {
  const offering = activity.offering && content.offering
    ? (() => {
      const { payload } = checkOffering(content.offering);
      return { ...activity.offering, ...payload, price_options: payload.price_options ?? activity.offering.price_options };
    })()
    : activity.offering;
  return { ...activity, title: content.title, category: content.category, summary: content.summary, age_label: content.age_label, sections: content.sections, offering };
}

/** What is stored: empty required texts go back to the placeholders the API accepts. */
export function storedBasics(content: WizardContent): Pick<WizardContent, 'title' | 'category' | 'summary' | 'age_label'> {
  return {
    title: content.title.trim(),
    category: content.category,
    summary: content.summary.trim() || placeholderSummary,
    age_label: content.age_label.trim() || placeholderAge,
  };
}

export const summaryLimit = 220;

/** Why the content cannot be saved yet, in plain Bulgarian; undefined when it can. */
export function contentBlocker(content: WizardContent): string | undefined {
  if (!content.title.trim()) return 'Въведете име на дейността, за да запазим промените.';
  if (content.summary.length > summaryLimit) return `Краткото описание е над ${summaryLimit} знака.`;
  return undefined;
}

export const agePresets = ['3–5 г.', '5–7 г.', '7–10 г.', '10–14 г.', '1.–4. клас', '5.–7. клас', '8.–12. клас'] as const;

export function savedAgo(savedAt: number | undefined, now: number): string {
  if (savedAt === undefined) return 'Чернова';
  const seconds = Math.max(0, Math.round((now - savedAt) / 1000));
  if (seconds < 5) return 'Запазено току-що';
  if (seconds < 60) return `Запазено преди ${seconds} сек.`;
  const minutes = Math.round(seconds / 60);
  return `Запазено преди ${minutes} мин.`;
}

/**
 * The API only changes the section keys it receives, so a cleared section must be sent
 * as null explicitly; otherwise the old text would silently stay.
 */
export function sectionsPayload(sections: ActivitySections): ActivitySections {
  const compact = compactOptionalSections(sections);
  return {
    about: compact.about ?? null,
    benefits: compact.benefits ?? null,
    methodology: compact.methodology ?? null,
    participation_terms: compact.participation_terms ?? null,
    faq: compact.faq ?? null,
    gallery: compact.gallery ?? null,
    instructor_intro: compact.instructor_intro ?? null,
  };
}
