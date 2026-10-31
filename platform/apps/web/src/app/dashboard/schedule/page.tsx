import type { Metadata } from 'next';
import { Suspense } from 'react';
import { SchedulePage } from '@/features/schedule/schedule-page';

export const metadata: Metadata = { title: 'График | Progressive Activities' };
// The page reads ?view=, ?date= and the filters (useSearchParams), which needs a Suspense boundary.
export default function Page() { return <Suspense><SchedulePage /></Suspense>; }
