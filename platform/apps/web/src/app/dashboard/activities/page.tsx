import type { Metadata } from 'next';
import { Suspense } from 'react';
import { ActivityList } from '@/features/catalog/admin/activity-list';

export const metadata: Metadata = { title: 'Дейности | Progressive Activities' };

export default function ActivitiesPage() {
  return <Suspense><ActivityList /></Suspense>;
}
