import {afterEach,expect,it,vi} from 'vitest';
import {AttendanceError,saveAttendance,loadSheet,loadPending,canSeePending} from '../src/features/attendance/api';
afterEach(()=>vi.unstubAllGlobals());
it('keeps organization, CSRF, exact body and idempotency key on attendance writes',async()=>{
 vi.stubGlobal('document',{cookie:'XSRF-TOKEN=csrf%20value'}); const fetch=vi.fn().mockResolvedValue(new Response(JSON.stringify({applied:[]}),{status:200})); vi.stubGlobal('fetch',fetch);
 const change={action:'mark' as const,marks:[{enrollment_id:'child',status:'present' as const,version:2}]}; await saveAttendance('org','session',change,'stable');
 expect(fetch).toHaveBeenCalledWith('/backend/api/v1/attendance/sessions/session/changes',expect.objectContaining({credentials:'include',cache:'no-store',body:JSON.stringify(change),headers:expect.objectContaining({'X-Organization-Id':'org','X-XSRF-TOKEN':'csrf value','Idempotency-Key':'stable'})}));
});
it('retains conflict roster and retry flag for the queue',async()=>{
 vi.stubGlobal('fetch',vi.fn().mockResolvedValue(new Response(JSON.stringify({message:'Променено',children:[],conflicts:['child']}),{status:409})));
 try {await loadSheet('org','session'); throw new Error('expected conflict');} catch(e) {expect(e).toBeInstanceOf(AttendanceError);expect((e as AttendanceError).body.conflicts).toEqual(['child']);}
});
it('treats non-JSON server errors as retryable errors instead of parsing failures',async()=>{
 vi.stubGlobal('fetch',vi.fn().mockResolvedValue(new Response('unavailable',{status:503}))); await expect(loadSheet('org','session')).rejects.toMatchObject({status:503});
});

it('requests pending only for mark or late-correction abilities, retaining organization and no-store',async()=>{
 expect(canSeePending(['attendance.view_month'])).toBe(false);expect(canSeePending(['attendance.mark'])).toBe(true);expect(canSeePending(['attendance.correct_late'])).toBe(true);expect(canSeePending()).toBe(false);
 const fetch=vi.fn().mockResolvedValue(new Response(JSON.stringify({total:22,sessions:[]})));vi.stubGlobal('fetch',fetch);await expect(loadPending('org')).resolves.toMatchObject({total:22});expect(fetch).toHaveBeenCalledWith('/backend/api/v1/attendance/pending',expect.objectContaining({cache:'no-store',headers:expect.objectContaining({'X-Organization-Id':'org'})}));
});
it('sends held/not-held reasons through the existing CSRF protected changes endpoint',async()=>{
 vi.stubGlobal('document',{cookie:'XSRF-TOKEN=csrf'});const fetch=vi.fn().mockResolvedValue(new Response(JSON.stringify({applied:[]})));vi.stubGlobal('fetch',fetch);await saveAttendance('org','session',{action:'not_held',reason:'Залата не е достъпна.'},'key');expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual({action:'not_held',reason:'Залата не е достъпна.'});
});
