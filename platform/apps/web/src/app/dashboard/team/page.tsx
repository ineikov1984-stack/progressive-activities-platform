import type { Metadata } from 'next';
import { Suspense } from 'react';
import { TeamPage } from '@/features/team/team-page';

export const metadata: Metadata = { title: 'Екип | Progressive Activities' };
// The page reads ?state= (useSearchParams), which needs a Suspense boundary.
export default function Page() { return <Suspense><TeamPage /></Suspense>; }
