import { loadPublicOpenDay } from '@/features/catalog/public/api';
import { OpenDayPage, UnavailableOpenDay } from '@/features/catalog/public/open-day-page';

export default async function PublicOpenDayPage({ params }: { params: Promise<{ organizationSlug: string; campaignSlug: string }> }) {
  const { organizationSlug, campaignSlug } = await params;
  const response = await loadPublicOpenDay(organizationSlug, campaignSlug);
  return response ? <OpenDayPage response={response} /> : <UnavailableOpenDay organizationSlug={organizationSlug} />;
}
