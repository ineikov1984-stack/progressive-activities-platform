'use client';
import Link from 'next/link';
import { useEffect, useState } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { useDashboardContext } from '../dashboard/dashboard-context';
import { cellLabels, cellSigns, date, format } from './presentation';
import styles from './attendance.module.css';
import {loadMonth, type Month} from './api';

/** Teacher's monthly read view. Office correction controls are added in PR 8. */
export function AttendanceMonthView() {
  const {organization}=useDashboardContext(); const search=useSearchParams();
  return <MonthReader key={`${organization.id}:${search.toString()}`} organizationId={organization.id} query={search.toString()} />;
}
function MonthReader({organizationId,query}:{organizationId:string;query:string}) {
  const [result,setResult]=useState<{data?:Month;error?:string}>(); const [attempt,retry]=useState(0); const router=useRouter();
  useEffect(()=>{const c=new AbortController(); loadMonth(organizationId,query,c.signal).then((data)=>{if(!c.signal.aborted)setResult({data});}).catch((e:unknown)=>{if(!c.signal.aborted)setResult({error:e instanceof Error?e.message:'Месецът не се зареди.'});});return()=>c.abort();},[organizationId,query,attempt]);
  const data=result?.data;
  function choose(key:string,value:string) {const params=new URLSearchParams(query);params.set(key,value);if(data){if(!params.has('group'))params.set('group',data.group.id);if(!params.has('month'))params.set('month',data.month);}router.push(`/dashboard/attendance?${params.toString()}`);}
  return <div className={styles.page}><Link className={styles.link} href="/dashboard">← Днес</Link><h1>Присъствия</h1>{result?.error?<><p role="alert">{result.error}</p><button className={styles.button} onClick={()=>retry((n)=>n+1)}>Опитай пак</button></>:!data?<div className={styles.skeleton} aria-busy="true" aria-label="Зареждане на месеца"/>:<section className={styles.card}><h2>{data.group.label} · {format(`${data.month}-01T12:00:00Z`,{month:'long'})}</h2><div className={styles.monthFilters}><label className={styles.field}>Група<select value={data.group.id} onChange={(e)=>choose('group',e.target.value)}>{data.groups.map((g)=><option key={g.id} value={g.id}>{g.label}</option>)}</select></label><label className={styles.field}>Месец<input type="month" min="2020-01" value={data.month} onChange={(e)=>{if(e.target.value)choose('month',e.target.value);}}/></label></div>
  {data.sessions.length===0?<p>През този месец групата няма занимания.</p>:<div className={styles.tableScroll} role="region" aria-label="Присъствия за месеца" tabIndex={0}><table className={styles.table}><caption className="sr-only">{data.group.label} · {data.month}</caption><thead><tr><th scope="col">Дете</th>{data.sessions.map((s)=><th scope="col" key={s.id}>{date(s.starts_at)}{s.extra?<small> · доп.</small>:null}</th>)}<th scope="col">Присъствия</th><th scope="col">Отсъствия</th><th scope="col">%</th></tr></thead><tbody>{data.rows.map((r,index)=><tr key={`${r.child_id??r.name}-${r.kind}-${index}`}><th scope="row">{r.kind==='trial'?'Пробно · ':r.kind==='single'?'Еднократно · ':''}{r.name}</th>{data.sessions.map((s)=>{const cell=r.cells[s.id];return <td key={s.id}>{cell?<><span aria-hidden="true">{cellSigns[cell.state]}</span><span className="sr-only">{cellLabels[cell.state]}</span></>:null}</td>;})}<td>{r.present}</td><td>{r.absent}</td><td>{r.percent===null?'—':`${r.percent}${r.percent<50?' ⚠':''}`}</td></tr>)}</tbody><tfoot><tr><th scope="row">Присъстваха</th>{data.sessions.map((s)=><td key={s.id}>{s.present===null?'—':`${s.present}/${s.expected}`}</td>)}<td colSpan={3}/></tr></tfoot></table></div>}
  <p className={styles.muted}>✓ присъства · ✕ отсъства · пр. — предупредено отсъствие · пауза — не се очаква · ✓ п. — дошло по време на пауза · — отменено · н.пр. — не се проведе · ? — неотбелязано. В процента влизат само присъствията и отсъствията от минали занимания, на които детето е очаквано.</p><p><strong>Теми: </strong>{data.sessions.filter((s)=>s.topic).map((s)=>`${date(s.starts_at)} — ${s.topic}`).join(' · ')||'Няма записани теми.'}</p></section>}</div>;
}
