'use client';
import { useEffect, useRef, useState } from 'react';
import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { useDashboardContext } from '../dashboard/dashboard-context';
import { initials } from '../team/presentation';
import { AssignmentDialog } from './assignment-dialog';
import { loadOfficeRequests, OfficeError, type OfficeList } from './office-api';
import { requestDetail, urgency } from './office-presentation';
import styles from './substitute.module.css';
export function OfficeSubstituteTasks({onCount}:{onCount:(count:number)=>void}){
 const {organization}=useDashboardContext();const router=useRouter();const path=usePathname();const query=useSearchParams();
 const requestId=query.get('substitute');const previousRequest=useRef(requestId);const opener=useRef<HTMLButtonElement|null>(null);const pushed=useRef(false);const decided=useRef(false);
 const [result,setResult]=useState<{data?:OfficeList;error?:string}>();const [attempt,retry]=useState(0);const [all,setAll]=useState(false);const [notice,setNotice]=useState('');
 useEffect(()=>{
  const controller=new AbortController();let generation=0;
  const read=()=>{const current=++generation;loadOfficeRequests(organization.id,controller.signal).then(data=>{if(!controller.signal.aborted&&current===generation){setResult({data});onCount(data.requests.length);}}).catch((e:unknown)=>{if(!controller.signal.aborted&&current===generation)setResult({error:e instanceof OfficeError?e.message:'Заявките за заместник не се заредиха.'});});};
  read();const timer=setInterval(read,30000);window.addEventListener('focus',read);window.addEventListener('substitute-requests-changed',read);
  return()=>{controller.abort();clearInterval(timer);window.removeEventListener('focus',read);window.removeEventListener('substitute-requests-changed',read);};
 },[organization.id,attempt,onCount]);
 useEffect(()=>{const previous=previousRequest.current;previousRequest.current=requestId;if(!requestId&&previous)requestAnimationFrame(()=>{if(!decided.current&&opener.current?.isConnected)opener.current.focus();else document.getElementById('tasks-title')?.focus();decided.current=false;});},[requestId]);
 function open(id:string,button:HTMLButtonElement){opener.current=button;pushed.current=true;decided.current=false;const params=new URLSearchParams(query.toString());params.set('substitute',id);router.push(`${path}?${params}`,{scroll:false});}
 function close(){if(pushed.current){pushed.current=false;router.back();}else{const params=new URLSearchParams(query.toString());params.delete('substitute');router.replace(`${path}${params.size?`?${params}`:''}`,{scroll:false});}}
 const rows=result?.data?.requests??[];
 return <div className={styles.section}>
  <p role="status" className={notice?styles.success:'sr-only'}>{notice}</p>
  {result?.error?<p role="alert">{result.error} <button className={styles.button} onClick={()=>retry(n=>n+1)}>Опитай пак</button></p>:!result?<div className={styles.skeleton} role="status">Зареждане на заявките за заместник…</div>:null}
  {(all?rows:rows.slice(0,5)).map(request=>{const first=request.sessions[0];if(!first)return null;const badge=urgency(first.starts_at,result!.data!.now);return <button key={request.id} className={styles.officeTask} onClick={e=>open(request.id,e.currentTarget)} aria-haspopup="dialog"><span className={styles.avatar} aria-hidden="true">{initials(request.requester.name)}</span><span><strong>{request.requester.name} търси заместник</strong><small>{requestDetail(request)}</small></span><span className={`${styles.badge} ${['днес','утре'].includes(badge)?styles.limited:styles.quiet}`}>{badge}</span><span aria-hidden="true">›</span></button>;})}
  {!all&&rows.length>5?<button className={styles.link} aria-expanded={false} onClick={()=>setAll(true)}>Още {rows.length-5} заявки за заместник</button>:null}
  {requestId?<AssignmentDialog key={`${organization.id}:${requestId}`} organizationId={organization.id} requestId={requestId} onClose={close} onApplied={()=>{decided.current=true;retry(n=>n+1);}} onNotice={setNotice}/>:null}
 </div>;
}
