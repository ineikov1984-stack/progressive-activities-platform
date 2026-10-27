import { notFound } from 'next/navigation';
import { CatalogPage } from '@/features/catalog/public/catalog-page';
import { loadPublicCatalog } from '@/features/catalog/public/api';

export default async function OrganizationCatalogPage({ params }: { params: Promise<{ organizationSlug: string }> }) {
  const { organizationSlug } = await params;
  const catalog = await loadPublicCatalog(organizationSlug);
  if (!catalog) notFound();
  return <CatalogPage catalog={catalog}/>;
}
