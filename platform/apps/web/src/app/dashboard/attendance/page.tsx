import {Suspense} from 'react';
import {AttendanceMonthView} from '@/features/attendance/month-view';
export const metadata={title:'Присъствия | Progressive Activities'};
export default function Page(){return <Suspense><AttendanceMonthView/></Suspense>;}
