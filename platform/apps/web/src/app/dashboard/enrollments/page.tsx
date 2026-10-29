import type { Metadata } from 'next';
import { Suspense } from 'react';
import { EnrollmentsPage } from '@/features/enrollment/enrollments-page';

export const metadata: Metadata = { title: 'Записвания | Progressive Activities' };
// The page reads ?view= (useSearchParams), which needs a Suspense boundary.
export default function Page() { return <Suspense><EnrollmentsPage /></Suspense>; }
