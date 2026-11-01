import type { ScheduleSession } from '../schedule/api';
import { sofiaDay } from '../schedule/presentation';
import type { Candidate, Candidates, OfficeRequest } from './office-api';
import { dateLabel } from './presentation';
export function urgency(starts:string,now:string):string {
 const days=Math.round((Date.parse(sofiaDay(starts))-Date.parse(sofiaDay(now)))/86400000);
 return days<=0?'днес':days===1?'утре':`след ${days} дни`;
}
export function requestDetail(request:OfficeRequest):string {
 const sessions=request.sessions;if(!sessions.length)return 'Заявката е приключена.';
 const first=sessions[0];if(sessions.length===1)return `${dateLabel(first.starts_at)} · ${first.activity} · ${first.group}`;
 const groups=[...new Set(sessions.map(s=>`${s.activity} · ${s.group}`))];
 return `${sessions.length} занимания · ${dateLabel(first.starts_at)} – ${dateLabel(sessions.at(-1)!.starts_at)} · ${groups[0]}${groups.length>1?` и още ${groups.length-1} ${groups.length===2?'група':'групи'}`:''}`;
}
export function candidateText(candidate:Candidate,total:number):string {
 if(candidate.return)return `Върни заниманието на ${candidate.name}`;
 const warning=candidate.warnings.map(w=>w.message).join(' ');
 return warning?`⚠ ${total>1?`В ${candidate.busy_in} от ${total}: `:''}${warning}`:`✓ Няма друго занимание${candidate.groups.length?` · ${candidate.groups.join(' · ')}`:''}`;
}
export function commonOriginals(sessions:Pick<ScheduleSession,'instructors'>[]):string[] {
 let ids:string[]|undefined;
 for(const s of sessions){const originals=s.instructors.flatMap(t=>t.replaces?.length?t.replaces.map(p=>p.profile_id):[t.profile_id]);ids=ids===undefined?originals:ids.filter(id=>originals.includes(id));}
 return [...new Set(ids??[])];
}
export function withoutText(data:Candidates):string {
 const names=[...new Set(data.sessions.flatMap(s=>s.teachers.filter(t=>t.profile_id!==s.steps_down?.profile_id).map(t=>t.name)))];
 return `Без заместник${names.length?` — води ${names.length===1?'само ':''}${names.join(', ')}`:''}`;
}
export function closedRequestText(request:OfficeRequest):string {
 if(request.sessions.every(s=>s.state==='withdrawn'))return 'Заявката е оттеглена.';
 return request.sessions.map(s=>s.state==='assigned'?`Решено: ${s.now.teaching?'Заниманието отново е на подалия':s.now.substitute?.name??'Без заместник'}${s.decided_by?` · ${s.decided_by.name}`:''}`:s.state==='declined'?`Заявката не е одобрена${s.decided_by?` · ${s.decided_by.name}`:''}.`:s.state==='expired'?'Заявката е приключена — заниманието е променено или вече е започнало.':'').filter(Boolean).join(' ');
}
