import { afterEach, expect, it, vi } from 'vitest';
import { loadMine, loadOptions, submitRequest, withdrawRequest, SubstituteError, type SubmitBody } from '../src/features/substitute/api';
const response = (body: unknown,status=200) => new Response(JSON.stringify(body),{status,headers:{'Content-Type':'application/json'}});
afterEach(()=>vi.unstubAllGlobals());
it('scopes all reads to the selected organization and never caches requests',async()=>{
 const fetch=vi.fn().mockResolvedValue(response({requests:[],standing_in:[],email_confirmed:false,now:''}));vi.stubGlobal('fetch',fetch);
 await loadMine('org-2');await loadOptions('org-2','2026-11-02','2026-11-03');
 expect(fetch.mock.calls[0][0]).toBe('/backend/api/v1/me/substitute-requests');expect(fetch.mock.calls[1][0]).toContain('from=2026-11-02&to=2026-11-03');
 for(const [,init] of fetch.mock.calls)expect(init).toMatchObject({credentials:'include',cache:'no-store',headers:{'X-Organization-Id':'org-2'}});
});
it('retries an unchanged submit with the same idempotency key and CSRF',async()=>{
 vi.stubGlobal('document',{cookie:'XSRF-TOKEN=encoded%3Dtoken'});const fetch=vi.fn().mockResolvedValue(response({id:'r',version:1}));vi.stubGlobal('fetch',fetch);
 const body:SubmitBody={sessions:[{id:'s',version:2}],reason:'personal',note:null,suggested_profile_id:null};
 await submitRequest('org',body,'same-key');await submitRequest('org',body,'same-key');
 expect(fetch.mock.calls[0]).toEqual(fetch.mock.calls[1]);expect(fetch.mock.calls[0][1]).toMatchObject({method:'POST',headers:{'X-XSRF-TOKEN':'encoded=token','Idempotency-Key':'same-key'},body:JSON.stringify(body)});
});
it('withdraws the whole request with its current version',async()=>{
 vi.stubGlobal('document',{cookie:'XSRF-TOKEN=csrf'});const fetch=vi.fn().mockResolvedValue(response({id:'r',version:3}));vi.stubGlobal('fetch',fetch);
 await withdrawRequest('org','r',2);expect(fetch.mock.calls[0]).toMatchObject(['/backend/api/v1/me/substitute-requests/r/withdraw',{body:'{"version":2}',headers:{'X-Organization-Id':'org'}}]);
});
it('retains the current request on a 422 and exposes conflicts for refresh',async()=>{
 vi.stubGlobal('document',{cookie:'XSRF-TOKEN=csrf'});const fetch=vi.fn().mockResolvedValueOnce(response({message:'Вече е решена.',request:{id:'r',version:4}},422)).mockResolvedValueOnce(response({message:'Графикът е променен.'},409));vi.stubGlobal('fetch',fetch);
 await expect(withdrawRequest('org','r',2)).rejects.toMatchObject({status:422,body:{request:{id:'r',version:4}}});
 await expect(loadMine('org')).rejects.toBeInstanceOf(SubstituteError);
});
