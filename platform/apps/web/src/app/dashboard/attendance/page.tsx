import {Suspense} from 'react';
import {AttendanceWorkspace} from '@/features/attendance/workspace';
export const metadata={title:'Присъствия | Progressive Activities'};
export default function Page(){return <Suspense><AttendanceWorkspace/></Suspense>;}
