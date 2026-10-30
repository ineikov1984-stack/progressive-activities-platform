import type { Metadata } from 'next';
import { PaymentTermsPage } from '@/features/payment-terms/payment-terms-page';

export const metadata: Metadata = { title: 'Такси, отсъствия и отказ | Progressive Activities' };
export default function Page() { return <PaymentTermsPage />; }
