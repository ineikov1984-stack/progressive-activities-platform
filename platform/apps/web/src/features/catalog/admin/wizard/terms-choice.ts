import type { BillingKind, PaymentTermsSet, PaymentTermsWorkspace, ReadyPreset, Rules, SelfService } from '../../../payment-terms/api';
import { describeTerms, type TermsLine } from '../../../payment-terms/rules';

/**
 * Which payment terms an activity uses (docs/product/payment-policies.md, т. 7.2; board "Дейност · Условия").
 * The organization's terms apply unless one set is chosen for the activity; it applies to the prices paid the
 * same way. Visits keep their offers' own terms until step 2d-3, so they are not chosen here.
 */
const MODE_KINDS: Record<string, BillingKind> = { monthly: 'monthly', full_period: 'full_period', package: 'package' };
const READY: ReadyPreset[] = ['flexible', 'standard', 'strict'];

/** The ways of paying among the activity's prices that take a set of terms, in a fixed order. */
export function kindsOf(modes: string[]): BillingKind[] {
  const kinds = new Set(modes.map((mode) => MODE_KINDS[mode]).filter((kind): kind is BillingKind => kind !== undefined));
  return (['monthly', 'full_period', 'package'] as BillingKind[]).filter((kind) => kinds.has(kind));
}

export type TermsSummary = { kind: BillingKind; kindLabel: string; name: string; presetLabel: string; lines: TermsLine[]; violations: string[] };

function summary(workspace: PaymentTermsWorkspace, kind: BillingKind, name: string, presetLabel: string, rules: Rules, violations: Record<string, string>): TermsSummary {
  return {
    kind,
    kindLabel: workspace.billing_kinds.find((info) => info.value === kind)?.label ?? kind,
    name,
    presetLabel,
    // The first three rules say enough on this screen; the settings show all of them.
    lines: describeTerms(kind, rules).slice(0, 3),
    violations: workspace.in_marketplace ? [...new Set(Object.values(violations))] : [],
  };
}

/** The organization's terms for one way of paying: its default set, or the ready-made "Стандартни". */
export function organizationTerms(workspace: PaymentTermsWorkspace, kind: BillingKind): TermsSummary {
  const set = workspace.payment_terms.find((item) => item.billing_kind === kind && item.is_default && item.status === 'active' && item.current_version);
  if (set?.current_version) return summary(workspace, kind, set.name, set.preset_label, set.current_version.rules, set.marketplace_violations);
  const standard = workspace.billing_kinds.find((info) => info.value === kind)?.presets.standard;

  return summary(workspace, kind, 'Условията на организацията', standard?.label ?? 'Стандартни', standard?.rules ?? {}, {});
}

export type TermsOption =
  | { value: string; kind: BillingKind; label: string; set: PaymentTermsSet }
  | { value: string; kind: BillingKind; label: string; preset: ReadyPreset; rules: Rules; selfService: SelfService };

/**
 * What can be chosen instead: the organization's other sets for these ways of paying, then the ready-made sets
 * it has not saved yet (choosing one saves it as a set, which only the owner and the administrator may do).
 */
export function otherOptions(workspace: PaymentTermsWorkspace, kinds: BillingKind[]): TermsOption[] {
  return kinds.flatMap((kind) => {
    const active = workspace.payment_terms.filter((set) => set.billing_kind === kind && set.status === 'active' && set.current_version);
    const defaultSet = active.find((set) => set.is_default);
    const sets: TermsOption[] = active.filter((set) => !set.is_default).map((set) => ({
      value: set.id, kind, set, label: set.name === set.preset_label ? set.name : `${set.name} (${set.preset_label})`,
    }));
    const info = workspace.billing_kinds.find((item) => item.value === kind);
    const ready: TermsOption[] = READY
      // Not the organization's own terms again, and not a set it already has.
      .filter((preset) => (defaultSet ? defaultSet.preset !== preset : preset !== 'standard') && !active.some((set) => set.preset === preset))
      .flatMap((preset) => info ? [{ value: `ready:${kind}:${preset}`, kind, label: info.presets[preset].label, preset, rules: info.presets[preset].rules, selfService: info.presets[preset].self_service }] : []);
    return [...sets, ...ready];
  });
}

export function optionSummary(workspace: PaymentTermsWorkspace, option: TermsOption): TermsSummary {
  return 'set' in option
    ? summary(workspace, option.kind, option.set.name, option.set.preset_label, option.set.current_version?.rules ?? {}, option.set.marketplace_violations)
    : summary(workspace, option.kind, option.label, option.label, option.rules, {});
}

/**
 * A chosen set that no longer fits — archived, removed or for a way of paying the activity no longer has —
 * would make every save fail; the activity falls back to the organization's terms instead.
 */
export function stillFits(workspace: PaymentTermsWorkspace, chosenId: string, kinds: BillingKind[]): boolean {
  return workspace.payment_terms.some((set) => set.id === chosenId && set.status === 'active' && kinds.includes(set.billing_kind));
}

/** A name for a ready-made set that is free among the organization's sets for that way of paying. */
export function freeName(workspace: PaymentTermsWorkspace, kind: BillingKind, wanted: string): string {
  const taken = new Set(workspace.payment_terms.filter((set) => set.billing_kind === kind && set.status === 'active').map((set) => set.name.toLocaleLowerCase('bg')));
  if (!taken.has(wanted.toLocaleLowerCase('bg'))) return wanted;
  for (let number = 2; ; number += 1) {
    const name = `${wanted} ${number}`;
    if (!taken.has(name.toLocaleLowerCase('bg'))) return name;
  }
}
