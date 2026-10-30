import type { Metadata } from 'next';
import { PaymentTermsPage } from '@/features/payment-terms/payment-terms-page';

export const metadata: Metadata = { title: 'Условия за плащане | Progressive Activities' };
export default function Page() { return <PaymentTermsPage />; }
