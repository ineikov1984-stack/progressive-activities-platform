'use client';
import { useEffect, useRef, useState } from 'react';
import { AttendanceError, loadSheet, saveAttendance, type AttendanceChild, type Change, type Mark, type Sheet } from './api';
import { childLabel, clock, date, deadlineText, historyLines } from './presentation';
import styles from './attendance.module.css';
export type CorrectionTarget = {session:string;enrollment:string;name:string;startsAt:string};
export function AttendanceCorrection({organizationId,target,onClose,onSaved}:{organizationId:string;target:CorrectionTarget;onClose:()=>void;onSaved:()=>void}) {
  const dialog=useRef<HTMLDialogElement>(null); const heading=useRef<HTMLHeadingElement>(null);
  const [sheet,setSheet]=useState<Sheet>();const [error,setError]=useState('');const [attempt,retry]=useState(0);
  useEffect(()=>{dialog.current?.showModal();heading.current?.focus();},[]);
  useEffect(()=>{const abort=new AbortController();loadSheet(organizationId,target.session,abort.signal).then(setSheet).catch((e:unknown)=>{if(!abort.signal.aborted)setError(e instanceof AttendanceError?e.message:'Подробностите не се заредиха.');});return()=>abort.abort();},[organizationId,target.session,attempt]);
  const child=sheet?.children.find((c)=>c.enrollment_id===target.enrollment);
  return <dialog ref={dialog} className={styles.drawer} aria-labelledby="correction-title" onClose={onClose} onClick={(e)=>{if(e.target===dialog.current)dialog.current?.close();}}><div className={styles.drawerInner}>
    <div className={styles.rowHead}><h2 ref={heading} id="correction-title" tabIndex={-1}>{target.name} · {date(target.startsAt)}</h2><button className={styles.icon} aria-label="Затвори поправката" onClick={()=>dialog.current?.close()}>✕</button></div>
    {!sheet ? error?<><p role="alert">{error}</p><button className={styles.button} onClick={()=>{setError('');retry((n)=>n+1);}}>Опитай пак</button></>:<div className={styles.skeleton} aria-busy="true" aria-label="Зареждане на поправката"/> : child?<CorrectionForm key={child.enrollment_id} organizationId={organizationId} sheet={sheet} child={child} onFresh={setSheet} onSaved={onSaved}/>:<p>{sheet.session.not_held?'Заниманието не се проведе.':sheet.session.status==='cancelled'?'Заниманието е отменено.':'Детето вече не е в списъка.'} Присъствия не се променят. <a className={styles.link} href={`/dashboard/attendance/${encodeURIComponent(target.session)}?from=month`}>Виж заниманието →</a></p>}
  </div></dialog>;
}
function CorrectionForm({organizationId,sheet,child,onFresh,onSaved}:{organizationId:string;sheet:Sheet;child:AttendanceChild;onFresh:(sheet:Sheet)=>void;onSaved:()=>void}) {
  const [mark,setMark]=useState<Mark>(child.status);const [reason,setReason]=useState('');const [error,setError]=useState('');const [busy,setBusy]=useState(false);const [forbidden,setForbidden]=useState(false);
  const field=useRef<HTMLTextAreaElement>(null);const abort=useRef<AbortController|null>(null);const request=useRef<{json:string;key:string}|undefined>(undefined);
  useEffect(()=>()=>abort.current?.abort(),[]);
  const allowed=sheet.abilities.mark&&!forbidden&&!sheet.session.not_held&&sheet.session.status!=='cancelled';
  async function save() {
    if(busy||!allowed||mark===null)return;
    if(sheet.abilities.reason_required&&!reason.trim()){setError('Напишете причина за поправката.');field.current?.focus();return;}
    const body:Change={action:'mark',marks:[{enrollment_id:child.enrollment_id,status:mark,version:child.version}],...(sheet.abilities.reason_required?{reason:reason.trim()}:{})};
    const json=JSON.stringify(body);if(request.current?.json!==json)request.current={json,key:crypto.randomUUID()};
    const controller=new AbortController();abort.current=controller;setBusy(true);setError('');
    try {await saveAttendance(organizationId,sheet.session.id,body,request.current.key,controller.signal);if(!controller.signal.aborted)onSaved();}
    catch(e:unknown){if(controller.signal.aborted)return;
      if(e instanceof AttendanceError&&e.status===409&&e.body.children&&e.body.session&&e.body.abilities&&e.body.counts){request.current=undefined;const current=e.body.children.find((c)=>c.enrollment_id===child.enrollment_id);setMark(current?.status??null);setError(`Междувременно ${current?.marked_by??'член на екипа'} промени отметката. Вижте новото състояние и опитайте пак.`);onFresh(e.body as Sheet);}
      else {if(e instanceof AttendanceError&&e.status===403)setForbidden(true);setError(e instanceof AttendanceError?e.message:'Не е записано. Проверете връзката и опитайте пак.');}
    } finally {if(!controller.signal.aborted)setBusy(false);}
  }
  const lines=historyLines(sheet.history.filter((h)=>h.enrollment_id===child.enrollment_id),[child]);
  return <><p className={styles.muted}>Отбелязано: {childLabel(child).toLocaleLowerCase('bg-BG')}. {sheet.abilities.reason_required?`Срокът на учителя изтече на ${date(new Date(Date.parse(sheet.abilities.deadline)-1).toISOString())}.`:deadlineText(sheet.abilities.deadline)}</p>
    {error?<p role="alert" className={styles.error}>{error}</p>:null}
    {allowed?<><div className={styles.buttons}>{(child.expected?['present','absent'] as const:['present'] as const).map((value)=><button disabled={busy} key={value} className={mark===value?(value==='present'?styles.good:styles.absent):styles.button} aria-pressed={mark===value} onClick={()=>setMark(value)}>{value==='present'?'Присъства':'Отсъства'}</button>)}</div>{sheet.abilities.reason_required?<label className={styles.field}>Причина (задължителна)<textarea ref={field} value={reason} disabled={busy} maxLength={500} onChange={(e)=>{setReason(e.target.value);setError('');}}/></label>:null}<button className={`${styles.primary} ${styles.full}`} disabled={busy||mark===null} onClick={save}>{busy?'Записва се…':'Запази поправката'}</button></>:<p className={styles.muted}>Само преглед — нямате право за промяна или срокът е изтекъл.</p>}
    <h3>История</h3>{child.skip?<p>{child.skip.by==='parent'?'Родителят предупреди':`${child.skip.by_name??'Екипът'} записа предупредено отсъствие`} · {date(child.skip.at)} · {clock(child.skip.at)}</p>:null}{lines.length===0&&!child.skip?<p className={styles.muted}>Все още няма промени.</p>:null}<ul className={styles.history}>{lines.map((line)=><li key={line.id}>{line.text}<p className={styles.muted}>{date(line.at)} · {clock(line.at)}</p></li>)}</ul></>;
}
