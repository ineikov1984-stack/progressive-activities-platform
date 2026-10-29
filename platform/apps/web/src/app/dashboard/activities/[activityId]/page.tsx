import type { Metadata } from 'next';
import { Suspense } from 'react';
import { ActivityWizard } from '@/features/catalog/admin/wizard/activity-wizard';

export const metadata: Metadata = { title: 'Дейност | Progressive Activities' };
export default async function ActivityPage({ params }: { params: Promise<{ activityId: string }> }) {
  const { activityId } = await params;
  return <Suspense><ActivityWizard activityId={activityId} /></Suspense>;
}
