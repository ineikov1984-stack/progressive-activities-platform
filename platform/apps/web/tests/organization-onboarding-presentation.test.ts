import { describe, expect, it } from 'vitest';
import { decisionRequiresGuidance, organizationSlugFromName } from '../src/features/organizations/operator/presentation';

describe('organization onboarding presentation', () => {
  it('creates a readable latin address from a Bulgarian organization name', () => {
    expect(organizationSlugFromName('Демо ателие № 1')).toBe('demo-atelie-1');
    expect(organizationSlugFromName('  Клуб „Знание“  ')).toBe('klub-znanie');
  });

  it('requires public guidance only for decisions that need action from the organization', () => {
    expect(decisionRequiresGuidance('applicant')).toBe(true);
    expect(decisionRequiresGuidance('restricted')).toBe(true);
    expect(decisionRequiresGuidance('suspended')).toBe(true);
    expect(decisionRequiresGuidance('approved')).toBe(false);
    expect(decisionRequiresGuidance('active')).toBe(false);
    expect(decisionRequiresGuidance('review')).toBe(false);
  });
});
