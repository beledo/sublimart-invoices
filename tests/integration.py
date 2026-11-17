"""Run only against a disposable installation and three empty test databases.
SM_TEST_CONFIG points to a JSON file containing databases, password and install_key.
SM_TEST_BASE_URL is the HTTP(S) address of the test installation.
"""
import base64,json,os,re,uuid,urllib.parse,urllib.request,urllib.error
from pathlib import Path
base=os.environ['SM_TEST_BASE_URL'].rstrip('/')+'/'
config=json.loads(Path(os.environ['SM_TEST_CONFIG']).read_text())
cookie=''
def request(path='',data=None,method='GET',csrf=None,form=False,expected=200):
 global cookie
 headers={}
 if cookie:headers['Cookie']=cookie
 if csrf:headers['X-CSRF-Token']=csrf
 if data is not None:
  data=urllib.parse.urlencode(data).encode() if form else json.dumps(data).encode()
  headers['Content-Type']='application/x-www-form-urlencoded' if form else 'application/json'
 req=urllib.request.Request(base+path,data=data,headers=headers,method=method)
 class NoRedirect(urllib.request.HTTPRedirectHandler):
  def redirect_request(self,*args):return None
 opener=urllib.request.build_opener(NoRedirect)
 try:response=opener.open(req,timeout=20)
 except urllib.error.HTTPError as error:response=error
 body=response.read();code=response.code
 if response.headers.get('Set-Cookie'):cookie=response.headers.get('Set-Cookie').split(';')[0]
 assert code==expected,(path,code,body[:500])
 if 'application/pdf' in response.headers.get('Content-Type',''):return body
 try:return json.loads(body)
 except (json.JSONDecodeError,UnicodeDecodeError):return body.decode(errors='replace')
def api(brand,route='',**kw):
 return request('api.php?'+urllib.parse.urlencode({'brand':brand,'route':route.split('?')[0]})+('&'+route.split('?',1)[1] if '?' in route else ''),**kw)
def token(html):return re.search(r'name="csrf" value="([a-f0-9]+)"',html).group(1)
if config.get('install_key'):
 html=request('instalar.php');csrf=token(html)
 form={'csrf':csrf,'install_key':config['install_key'],'password':config['password'],'confirm':config['password']}
 for brand,db in config['databases'].items():
  for key,value in db.items():form[brand+'_'+key.removeprefix('db_')]=value
 # The installer must reject repeated physical databases before writing configuration.
 invalid={**form,'argento_name':form['sublimart_name'],'argento_user':form['sublimart_user'],'argento_password':form['sublimart_password']}
 assert 'base de datos diferente' in request('instalar.php',data=invalid,method='POST',form=True,expected=400)
 request('instalar.php',data=form,method='POST',form=True,expected=302)
else:
 html=request('entrar.php');request('entrar.php',data={'csrf':token(html),'password':config['password']},method='POST',form=True,expected=302)
selector=request();assert all(name in selector for name in ['Sublimart','Argento 100x100','El Merchandising de tu club'])
request('api.php?route=bootstrap',expected=400)
api('invalid','bootstrap',expected=400)
brands=list(config['databases']);brand_names=['Sublimart','Argento 100x100','El Merchandising de tu club']
client_ids={};document_ids={};tokens={}
for brand,name in zip(brands,brand_names):
 boot=api(brand,'bootstrap');assert boot['company']['name']==name;tokens[brand]=boot['csrfToken']
 client={'name':'Cliente '+name,'nif':'30.123.456','address':'','postcode':'','city':'','email':'','phone':''}
 cid=str(uuid.uuid4());client_ids[brand]=cid
 result=api(brand,'clients',data={'id':cid,'client':client},method='POST',csrf=tokens[brand],expected=201)
 assert result['client']['name']==client['name']
 assert api(brand,'clients?search=30123456')['total']==1
 assert api(brand,'clients?search='+urllib.parse.quote(name))['total']==1
 assert api(brand,'clients/lookup?nif=30-123-456')['client']['id']==cid
 api(brand,'clients',data={'id':str(uuid.uuid4()),'client':client},method='POST',csrf=tokens[brand],expected=409)
 api(brand,'company',data={'company':boot['company']},method='PUT',expected=403)
 wrong={**boot['company'],'brand':next(x for x in brands if x!=brand)}
 api(brand,'company',data={'company':wrong},method='PUT',csrf=tokens[brand],expected=400)
 data={'brand':brand,'type':'quote','number':'001-26','date':'2026-11-17','tax':21,'unit':'Cantidad','company':boot['company'],'client':client,'items':[{'description':'Camiseta personalizada','quantity':'12','price':'11.50'},{'description':'Taza con diseño a color','quantity':'6','price':'6.90'}],'paymentMethod':'','paymentTerms':'','notes':'','clause':'','validity':'30'}
 pdf=base64.b64encode((Path(config['pdf_directory'])/(brand+'.pdf')).read_bytes()).decode()
 did=str(uuid.uuid4());document_ids[brand]=did
 saved=api(brand,'documents',data={'id':did,'data':data,'pdfBase64':pdf},method='POST',csrf=tokens[brand],expected=201)
 assert saved['document']['total']==21707 and saved['suggestions']['quote']['next']=='002-26'
 api(brand,'documents/'+did,data={'revision':0,'data':data,'pdfBase64':pdf},method='PUT',csrf=tokens[brand],expected=409)
 assert api(brand,'documents/'+did+'/pdf').startswith(b'%PDF-')
for brand in brands:
 assert api(brand,'clients')['total']==1
 for other in brands:
  if other==brand:continue
  assert api(brand,'clients?search='+urllib.parse.quote('Cliente '+brand_names[brands.index(other)]))['total']==0
  api(brand,'documents/'+document_ids[other],expected=404)
  api(brand,'documents/'+document_ids[other]+'/pdf',expected=404)
  api(brand,'documents/'+document_ids[other],method='DELETE',csrf=tokens[brand],expected=404)
 assert api(brand,'documents')['total']==1
 # Trash and restore one brand, then confirm the others remain active.
api(brands[0],'documents/'+document_ids[brands[0]],method='DELETE',csrf=tokens[brands[0]])
assert api(brands[0],'documents')['total']==0
assert all(api(brand,'documents')['total']==1 for brand in brands[1:])
api(brands[0],'documents/'+document_ids[brands[0]]+'/restore',method='POST',csrf=tokens[brands[0]])
print('PASS: instalación, tres BD, logos precargados, DNI/nombre, duplicados, CSRF, revisión, numeración, documentos y PDF aislados, papelera y restauración.')
