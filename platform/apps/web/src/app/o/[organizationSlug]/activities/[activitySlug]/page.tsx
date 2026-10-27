import { notFound } from 'next/navigation';
import { ActivityPage } from '@/features/catalog/public/activity-page';
import { loadPublicActivity } from '@/features/catalog/public/api';

export default async function PublicActivityPage({ params }: { params: Promise<{ organizationSlug: string; activitySlug: string }> }) {
  const { organizationSlug, activitySlug } = await params;
  const response = await loadPublicActivity(organizationSlug, activitySlug);
  if (!response) notFound();
  return <ActivityPage response={response}/>;
}
