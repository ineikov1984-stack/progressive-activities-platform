'use client';
import { useEffect, useId, useRef, useState } from 'react';
import { loadSessionPanel, type ScheduleBoard, type ScheduleSession } from '../schedule/api';
import { ScheduleChangeDialog } from '../schedule/change-dialog';
import { ChangeApiError } from '../schedule/change-api';
import { warningKeys, type ChangeResult } from '../schedule/changes';
import { sofiaDay } from '../schedule/presentation';
import { assignSubstitute, declineOfficeRequest, loadCandidates, loadOfficeRequest, OfficeError, type AssignBody, type Candidates, type OfficeRequest } from './office-api';
import { candidateText, closedRequestText, withoutText } from './office-presentation';
import { dateLabel, when } from './presentation';
import dialogStyles from '../schedule/changes.module.css';
import styles from './substitute.module.css';

type Props={organizationId:string;requestId?:string;sessions?:ScheduleSession[];board?:ScheduleBoard;onClose:()=>void;onApplied:(result?:ChangeResult)=>void;onNotice:(message:string)=>void;onInvalid?:(message:string)=>void};
const reasons={vacation:'Отпуск',personal:'Лични причини',other:'Друго'};
export function AssignmentDialog({organizationId,requestId,sessions=[],board,onClose,onApplied,onNotice,onInvalid}:Props){
 const id=useId();const dialog=useRef<HTMLDialogElement>(null);const heading=useRef<HTMLHeadingElement>(null);
 const key=useRef(crypto.randomUUID());
 const [request,setRequest]=useState<OfficeRequest>();const [related,setRelated]=useState<OfficeRequest[]>([]);
 const [picked,setPicked]=useState<string[]>(sessions.map(s=>s.id));const [replaces,setReplaces]=useState('');
 const [originalChoices,setOriginalChoices]=useState<NonNullable<Candidates['choose_replaced']>>([]);
 const [person,setPerson]=useState('');const [confirmed,setConfirmed]=useState(false);const [others,setOthers]=useState(false);
 const [attempt,retry]=useState(0);const [detailLoading,setDetailLoading]=useState(!!requestId);
 const [result,setResult]=useState<{scope:string;data?:Candidates;error?:string}>();const [detailError,setDetailError]=useState('');
 const [success,setSuccess]=useState('');
 const [saving,setSaving]=useState(false);const [notice,setNotice]=useState('');const [declining,setDeclining]=useState(false);
 const declineHeading=useRef<HTMLParagraphElement>(null);const declineButton=useRef<HTMLButtonElement>(null);
 const [retryBody,setRetryBody]=useState<AssignBody>();
 const [cancel,setCancel]=useState<{sessions:ScheduleSession[];board:ScheduleBoard}>();
 const preserve=useRef(false);
 const scope=JSON.stringify([picked.slice().sort(),replaces,attempt]);
 const data=result?.scope===scope?result.data:undefined;
 const error=result?.scope===scope?result.error:undefined;
 const candidate=data?.candidates.find(p=>p.profile_id===person);
 const warnings=candidate?.warnings??[];const required=warningKeys(warnings);
 const active=request?.sessions.filter(s=>s.state==='open')??[];
 const locked=saving||!!retryBody;
 useEffect(()=>{dialog.current?.showModal();heading.current?.focus();},[]);
 useEffect(()=>{if(declining)declineHeading.current?.focus();},[declining]);
 useEffect(()=>{
  if(!requestId)return;
  const controller=new AbortController();
  loadOfficeRequest(organizationId,requestId,controller.signal).then(value=>{
   if(controller.signal.aborted)return;setRequest(value);setReplaces(value.requester.profile_id);
   const ids=value.sessions.filter(s=>s.state==='open').map(s=>s.session_id);
   setPicked(old=>preserve.current?old.filter(id=>ids.includes(id)):ids);preserve.current=false;setDetailLoading(false);
  }).catch((e:unknown)=>{if(!controller.signal.aborted){setDetailError(e instanceof OfficeError?e.message:'Заявката не се зареди. Опитайте пак.');setDetailLoading(false);}});
  return()=>controller.abort();
 },[organizationId,requestId,attempt]);
 useEffect(()=>{
  if(!picked.length||picked.length>50||detailLoading||detailError)return;
  const controller=new AbortController();
  const timer=setTimeout(()=>loadCandidates(organizationId,picked,replaces||undefined,controller.signal).then(async value=>{
   const linked=requestId?[]:await Promise.all((value.open_requests??[]).map(r=>loadOfficeRequest(organizationId,r.id,controller.signal)));
   if(controller.signal.aborted)return;setResult({scope,data:value});setRelated(linked);if(value.choose_replaced)setOriginalChoices(value.choose_replaced);
  }).catch((e:unknown)=>{if(!controller.signal.aborted){const message=e instanceof OfficeError?e.message:'Кандидатите не се заредиха. Проверете връзката и опитайте пак.';setResult({scope,error:message});if(!requestId&&e instanceof OfficeError&&e.status===422&&message.includes('един и същ преподавател'))onInvalid?.(message);}}),300);
  return()=>{clearTimeout(timer);controller.abort();};
 },[organizationId,scope,picked,replaces,requestId,detailLoading,detailError,onInvalid]);
 function edit(){setPerson('');setConfirmed(false);setNotice('');setDeclining(false);setResult(undefined);}
 function refresh(){if(requestId)setDetailLoading(true);setDetailError('');preserve.current=true;edit();retry(n=>n+1);}
 async function afterChange(result?:ChangeResult,message?:string){
  onApplied(result);window.dispatchEvent(new Event('substitute-requests-changed'));
  if(requestId){
   try{const value=await loadOfficeRequest(organizationId,requestId);const remaining=value.sessions.filter(s=>s.state==='open');if(remaining.length){setSuccess(`${message??'Промяната е запазена.'} ${remaining.length===1?'Остава':'Остават'} ${remaining.length} ${remaining.length===1?'занимание':'занимания'} за решение.`);setRequest(value);setPicked(remaining.map(s=>s.session_id));setPerson('');setConfirmed(false);key.current=crypto.randomUUID();retry(n=>n+1);return;}}catch{refresh();return;}
  }
  onClose();
 }
 async function assign(event:React.FormEvent){
  event.preventDefault();if(saving)return;
  if(!retryBody&&(!data||!person||!data.replaces)){setNotice(!picked.length?'Изберете занимания.':'Изберете кой ще замести.');return;}
  if(!retryBody&&required.length&&!confirmed){setNotice('Отметнете „Знам, назначи“.');return;}
  const currentRequest=request??related.find(r=>picked.every(session=>r.sessions.some(s=>s.session_id===session&&s.state==='open')));
  const body:AssignBody=retryBody??{action:'substitute',sessions:data!.sessions.map(s=>({id:s.id,version:s.version})),replaces_profile_id:data!.replaces!.profile_id,substitute_profile_id:person==='__none__'?null:person,...(currentRequest?{request:{id:currentRequest.id,version:currentRequest.version}}:{}),confirmed_warnings:confirmed?required:[]};
  setSaving(true);setNotice('');
  try{const value=await assignSubstitute(organizationId,body,key.current);setRetryBody(undefined);
   const text=person==='__none__'?`${data?.replaces?.name??'Преподавателят'} е освободен от заниманието.`:candidate?.return?`Заниманието отново е на ${candidate.name}.`:`${candidate?.name??'Заместникът'} ще замести ${data?.replaces?.name??'преподавателя'}.`;
   onNotice(`${text} Писмата тръгват до минута.`);await afterChange(value,`${text} ${data?.sessions[0]?`${data.sessions[0].activity} · ${data.sessions[0].group} · ${when(data.sessions[0])}${data.sessions.length>1?` и още ${data.sessions.length-1} занимания`:''}.`:''}`);
  }catch(e:unknown){
   if(e instanceof ChangeApiError&&[409,422].includes(e.status)){setRetryBody(undefined);refresh();setNotice(e.status===409?'Заявката е променена междувременно. Прегледайте я отново.':e.message);}
   else{if(!(e instanceof ChangeApiError)||e.status>=500)setRetryBody(body);setNotice(e instanceof ChangeApiError?e.message:'Потвърждението не пристигна. Опитайте отново със същата заявка.');}
  }finally{setSaving(false);}
 }
 async function decline(){
  if(!request||saving)return;setSaving(true);setNotice('');
  try{const value=await declineOfficeRequest(organizationId,request.id,request.version);setRequest(value);setDeclining(false);onNotice('Заявката не е одобрена. Писмото тръгва до минута.');await afterChange();}
  catch(e:unknown){setDeclining(false);if(e instanceof OfficeError&&[409,422].includes(e.status)){refresh();setNotice('Заявката е променена междувременно. Прегледайте я отново.');}else setNotice(e instanceof OfficeError?e.message:'Отказът не се запази. Опитайте пак.');}
  finally{setSaving(false);}
 }
 async function openCancellation(){
  if(saving||!picked.length)return;setSaving(true);setNotice('');
  try{const panels=await Promise.all(picked.map(session=>loadSessionPanel(organizationId,session)));if(panels.some(p=>!p.actions?.includes('cancel')))throw new Error('Заниманието не може да се отмени — промени са достъпни до 15 минути преди началото.');
   setCancel({sessions:panels.map(p=>p.session),board:{sessions:panels.map(p=>p.session),activities:panels.map(p=>({...p.activity,color:0})),groups:panels.map(p=>({...p.group,activity_id:p.activity.id})),locations:panels.flatMap(p=>p.location?[p.location]:[]),instructors:[],abilities:Object.fromEntries(panels.map(p=>[p.group.id,p.abilities]))}});
  }catch(e:unknown){setNotice(e instanceof Error&&/[а-яА-Я]/.test(e.message)?e.message:'Заниманията не се заредиха. Опитайте пак.');}finally{setSaving(false);}
 }
 function personRow(p:NonNullable<typeof candidate>){const warningId=`${id}-${p.profile_id}-warning`;return <label key={p.profile_id} className={styles.option}><input type="radio" name={`${id}-person`} checked={person===p.profile_id} aria-describedby={warningId} disabled={locked} onChange={()=>{setPerson(p.profile_id);setConfirmed(false);setNotice('');}}/><span><strong>{p.name}</strong><small id={warningId}>{candidateText(p,data?.sessions.length??0)}</small>{p.suggested?<small>Предлага {request?.requester.name??related[0]?.requester.name??'колега'}</small>:null}{!p.has_access?<small>Без вход в системата — няма да получи имейл</small>:null}</span></label>;}
 const requestCards=request?[request]:related;
 const choices=originalChoices;
 const returns=data?.candidates.filter(p=>p.return)??[];
 const activityCandidates=data?.candidates.filter(p=>!p.return&&p.section==='activity')??[];
 const othersCandidates=data?.candidates.filter(p=>!p.return&&p.section==='others')??[];
 const shownSessions=request?active.map(s=>({id:s.session_id,...s})):sessions.map(s=>({id:s.id,starts_at:s.starts_at,ends_at:s.ends_at,activity:board?.activities.find(a=>a.id===s.activity_id)?.title??'Занимание',group:board?.groups.find(g=>g.id===s.group_id)?.name??'',age_label:board?.groups.find(g=>g.id===s.group_id)?.age_label??null,location:board?.locations.find(l=>l.id===s.location_id)?.name??null}));
 const ready=!detailLoading&&!detailError&&(!request||active.length>0);
 return <dialog ref={dialog} className={`${dialogStyles.dialog} ${styles.officeDialog}`} aria-labelledby={`${id}-title`} onCancel={e=>{e.stopPropagation();if(saving)e.preventDefault();}} onClose={e=>{e.stopPropagation();onClose();}}>
 <form className={dialogStyles.inner} onSubmit={assign}>
  <header className={dialogStyles.header}><div><h2 id={`${id}-title`} ref={heading} tabIndex={-1}>Назначи заместник</h2>{success?<p role="status" className={styles.success}>{success}</p>:null}</div><button className={dialogStyles.close} type="button" disabled={saving} aria-label="Затвори назначаването" onClick={()=>dialog.current?.close()}>×</button></header>
  <div className={dialogStyles.body}>
   {detailLoading?<div className={styles.skeleton} role="status">Зареждане на заявката…</div>:null}
   {detailError?<p role="alert">{detailError} <button type="button" onClick={refresh}>Опитай пак</button></p>:null}
   {requestCards.map(r=><div key={r.id} className={styles.confirm}><strong>{r.requester.name} няма да може</strong><p className={styles.small}>Изпратено {new Date(r.created_at).toLocaleString('bg-BG',{timeZone:'Europe/Sofia'})}</p><p>Причина: {reasons[r.reason]}{r.note?` · „${r.note}“`:''}</p>{!r.sessions.some(s=>s.state==='open')?<p role="status">{closedRequestText(r)}</p>:null}</div>)}
   {ready?<>
    <fieldset disabled={locked}><legend>{shownSessions.length} {shownSessions.length===1?'занимание':'занимания'} · Кои занимания?</legend><div className={styles.stack}>{shownSessions.map(s=>{const fresh=data?.sessions.find(v=>v.id===s.id);return <label className={styles.option} key={s.id}><input type="checkbox" checked={picked.includes(s.id)} onChange={e=>{setPicked(old=>e.target.checked?[...old,s.id]:old.filter(v=>v!==s.id));if(!requestId){setReplaces('');setOriginalChoices([]);}edit();}}/><span><strong>{fresh?.activity??s.activity} · {fresh?.group??s.group}</strong><small>{when(fresh??s)}{(fresh??s).age_label?` · ${(fresh??s).age_label}`:''}{(fresh??s).location?` · ${(fresh??s).location}`:''}</small></span></label>;})}</div></fieldset>
    {picked.length>50?<p role="alert">Изберете най-много 50 занимания за заместник.</p>:null}
    {choices?.length?<fieldset disabled={locked}><legend>Кой няма да може?</legend><div className={styles.stack}>{choices.map(p=><label key={p.profile_id} className={styles.option}><input type="radio" name={`${id}-replaces`} checked={replaces===p.profile_id} onChange={()=>{setReplaces(p.profile_id);edit();}}/>{p.name}{p.state==='released'?' (без заместник)':p.state==='replaced'?` (замества го ${sessions.flatMap(s=>s.instructors).find(t=>t.replaces?.some(r=>r.profile_id===p.profile_id))?.name??'друг преподавател'})`:''}</label>)}</div></fieldset>:null}
    {picked.length>0&&picked.length<=50&&!data&&!error?<div className={styles.skeleton} role="status">Проверяваме кой е свободен…</div>:null}
    {error?<p role="alert">{error} <button type="button" disabled={locked} onClick={refresh}>Опитай пак</button></p>:null}
    {data?.replaces?<><p><strong>Кой няма да може:</strong> {data.replaces.name}</p><fieldset><legend>Кой ще замести</legend><div className={styles.stack}>{returns.map(personRow)}<h3 className={styles.day}>Преподаватели на {[...new Set(data.sessions.map(s=>s.activity))].join(', ')}</h3>{activityCandidates.map(personRow)}{!returns.length&&!activityCandidates.length?<p>Няма други преподаватели на тези дейности.</p>:null}
      {othersCandidates.length?<><button className={styles.button} type="button" aria-expanded={others} aria-controls={`${id}-others`} onClick={()=>setOthers(v=>!v)}>Други от екипа ({othersCandidates.length})</button><div id={`${id}-others`} hidden={!others} className={others?styles.stack:undefined}>{others?othersCandidates.map(personRow):null}</div></>:null}
      {data.can_go_without?<label className={styles.option}><input type="radio" name={`${id}-person`} disabled={locked} checked={person==='__none__'} onChange={()=>{setPerson('__none__');setConfirmed(false);setNotice('');}}/>{withoutText(data)}</label>:null}
    </div></fieldset></>:null}
    {warnings.map((w,index)=><p key={`${w.type}-${w.session_id}-${index}`} className={dialogStyles.warning}>⚠ {w.message}</p>)}
    {required.length?<label className={dialogStyles.check}><input type="checkbox" disabled={locked} checked={confirmed} onChange={e=>{setConfirmed(e.target.checked);setNotice('');}}/>Знам, назначи</label>:null}
    {candidate?<div className={styles.confirm}>{!candidate.return&&candidate.has_access?<p>{candidate.name} ще вижда {[...new Set(data?.sessions.map(s=>`${s.activity} · ${s.group}`))].join(', ')} само {data?.sessions.length===1?`за заниманието на ${dateLabel(data.sessions[0].starts_at)} — децата, име и клас — до ${dateLabel(new Date(Date.parse(`${sofiaDay(data.sessions[0].starts_at)}T12:00:00Z`)+86400000).toISOString())}, 23:59`:'в дните на заниманията и до края на следващия ден'}. После групата изчезва сама.</p>:null}<p className={styles.small}>{candidate.has_access&&candidate.email_confirmed?`${candidate.name} получава имейл. Записва се в историята.`:`${candidate.name} ${candidate.has_access?'няма потвърден имейл':'няма вход'} и няма да получи писмо. Записва се в историята.`}</p></div>:null}
    {request&&active.length?declining?<div className={styles.confirm}><p ref={declineHeading} tabIndex={-1}>{request.requester.name} ще получи имейл, че заявката не е одобрена. Отказът важи за всички чакащи занимания в заявката.</p><div className={styles.choices}><button type="button" disabled={locked} onClick={decline}>Откажи заявката</button><button type="button" disabled={locked} onClick={()=>{setDeclining(false);requestAnimationFrame(()=>declineButton.current?.focus());}}>Назад</button></div></div>:<button ref={declineButton} type="button" disabled={locked} className={styles.link} onClick={()=>setDeclining(true)}>Откажи заявката</button>:null}
    {picked.length>0?<button type="button" disabled={locked} className={styles.link} onClick={openCancellation}>Вместо това — отмени заниманието</button>:null}
   </>:null}
   {notice?<p role="alert" className={dialogStyles.warning}>{notice}</p>:null}
   {retryBody?<p>Опитайте отново със същото назначаване. Изборът е запазен.</p>:null}
  </div>
  <footer className={dialogStyles.footer}><button type="button" disabled={saving} onClick={()=>dialog.current?.close()}>Назад</button>{ready?<button type="submit" className={dialogStyles.primary} disabled={saving||detailLoading}>{saving?'Запазване…':retryBody?'Опитай пак':person==='__none__'?'Освободи без заместник':candidate?.return?`Върни ${candidate.name}`:candidate?`Назначи ${candidate.name}`:'Назначи'}</button>:null}</footer>
 </form>
 {cancel?<ScheduleChangeDialog organizationId={organizationId} action="cancel" sessions={cancel.sessions} board={cancel.board} day={sofiaDay(cancel.sessions[0].starts_at)} onClose={()=>setCancel(undefined)} onApplied={value=>{setCancel(undefined);onNotice('Заниманията са отменени.');void afterChange(value);}}/>:null}
 </dialog>;
}
