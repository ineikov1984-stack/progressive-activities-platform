import type { ActivityDraft, ActivityGroup, CatalogProfile, InstructorProfile, Location, PriceOption } from '../api';
import type { PublicActivityResponse, PublicPrice } from '../../public/api';
import { formatPublicPrice } from '../../public/presentation';

function prices(items: PriceOption[]): PublicPrice[] {
  return items.filter(item => item.is_available !== false && ['month','period','package','session'].includes(item.unit)).map(item => ({ ...item, unit: item.unit as PublicPrice['unit'], currency: 'EUR', label: item.amount_cents === 0 ? 'Безплатно' : formatPublicPrice({...item, unit:item.unit as PublicPrice['unit'], currency:'EUR'}) }));
}

/** Presentation only. No public endpoint, availability calculation or publishing of draft data. */
export function pagePreviewData(activity: ActivityDraft, groups: ActivityGroup[], locations: Location[], instructors: InstructorProfile[], profile: CatalogProfile | null, organizationName: string): PublicActivityResponse {
  const offering = activity.offering;
  const options = prices(offering?.price_options ?? []);
  const price = [...options].sort((a,b) => Number(a.amount_cents === 0)-Number(b.amount_cents === 0) || a.amount_cents-b.amount_cents)[0];
  const shownGroups = groups.filter(item => item.status !== 'archived');
  const instructorIds = new Set([offering?.lead_instructor_profile_id, ...shownGroups.flatMap(item => item.instructor_ids)]);
  return {
    organization: { slug: profile?.slug ?? '', public_name: profile?.public_name || organizationName, accent_color: profile?.accent_color },
    activity: {
      slug: activity.slug, title: activity.title.trim() || 'Име на дейността', category: activity.category, summary: activity.summary, age_label: activity.age_label,
      hero_image_url: activity.hero_image_path, price: price ? {...price, from: true} : null,
      availability: {status:'closed', remaining_places:null},
      sections: {...activity.sections, gallery: activity.sections.gallery?.map(image => ({url:image.path, alt:image.alt}))},
      terms: offering ? [{ title: offering.title, terms: offering.terms, cancellation_policy: offering.cancellation_policy }] : [],
      instructors: instructors.filter(item => item.is_public && instructorIds.has(item.id)).map(item => ({public_name:item.public_name, role_title:item.role_title, bio:item.bio, qualifications:item.qualifications, photo_url:item.photo_path})),
      groups: shownGroups.map(group => {
        const location = locations.find(item => item.id === group.location_id && item.is_public) ?? locations.find(item => item.id === offering?.location_id && item.is_public);
        return {id:group.id, name:group.name, age_label:group.age_label, capacity_min:group.capacity_min, schedule:group.schedule_pattern,
          availability:{status:'closed', remaining_places:null}, location:location ? {name:location.name, type:location.type, address:location.address, public_directions:location.public_directions} : null,
          prices: (() => { const own=prices(group.price_options); return [...own,...options.filter(item=>!own.some(other=>other.enrollment_mode===item.enrollment_mode))].filter(item=>group.enrollment_modes == null || group.enrollment_modes.includes(item.enrollment_mode ?? '')); })()};
      }),
    },
  };
}
