"""Run only against a disposable installation and three empty test databases.
SM_TEST_CONFIG points to a JSON file containing databases, password and install_key.
SM_TEST_BASE_URL is the HTTP(S) address of the test installation.
"""
import base64,json,os,re,uuid,urllib.parse,urllib.request,urllib.error
from http.cookies import SimpleCookie
from pathlib import Path
base=os.environ['SM_TEST_BASE_URL'].rstrip('/')+'/'
config=json.loads(Path(os.environ['SM_TEST_CONFIG']).read_text())
cookies={}
def request(path='',data=None,method='GET',csrf=None,form=False,expected=200,location=None,accept=None):
 headers={}
 if cookies:headers['Cookie']='; '.join(f'{key}={value}' for key,value in cookies.items())
 if accept:headers['Accept']=accept
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
 for value in response.headers.get_all('Set-Cookie',[]):
  for key,cookie in SimpleCookie(value).items():
   if cookie['max-age']=='0':cookies.pop(key,None)
   else:cookies[key]=cookie.value
 assert code==expected,(path,code,body[:500])
 if location is not None:assert response.headers['Location']==location
 if 'application/pdf' in response.headers.get('Content-Type',''):return body
 if response.headers.get('Content-Type')=='application/octet-stream':
  assert 'no-transform' in response.headers.get('Cache-Control','')
  return body
 try:return json.loads(body)
 except (json.JSONDecodeError,UnicodeDecodeError):return body.decode(errors='replace')
def api(brand,route='',**kw):
 return request('api.php?'+urllib.parse.urlencode({'brand':brand,'route':route.split('?')[0]})+('&'+route.split('?',1)[1] if '?' in route else ''),**kw)
def token(html):return re.search(r'name="csrf" value="([a-f0-9]+)"',html).group(1)
def login(brand,password,expected=302):
 path='entrar.php?brand='+brand
 html=request(path)
 return request(path,data={'csrf':token(html),'password':password},method='POST',form=True,expected=expected)
if config.get('install_key'):
 html=request('instalar.php');csrf=token(html)
 form={'csrf':csrf,'install_key':config['install_key'],'password':config['password'],'confirm':config['password']}
 for brand,db in config['databases'].items():
  for key,value in db.items():form[brand+'_'+key.removeprefix('db_')]=value
 # The installer must reject repeated physical databases before writing configuration.
 invalid={**form,'argento_name':form['sublimart_name'],'argento_user':form['sublimart_user'],'argento_password':form['sublimart_password']}
 assert 'base de datos diferente' in request('instalar.php',data=invalid,method='POST',form=True,expected=400)
 request('instalar.php',data=form,method='POST',form=True,expected=302,location='/')
selector=request();assert all(name in selector for name in ['Sublimart','Argento 100x100','El Merchandising de tu club'])
request('api.php?route=bootstrap',expected=400)
api('invalid','bootstrap',expected=400)
brands=list(config['databases']);brand_names=['Sublimart','Argento 100x100','El Merchandising de tu club']
client_ids={};document_ids={};tokens={}
for brand,name in zip(brands,brand_names):
 login_url='/entrar.php?brand='+brand
 request('?brand='+brand,expected=302,location=login_url)
 assert api(brand,'bootstrap',expected=401)['login']==login_url
 login(brand,config['password'])
 original_logo=Path(__file__).resolve().parents[1]/'assets'/'logos'/(brand+'.png')
 assert api(brand,'pdf-logo',accept='image/webp,image/*,*/*;q=0.8')==original_logo.read_bytes()
 boot=api(brand,'bootstrap');assert boot['company']['name']==name;tokens[brand]=boot['csrfToken']
 assert 'Cambiar contraseña' in request('?brand='+brand)
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
assert len(set(tokens.values()))==3
api(brands[1],'company',data={},method='PUT',csrf=tokens[brands[0]],expected=403)
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
first=brands[0]
change_path='cuenta.php?brand='+first
other_browser=cookies.copy()
replacement='NewPass7'
assert replacement!=config['password']
updated=request(change_path,data={'csrf':token(request(change_path)),'current':config['password'],'password':replacement,'confirm':replacement},method='POST',form=True)
assert 'Contraseña guardada para esta marca.' in updated
api(first,'bootstrap')
active_browser=cookies.copy()
cookies=other_browser
api(first,'bootstrap',expected=401)
for brand in brands[1:]:api(brand,'bootstrap')
cookies=active_browser
logout_path='salir.php?brand='+first
request(logout_path,data={'csrf':token(updated)},method='POST',form=True,expected=302,location='/')
api(first,'documents/'+document_ids[first]+'/pdf',expected=401)
for brand in brands[1:]:api(brand,'bootstrap')
login(first,config['password'],expected=401)
login(first,replacement)
print('PASS: instalación, accesos y contraseñas por marca, cierre de sesión, tres BD, clientes, documentos, PDF, papelera y restauración.')
