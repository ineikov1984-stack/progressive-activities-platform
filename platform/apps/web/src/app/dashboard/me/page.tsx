import type { Metadata } from 'next';
import { MyProfile } from '@/features/me/my-profile';

export const metadata: Metadata = { title: 'Моят профил | Progressive Activities' };
export default function MyProfilePage() {
  return <MyProfile />;
}
