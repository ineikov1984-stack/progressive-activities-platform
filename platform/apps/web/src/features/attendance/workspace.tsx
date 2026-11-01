'use client';
import Link from 'next/link';
import {useEffect,useRef,useState} from 'react';
import {useSearchParams} from 'next/navigation';
import {useDashboardContext} from '../dashboard/dashboard-context';
import {AttendanceMonthView} from './month-view';
import {AttendanceSessionButton} from './session-dialog';
import {AttendanceError,canSeePending,loadPending,loadToday,type Pending,type Today,type TodaySession} from './api';
import {attendanceView,clock,date,format} from './presentation';
import styles from './attendance.module.css';

export function AttendanceWorkspace(){
 const {organization}=useDashboardContext();const search=useSearchParams();
 const daily=canSeePending(organization.access?.abilities)||organization.role==='instructor';
 const view=attendanceView(search.toString(),daily);
 const month=new URLSearchParams(search.toString());month.set('view','month');
 return <div className={styles.page}><h1>Присъствия</h1><p className={styles.muted}>Отбелязване и преглед на групите</p>{daily?<nav className={styles.viewTabs} aria-label="Изглед на присъствията"><Link aria-current={view==='pending'?'page':undefined} href="/dashboard/attendance?view=pending">За отбелязване</Link><Link aria-current={view==='month'?'page':undefined} href={`/dashboard/attendance?${month}`}>Месечен преглед</Link></nav>:null}{view==='month'?<AttendanceMonthView/>:<DailyAttendance key={organization.id} organizationId={organization.id} office={canSeePending(organization.access?.abilities)}/>}</div>;
}
function DailyAttendance({organizationId,office}:{organizationId:string;office:boolean}){
 const [result,setResult]=useState<{today?:Today;pending?:Pending;error?:string}>();const [attempt,retry]=useState(0);
 const closedSession=useRef<string|null>(null);const headingRef=useRef<HTMLHeadingElement>(null);
 useEffect(()=>{if(result?.today && closedSession.current){if(!document.querySelector(`[data-attendance-session="${CSS.escape(closedSession.current)}"]`))headingRef.current?.focus();closedSession.current=null;}},[result]);
 useEffect(()=>{const c=new AbortController();const read=()=>Promise.all([loadToday(organizationId,c.signal),office?loadPending(organizationId,c.signal):Promise.resolve(undefined)]).then(([today,pending])=>{if(!c.signal.aborted)setResult({today,pending});}).catch((e:unknown)=>{if(!c.signal.aborted)setResult({error:e instanceof AttendanceError?e.message:'Заниманията не се заредиха. Опитайте пак.'});});read();const timer=setInterval(read,30000);return()=>{c.abort();clearInterval(timer);};},[organizationId,office,attempt]);
 if(result?.error)return <section className={styles.error}><p role="alert">{result.error}</p><button className={styles.button} onClick={()=>retry(n=>n+1)}>Опитай пак</button></section>;
 if(!result?.today)return <div className={styles.skeleton} aria-busy="true" aria-label="Зареждане на заниманията"/>;
 const today=result.today;const todayKey=format(today.now,{year:'numeric',month:'2-digit',day:'2-digit'});
 const sameDay=(value:string)=>format(value,{year:'numeric',month:'2-digit',day:'2-digit'})===todayKey;
 const officeToday=(result.pending?.sessions??[]).filter(s=>sameDay(s.starts_at)&&!today.sessions.some(own=>own.id===s.id));
 const earlier=office?(result.pending?.sessions??[]).filter(s=>!sameDay(s.starts_at)):today.pending;
 const refresh=(sessionId:string)=>{closedSession.current=sessionId;retry(n=>n+1);};
 return <><h2 ref={headingRef} tabIndex={-1} className={styles.sectionHeading}>Днес · {date(today.now)}</h2>{!today.sessions.length&&!officeToday.length?<div className={styles.empty}><h3>Няма занимания за отбелязване днес</h3><p>Проверете предишните занимания или отворете графика.</p><Link className={styles.button} href="/dashboard/schedule">Отвори графика</Link></div>:null}{today.sessions.filter(s=>s.counts.unmarked>0&&s.state!=='cancelled'&&s.state!=='not_held').map(s=><DailyCard now={Date.parse(today.now)} key={s.id} session={s} onClosed={()=>refresh(s.id)}/>)}{officeToday.map(s=><PendingCard key={s.id} session={s} onClosed={()=>refresh(s.id)}/>)}
 {earlier.length?<><h2 className={styles.sectionHeading}>По-рано · чакат отбелязване</h2>{earlier.map(s=>'counts' in s?<DailyCard now={Date.parse(today.now)} key={s.id} session={s} onClosed={()=>refresh(s.id)}/>:<PendingCard key={s.id} session={s} onClosed={()=>refresh(s.id)}/> )}</>:null}{(result.pending?.total??0)>20?<p className={styles.muted}>и още {result.pending!.total-20} занимания · показани са най-старите</p>:null}
 {today.sessions.some(s=>s.counts.unmarked===0||s.state==='cancelled'||s.state==='not_held')?<details className={styles.extras}><summary>Отбелязани и непроведени занимания</summary>{today.sessions.filter(s=>s.counts.unmarked===0||s.state==='cancelled'||s.state==='not_held').map(s=><DailyCard now={Date.parse(today.now)} key={s.id} session={s} onClosed={()=>refresh(s.id)}/>)}</details>:null}</>;
}
function DailyCard({session:s,onClosed,now}:{session:TodaySession;onClosed:()=>void;now:number}){
 const closed=s.state==='cancelled'||s.state==='not_held';const complete=s.counts.unmarked===0;
 return <article className={styles.dailyCard}><div className={styles.dailyInfo}><strong className={styles.dailyTime}>{clock(s.starts_at)}<small>{clock(s.ends_at)}</small></strong><div><h3>{s.activity} · {s.group}</h3><p className={styles.muted}>{date(s.starts_at)}{s.location?` · ${s.location}`:''}</p><span className={`${styles.badge} ${closed?'':complete?styles.present:styles.warning}`}>{s.state==='cancelled'?'Отменено':s.state==='not_held'?'Не се проведе':complete?'✓ Всички са отбелязани':`${s.counts.unmarked} неотбелязани`}</span></div></div>{Date.parse(s.opens_at)>now&&!closed?<p className={styles.muted}>Отбелязването започва в {clock(s.opens_at)}</p>:<AttendanceSessionButton sessionId={s.id} className={!closed&&!complete&&s.can_mark?styles.primary:styles.button} onClosed={onClosed}>{!closed&&!complete&&s.can_mark?'Отбележи присъствия':'Прегледай присъствията'}</AttendanceSessionButton>}</article>;
}
function PendingCard({session:s,onClosed}:{session:Pending['sessions'][number];onClosed:()=>void}){
 return <article className={styles.dailyCard}><div className={styles.dailyInfo}><strong className={styles.dailyTime}>{clock(s.starts_at)}</strong><div><h3>{s.activity} · {s.group}</h3><p className={styles.muted}>{date(s.starts_at)} · {s.teachers}</p><span className={`${styles.badge} ${styles.warning}`}>{s.unmarked} неотбелязани{s.late?' · с причина след срока':''}</span></div></div><AttendanceSessionButton sessionId={s.id} onClosed={onClosed}>Отбележи присъствия</AttendanceSessionButton></article>;
}
