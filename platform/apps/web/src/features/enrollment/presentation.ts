import { formatEur } from '@/features/catalog/admin/presentation';
import type { StaffApplication } from './api';

export const scopeLabels: Record<string, string> = {
  full_period: 'Записване за периода',
  single: 'Еднократно посещение',
  trial: 'Пробно занимание',
};

const unitLabels: Record<string, string> = { month: 'на месец', session: 'за занимание', period: 'за периода', package: 'за пакета' };

export function priceText(price: StaffApplication['price']): string {
  if (price.amount_cents === 0) return 'Безплатно';
  return `${formatEur(price.amount_cents)} ${unitLabels[price.unit] ?? ''}`.trim();
}

const dateFormat = new Intl.DateTimeFormat('bg-BG', { day: 'numeric', month: 'long', timeZone: 'Europe/Sofia' });
const dateTimeFormat = new Intl.DateTimeFormat('bg-BG', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Sofia' });

export function dateText(value: string | null | undefined): string {
  return value ? dateFormat.format(new Date(value)) : '';
}

export function dateTimeText(value: string | null | undefined): string {
  return value ? dateTimeFormat.format(new Date(value)) : '';
}

/** "1 свободно място", "Няма свободни места", "Без ограничение". */
export function placesText(remaining: number | null): string {
  if (remaining === null) return 'Без ограничение на местата';
  if (remaining <= 0) return 'Няма свободни места';
  return remaining === 1 ? '1 свободно място' : `${remaining} свободни места`;
}
