import type { Person, Reason, RequestSession, Session } from './api';
import type { ChangeWarning, ChangeResult, WarningKey } from '../schedule/changes';
import { applyChange } from '../schedule/change-api';
export type OfficeRequest = { id: string; version: number; created_at: string; requester: Person; reason: Reason; note: string | null; suggested: Person | null; sessions: (RequestSession & { version: number })[]; now: string };
export type OfficeList = { requests: OfficeRequest[]; now: string };
export type Candidate = Person & { section: 'activity' | 'others'; groups: string[]; state: 'free' | 'busy' | 'away'; return: boolean; has_access: boolean; email_confirmed: boolean; suggested: boolean; busy_in: number; warnings: ChangeWarning[] };
export type CandidateSession = Omit<Session,'session_id'> & { id: string; version: number; teachers: (Person & { role: string; replaces?: Person[] })[]; steps_down: Person | null };
export type Candidates = { sessions: CandidateSession[]; replaces?: Person; choose_replaced?: (Person & { state: 'teaching' | 'replaced' | 'released' })[]; can_go_without?: boolean; open_requests?: {id:string;version:number;session_ids:string[]}[]; candidates: Candidate[] };
export type AssignBody = { action: 'substitute'; sessions: {id:string;version:number}[]; replaces_profile_id: string; substitute_profile_id: string | null; request?: {id:string;version:number}; confirmed_warnings: WarningKey[] };
export class OfficeError extends Error { constructor(message: string, public status: number) { super(message); } }
async function read<T>(org:string,path:string,signal?:AbortSignal,body?:{version:number}):Promise<T> {
 const token=()=>document.cookie.split('; ').find(v=>v.startsWith('XSRF-TOKEN='))?.slice(11);
 if(body&&!token())await fetch('/backend/sanctum/csrf-cookie',{credentials:'include',signal});
 const csrf=token();
 const response=await fetch(`/backend/api/v1/schedule/${path}`,{method:body?'POST':'GET',credentials:'include',cache:'no-store',signal,headers:{Accept:'application/json','X-Organization-Id':org,...(body?{'Content-Type':'application/json'}:{}),...(csrf?{'X-XSRF-TOKEN':decodeURIComponent(csrf)}:{})},...(body?{body:JSON.stringify(body)}:{})});
 const data=await response.json().catch(()=>({}));
 if(!response.ok){const message=Object.values(data.errors??{}).flat()[0]??data.message;throw new OfficeError(typeof message==='string'&&/[а-яА-Я]/.test(message)?message:response.status===403?'Нямате право да назначавате заместници.':response.status===404?'Заявката не е намерена.':response.status===429?'Изчакайте минута и опитайте пак.':'Данните не се заредиха. Проверете връзката и опитайте пак.',response.status);}
 return data as T;
}
export const loadOfficeRequests=(org:string,signal?:AbortSignal)=>read<OfficeList>(org,'substitute-requests',signal);
export const loadOfficeRequest=(org:string,id:string,signal?:AbortSignal)=>read<OfficeRequest>(org,`substitute-requests/${encodeURIComponent(id)}`,signal);
export const declineOfficeRequest=(org:string,id:string,version:number)=>read<OfficeRequest>(org,`substitute-requests/${encodeURIComponent(id)}/decline`,undefined,{version});
export const loadCandidates=(org:string,ids:string[],replaces?:string,signal?:AbortSignal)=>{
 const query=new URLSearchParams();ids.forEach(id=>query.append('sessions[]',id));if(replaces)query.set('replaces',replaces);
 return read<Candidates>(org,`substitutes/candidates?${query}`,signal);
};
export const assignSubstitute=(org:string,body:AssignBody,key:string):Promise<ChangeResult>=>applyChange(org,body,key);
