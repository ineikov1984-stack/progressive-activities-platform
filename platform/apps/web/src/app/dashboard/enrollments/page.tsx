import type { Metadata } from 'next';
import { EnrollmentsPage } from '@/features/enrollment/enrollments-page';

export const metadata: Metadata = { title: 'Записвания | Progressive Activities' };
export default function Page() { return <EnrollmentsPage />; }
