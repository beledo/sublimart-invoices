(function () {
  'use strict';
  const engine = window.BrandDocument, $ = id => document.getElementById(id);
  const form = $('document-form');
  const previewFootnote = document.querySelector('.preview-footnote');
  const assetBase=new URL('.',document.currentScript.src);
  const phpApi=document.querySelector('meta[name="brand-api"]')?.content;
  const brandId=document.querySelector('meta[name="brand-id"]').content;
  const sessionKey=document.querySelector('meta[name="app-csrf"]').content;
  const draftKey='sublimart:draft:'+sessionKey+':'+brandId;
  function apiUrl(path) {
    if (!phpApi) return path;
    const endpoint=new URL(phpApi,location.href), request=new URL(path,location.origin);
    endpoint.search=request.search;
    endpoint.searchParams.set('route',request.pathname.slice('/api/'.length));
    endpoint.searchParams.set('brand',brandId);
    return endpoint.href;
  }
  function uuid() {
    if(typeof crypto.randomUUID==='function')return crypto.randomUUID();
    const bytes=crypto.getRandomValues(new Uint8Array(16));bytes[6]=(bytes[6]&15)|64;bytes[8]=(bytes[8]&63)|128;
    const hex=Array.from(bytes,byte=>byte.toString(16).padStart(2,'0')).join('');return hex.slice(0,8)+'-'+hex.slice(8,12)+'-'+hex.slice(12,16)+'-'+hex.slice(16,20)+'-'+hex.slice(20);
  }
  let nextId = 2, ready = false, timer, toastTimer, busy = false, errorField=null;
  let rows = [{ id: 1, description: '', quantity: '1', price: '0' }];
  let type = 'quote';
  let editingId = null, editingRevision = null, requestId = uuid(), saving = false, savedFingerprint = null, savedNumber = null;
  let suggestions = {}, savedCompany, companyTouched = false, storeReady = false, bootstrapVersion = 0;
  let numberIsAutomatic = true;
  let view = 'editor', filter = '', archiveRows = [], archiveNext = null, archiveVersion = 0, loadingRecord = false;
  const clientFields = ['name','nif','address','postcode','city','email','phone'];
  let documentSource=null, clientResolved=null, clientLookupVersion=0, clientLookupController=null, csrfToken=sessionKey, uploadPdf=false;
  let clientsRows=[],clientsNext=null,clientsVersion=0,clientsQuery='',clientModal=null;
  let reportRows=[],reportNext=null,reportVersion=0,reportPeriod=null;
  let draftRestored=false, lookupRows=[],searchTimer;
  const clauseDefault = '';
  const termsByType = { quote:'', invoice:'' };
  const notesByType = { quote:'', invoice:'' };
  $('date').value = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Madrid', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
  $('payment-terms').value = termsByType.quote;
  $('notes').value = notesByType.quote;
  $('clause').value = clauseDefault;
  function value(id) { return $(id).value.trim(); }
  function readCompany() {
    return { brand:brandId, name:value('company-name'), nif:value('company-nif'), address:value('company-address'), postcode:value('company-postcode'), city:value('company-city'), email:value('company-email'), phone:value('company-phone'), iban:value('company-iban') };
  }
  savedCompany = readCompany();
  function readData() {
    return {
      brand:brandId, type, number: value('number'), date: value('date'), tax: Number(value('tax')), unit: 'Cantidad',
      company: readCompany(),
      client: Object.fromEntries(clientFields.map(key=>[key,value('client-'+key)])),
      items: rows.map(row => ({ description: row.description.trim(), quantity: row.quantity, price: row.price })),
      paymentMethod: value('payment-method'), paymentTerms: value('payment-terms'), notes: value('notes'), clause: value('clause'), validity: value('validity'), iban: value('company-iban'), ...(documentSource?{source:documentSource}:{})
    };
  }
  function renderRows() {
    if ($('items').contains($('form-error'))) $('form-actions').before($('form-error'));
    $('items').innerHTML = rows.map((row, index) => {
      const position = index + 1;
      const options = rows.map((_,i)=>'<option value="'+i+'"'+(i===index?' selected':'')+'>'+(i+1)+'</option>').join('');
      const controls = '<div class="item-tools"><label class="item-position">Posición<select id="position-'+row.id+'" data-position="'+row.id+'" aria-label="Posición de la partida '+position+'">'+options+'</select></label><button type="button" class="move-item" data-move="-1" data-move-id="'+row.id+'" aria-label="Subir partida '+position+'" title="Subir partida"'+(index===0?' disabled':'')+'><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5m-6 6 6-6 6 6"/></svg></button><button type="button" class="move-item" data-move="1" data-move-id="'+row.id+'" aria-label="Bajar partida '+position+'" title="Bajar partida"'+(index===rows.length-1?' disabled':'')+'><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14m-6-6 6 6 6-6"/></svg></button><button type="button" class="remove-item" data-remove="'+row.id+'" aria-label="Eliminar partida '+position+'" title="Eliminar partida"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3m-9 0 1 14h10l1-14M10 11v6m4-6v6"/></svg></button></div>';
      return '<div class="item-card" data-row-id="' + row.id + '"><div class="item-top"><span class="item-index">PARTIDA ' + String(position).padStart(2, '0') + '</span>'+controls+'</div><label class="sr-only" for="description-' + row.id + '">Descripción de la partida ' + position + '</label><textarea id="description-' + row.id + '" data-field="description" maxlength="2000" rows="2" placeholder="Descripción del producto o servicio">' + engine.escape(row.description) + '</textarea><div class="item-grid"><label>Cantidad<input id="quantity-' + row.id + '" aria-label="Cantidad de la partida ' + position + '" data-field="quantity" type="text" inputmode="decimal" maxlength="14" value="' + engine.escape(row.quantity) + '"></label><label>Precio sin IVA (€)<input id="price-' + row.id + '" aria-label="Precio sin IVA de la partida ' + position + '" data-field="price" type="text" inputmode="decimal" maxlength="13" value="' + engine.escape(row.price) + '"></label><div><span class="line-total-label">Importe</span><div class="line-amount" id="line-' + row.id + '">0,00 €</div></div></div></div>';
    }).join('');
    update();
  }
  function moveRow(id, target) {
    const from = rows.findIndex(row=>row.id===id);
    if(from<0||!Number.isInteger(target)||target<0||target>=rows.length||from===target)return;
    const [row]=rows.splice(from,1);rows.splice(target,0,row);
    clearTimeout(timer);renderRows();clearError();closePdf();
    $('position-'+id).focus();
    $('item-order-status').textContent='Partida '+(from+1)+' movida a la posición '+(target+1)+'.';
  }
  function clearError() {
    $('form-error').hidden = true;
    if (errorField) {
      const described = (errorField.getAttribute('aria-describedby') || '').split(/\s+/).filter(id=>id&&id!=='form-error');
      if (described.length) errorField.setAttribute('aria-describedby',described.join(' '));
      else errorField.removeAttribute('aria-describedby');
      errorField.removeAttribute('aria-invalid');errorField=null;
    }
  }
  function showError(message, field) {
    clearError();
    setView('editor');
    const error=$('form-error');
    const before=field?.closest('[data-row-id], .form-section, .company-settings') || $('form-actions');
    before.before(error);error.textContent=message;error.hidden=false;
    document.querySelector('.workspace').classList.remove('preview-visible');
    $('preview-toggle').textContent = 'Vista previa';
    if (field) {
      errorField=field;
      field.setAttribute('aria-invalid','true');
      field.setAttribute('aria-describedby',[(field.getAttribute('aria-describedby')||''),'form-error'].filter(Boolean).join(' '));
      const company = field.closest('.company-settings'); if (company) company.open = true;
      field.focus({preventScroll:true}); error.scrollIntoView({ behavior: 'smooth', block: 'center' });
    } else { error.focus({preventScroll:true});error.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
  }
  function update() {
    let invalid = false;
    const safeRows = rows.map(row => {
      try {
        const cents = engine.lineCents(row);
        $('line-' + row.id).textContent = engine.euro(cents);
        $('quantity-' + row.id).setCustomValidity(''); $('price-' + row.id).setCustomValidity('');
        return row;
      } catch (error) {
        invalid = true;
        $('line-' + row.id).textContent = '—';
        try { const qty = engine.decimal(row.quantity, 3); if (qty === 0n) throw new Error(); $('quantity-' + row.id).setCustomValidity(''); }
        catch (_) { $('quantity-' + row.id).setCustomValidity('Ingresá una cantidad mayor que cero, con hasta 3 decimales.'); }
        try { engine.decimal(row.price, 2); $('price-' + row.id).setCustomValidity(''); }
        catch (_) { $('price-' + row.id).setCustomValidity('Ingresá un precio sin IVA con hasta 2 decimales.'); }
        return { ...row, quantity: '1', price: '0' };
      }
    });
    const data = readData();
    if (previewFootnote) previewFootnote.hidden = true;
    try {
      const amounts = engine.calculate(safeRows, data.tax);
      $('subtotal').textContent = invalid ? '—' : engine.euro(amounts.base);
      $('vat').textContent = invalid ? '—' : engine.euro(amounts.tax);
      $('total').textContent = invalid ? '—' : engine.euro(amounts.total);
      $('vat-label').textContent = 'IVA (' + data.tax + ' %)';
      if (ready && !invalid) {
        const doc = engine.layout(data);
        $('preview-pages').innerHTML = engine.svgPages(doc);
        $('page-count').textContent = 'A4 · ' + doc.pages.length + (doc.pages.length === 1 ? ' página' : ' páginas');
        if (previewFootnote) previewFootnote.hidden = false;
      } else if (ready && invalid) $('preview-pages').innerHTML = '<div class="preview-loading">Revisá las cantidades y precios para actualizar la vista previa.</div>';
    } catch (error) {
      $('subtotal').textContent = '—'; $('vat').textContent = '—'; $('total').textContent = '—';
      if (ready) $('preview-pages').innerHTML = '<div class="preview-loading">Revisá los importes para actualizar la vista previa.</div>';
    }
    updateNumberGuidance();
    updateSaveLabels();
  }
  function setType(newType) {
    if (!['quote', 'invoice'].includes(newType) || newType === type) return;
    termsByType[type] = $('payment-terms').value; notesByType[type] = $('notes').value;
    if (editingId && !loadingRecord) { editingId=null; editingRevision=null; requestId=uuid(); savedFingerprint=null; savedNumber=null; }
    type = newType;
    form.querySelector('input[name="documentType"][value="' + type + '"]').checked = true;
    $('payment-terms').value = termsByType[type]; $('notes').value = notesByType[type];
    closePdf();
    const invoice = type === 'invoice';
    $('quote-settings').hidden = invoice; $('clause-label').hidden = invoice;
    $('client-optional').textContent = invoice ? 'Obligatorio en factura' : 'Opcional en presupuesto';
    ['company-nif','company-address','company-postcode','company-city','client-name', 'client-nif', 'client-address', 'client-postcode', 'client-city'].forEach(id => { $(id).required = invoice; });
    $('number').placeholder = suggestions[type]?.next || (invoice ? 'Ej. 005-26' : 'Ej. 013-26');
    if (!loadingRecord && !editingId) useNumberSuggestion();
    clearError(); update(); updateSaveLabels();
  }
  function validate({exporting=false}={}) {
    for (const input of form.querySelectorAll('input, select, textarea')) {
      if (input.closest('[hidden]')) continue;
      if (exporting && type==='quote' && input.id==='number' && !input.value.trim()) continue;
      if (input.required && input.type !== 'radio' && !String(input.value).trim()) {
        const label = input.closest('label');
        showError('Completá ' + (label ? label.firstChild.textContent.trim().toLowerCase() : 'este dato') + ' para generar el documento.', input); return false;
      }
      if (!input.checkValidity()) {
        const row = input.closest('[data-row-id]');
        let message = input.validationMessage;
        if (input.validity.valueMissing) {
          const label = input.closest('label');
          message = 'Completá ' + (label ? label.firstChild.textContent.trim().toLowerCase() : 'este dato') + ' para generar el documento.';
        } else if (row && input.dataset.field) {
          message = input.dataset.field === 'quantity' ? 'La cantidad debe ser mayor que cero y tener hasta 3 decimales.' : 'El precio debe ser positivo o cero y tener hasta 2 decimales. Usá coma o punto, sin separadores de miles.';
        }
        showError(message, input); return false;
      }
    }
    const blank = rows.find(row => !row.description.trim());
    if (blank) { showError('Completá la descripción de cada partida.', $('description-' + blank.id)); return false; }
    try { engine.calculate(readData().items, Number(value('tax'))); }
    catch (error) { showError(error.message); return false; }
    if ((!value('number') && (!exporting||type==='invoice')) || !value('company-name') || (type==='invoice'&&(!value('company-nif')||!value('company-address')))) { showError('Completá el número de documento y los datos de la empresa.'); return false; }
    return true;
  }
  function toast(message) {
    $('toast').textContent = message; $('toast').hidden = false;
    clearTimeout(toastTimer); toastTimer = setTimeout(() => { $('toast').hidden = true; }, 4000);
  }
  function closePdf() {
    $('pdf-ready').hidden=true;
    $('pdf-draft-data').value='';
    $('pdf-draft-csrf').value='';
    $('pdf-draft-form').hidden=true;
    $('pdf-saved-actions').hidden=true;
    $('pdf-download-link').removeAttribute('href');
    $('pdf-open-link').removeAttribute('href');
  }
  $('pdf-dismiss').addEventListener('click',closePdf);
  window.addEventListener('pagehide',closePdf);
  function pdfTitle(kind) {
    $('pdf-ready-title').textContent=kind==='invoice'?'PDF de la factura':'PDF del presupuesto';
    $('pdf-ready').hidden=false;
  }
  function savedPdfUrl(id, inline=false) {
    return apiUrl('/api/documents/'+encodeURIComponent(id)+'/pdf'+(inline?'?view=1':''));
  }
  function showSavedPdf(id,kind) {
    closePdf();
    $('pdf-download-link').href=savedPdfUrl(id);
    $('pdf-open-link').href=savedPdfUrl(id,true);
    $('pdf-saved-actions').hidden=false;
    pdfTitle(kind);
  }
  function pdfBase64(bytes) {
    let binary='';
    for(let i=0;i<bytes.length;i+=8192) binary+=String.fromCharCode(...bytes.subarray(i,i+8192));
    return btoa(binary);
  }
  async function download() {
    if (busy) return;
    update();
    if (!validate({exporting:true})) return;
    const data = readData();
    if(editingId&&savedFingerprint===JSON.stringify(data)) {
      showSavedPdf(editingId,data.type);$('pdf-download-link').focus();return;
    }
    closePdf();
    busy = true; $('download-top').disabled = true; $('download-bottom').disabled = true;
    const draftId=requestId;
    try {
      let payload=JSON.stringify(data);
      if(phpApi) {
        if(!csrfToken)await loadBootstrap();
        if(!csrfToken)throw new Error('No se pudo comprobar tu sesión. Recargá la página y volvé a intentarlo.');
        await generatorReady;
        if(!ready)throw new Error('No se pudo cargar el generador de PDF. Recargá la página y volvé a intentarlo.');
        payload=JSON.stringify({type:data.type,number:data.number,pdf:pdfBase64(await engine.pdfBytes(window.PDFLib,engine.layout(data)))});
      }
      if(draftId!==requestId||view!=='editor'||JSON.stringify(readData())!==JSON.stringify(data))return;
      $('pdf-draft-data').value=payload;
      $('pdf-draft-csrf').value=csrfToken||'';
      $('pdf-draft-form').action=apiUrl('/api/draft.pdf');
      $('pdf-draft-open').formAction=apiUrl('/api/draft.pdf?view=1');
      $('pdf-draft-form').hidden=false;
      pdfTitle(data.type);
      clearError();
      $('pdf-draft-download').focus();
    } catch (error) { showError(!ready||!csrfToken?error.message:'No se pudo preparar el PDF. Tus datos siguen en el formulario. Volvé a intentarlo.'); }
    finally { busy = false; $('download-top').disabled = false; $('download-bottom').disabled = false; }
  }
  form.addEventListener('submit', event => { event.preventDefault(); void saveDocument(); });
  $('download-top').addEventListener('click', () => void download());
  $('download-bottom').addEventListener('click', () => void download());
  $('save-top').addEventListener('click', () => void saveDocument());
  form.addEventListener('input', event => {
    clearError();closePdf();
    if (event.target.id === 'number') numberIsAutomatic = false;
    if (event.target.id?.startsWith('company-')) companyTouched=true;
    if(event.target.id?.startsWith('client-')) {
      const pending=!!clientLookupController;
      resetClientLookup(event.target.id==='client-nif'?'Ingresá el DNI, NIF, CIF o NIE y pulsá Buscar cliente.':pending?'Búsqueda cancelada. Pulsá Buscar cliente para consultar de nuevo.':null);
      if(event.target.id==='client-nif')clientResolved=null;
    }
    const rowElement = event.target.closest('[data-row-id]');
    if (rowElement && event.target.dataset.field) {
      const row = rows.find(row => row.id === Number(rowElement.dataset.rowId));
      row[event.target.dataset.field] = event.target.value;
    }
    clearTimeout(timer); timer = setTimeout(update, 150);
  });
  form.addEventListener('change', event => {
    if(event.target.matches('[data-position]'))moveRow(Number(event.target.dataset.position),Number(event.target.value));
    else if (event.target.name === 'documentType') setType(event.target.value);
    else { update(); if(event.target.id==='date') void loadBootstrap(); }
  });
  $('add-item').addEventListener('click', () => {
    if(rows.length>=200){showError('Podés añadir hasta 200 partidas por documento.');return;}
    const row = { id: nextId++, description: '', quantity: '1', price: '0' };
    rows.push(row); renderRows(); $('description-' + row.id).focus();
  });
  $('items').addEventListener('click', event => {
    const move=event.target.closest('[data-move]');
    if(move){const id=Number(move.dataset.moveId),index=rows.findIndex(row=>row.id===id);moveRow(id,index+Number(move.dataset.move));return;}
    const remove = event.target.closest('[data-remove]'); if (!remove) return;
    const id = Number(remove.dataset.remove);
    rows = rows.filter(row => row.id !== id);
    if (!rows.length) rows = [{ id: nextId++, description: '', quantity: '1', price: '0' }];
    renderRows(); clearError();
  });
  $('preview-toggle').addEventListener('click', () => {
    const visible = document.querySelector('.workspace').classList.toggle('preview-visible');
    $('preview-toggle').textContent = visible ? 'Editar datos' : 'Vista previa';
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
  function year() { return Number(value('date').slice(0,4)) || Number(new Intl.DateTimeFormat('en',{timeZone:'Europe/Madrid',year:'numeric'}).format(new Date())); }
  async function api(path, options={}) {
    const response=await fetch(apiUrl(path),{credentials:'same-origin',...options,headers:{...(options.body?{'Content-Type':'application/json'}:{}),...(csrfToken?{'X-CSRF-Token':csrfToken}:{}),...(options.headers||{})}});
    let body;
    try {body=await response.json();} catch(_){throw new Error('No se pudo acceder al historial. Recargá la página y volvé a intentarlo.');}
    if(response.status===401){location.href=body.login||'entrar.php?brand='+encodeURIComponent(brandId);throw new Error('Tu sesión terminó.');}
    if(!response.ok) {const error=new Error(body.error||'No se pudo completar la operación.');error.details=body;throw error;}
    return body;
  }
  function setCompany(company) {for(const [key,val]of Object.entries(company)) {const input=$('company-'+key);if(input)input.value=val||'';}}
  function connectionError(message) {$('connection-message').textContent=message;$('connection-error').hidden=false;}
  async function loadBootstrap(applyCompany=false) {
    const version=++bootstrapVersion;
    const requestedYear = year();
    try {
      const result=await api('/api/bootstrap?year='+requestedYear);
      if(version!==bootstrapVersion)return;
      storeReady=true; suggestions=result.suggestions;
      csrfToken=result.csrfToken||csrfToken;uploadPdf=!!result.uploadPdf;
      $('history-count').textContent=String(result.counts.active);$('trash-count').textContent=String(result.counts.trash);
      if(applyCompany&&!companyTouched) {savedCompany=result.company;setCompany(savedCompany);}
      else if(applyCompany) savedCompany=result.company;
      if(applyCompany&&!draftRestored){draftRestored=true;restoreDraft();}
      if (year() !== requestedYear) return loadBootstrap();
      if (!editingId && (!value('number') || (type === 'quote' && numberIsAutomatic))) useNumberSuggestion();
      $('connection-error').hidden=true;update();renderSequenceCards();
    }catch(error){connectionError(error.message);}
  }
  function useNumberSuggestion() {
    const suggestion = suggestions[type];
    if (!suggestion) return;
    if (value('number') !== suggestion.next) closePdf();
    $('number').value = suggestion.next;
    numberIsAutomatic = true;
  }
  function updateNumberGuidance() {
    const suggestion=suggestions[type];
    if(!suggestion)return;
    const lastLabel = type === 'quote' ? 'Último activo: ' : 'Último guardado: ';
    const emptyLabel = type === 'quote' ? 'Sin presupuestos activos en esta serie · ' : 'Sin documentos guardados en esta serie · ';
    $('number-guidance').textContent=(suggestion.last?lastLabel+suggestion.last+' · ':emptyLabel)+'Siguiente: '+suggestion.next;
    $('use-suggestion').hidden=!!editingId||value('number')===suggestion.next;
  }
  function updateSaveLabels() {
    $('save-top-label').textContent=saving?'Guardando…':editingId?'Guardar cambios':'Guardar';
    $('save-bottom-label').textContent=saving?'Guardando…':editingId?'Guardar cambios':'Guardar documento';
    $('save-top').disabled=saving;$('save-bottom').disabled=saving;
    $('editing-status').hidden=!editingId;
    $('editing-status-text').textContent=(type==='invoice'?'Factura ':'Presupuesto ')+(savedNumber||value('number'))+' · '+(savedFingerprint===JSON.stringify(readData())?'Guardado en el historial':'Cambios sin guardar');
    $('convert-document').hidden=type!=='quote';
    $('document-source').hidden=!documentSource;
    $('document-source').textContent=documentSource?(documentSource.action==='conversion'?'Factura creada desde el presupuesto ':'Copia de '+(documentSource.type==='quote'?'presupuesto ':'factura '))+documentSource.number+'. El documento original se conserva.':'';
  }
  async function saveDocument() {
    if(saving)return;update();if(!validate())return;
    if(!storeReady) {await loadBootstrap();if(!storeReady){showError('No se pudo conectar con el historial. Tus datos siguen en el formulario. Volvé a intentarlo.');return;}}
    saving=true;updateSaveLabels();
    try {
      const data=readData(), currentRequest=requestId, currentEditing=editingId;
      let pdf;
      if(uploadPdf){if(!ready)throw new Error('Esperá a que se prepare el PDF.');pdf=pdfBase64(await engine.pdfBytes(window.PDFLib,engine.layout(data)));}
      const result=await api(editingId?'/api/documents/'+editingId:'/api/documents',{method:editingId?'PUT':'POST',body:JSON.stringify({data,id:requestId,revision:editingRevision,pdfBase64:pdf})});
      if(requestId===currentRequest&&editingId===currentEditing){editingId=result.document.id;editingRevision=result.document.revision;savedFingerprint=JSON.stringify(data);savedNumber=result.document.number;}
      suggestions=result.suggestions;
      toast((data.type==='invoice'?'Factura ':'Presupuesto ')+result.document.number+' guardado en el historial.');
      await loadBootstrap();
      if(['history','trash'].includes(view))await loadArchive();
    }catch(error){if(error.details?.suggestions)suggestions=error.details.suggestions;showError(error.message,$('number'));}
    finally{saving=false;updateSaveLabels();updateNumberGuidance();}
  }
  async function saveCompany() {
    const controls=document.querySelector('.company-fields').querySelectorAll('input');
    for(const input of controls)if(!input.checkValidity()||(input.required&&!input.value.trim())){showError('Revisá los datos de esta marca.',input);return;}
    const button=$('save-company');button.disabled=true;button.textContent='Guardando…';
    try{const result=await api('/api/company',{method:'PUT',body:JSON.stringify({company:readCompany()})});savedCompany=result.company;companyTouched=false;toast('Datos de la marca guardados.');}
    catch(error){showError(error.message);}
    finally{button.disabled=false;button.textContent='Guardar datos de empresa';}
  }
  function setView(next) {
    const changed=view!==next;view=next;document.body.dataset.view=view;
    if(changed)closePdf();
    document.querySelector('.workspace').hidden=view!=='editor';$('archive-page').hidden=!['history','trash'].includes(view);
    $('clients-page').hidden=view!=='clients';$('reports-page').hidden=view!=='reports';
    document.querySelectorAll('.nav-tab[data-view]').forEach(button=>{const active=button.dataset.view===view;button.classList.toggle('active',active);if(active)button.setAttribute('aria-current','page');else button.removeAttribute('aria-current');});
    if(view==='editor')return;
    if(view==='clients'||view==='reports'){if(changed){if(view==='clients')void loadClients();else void loadReport();window.scrollTo({top:0,behavior:'smooth'});}return;}
    if(changed){filter='';document.querySelectorAll('[data-filter]').forEach(button=>button.classList.toggle('active',button.dataset.filter===''));}
    const trash=view==='trash';
    $('archive-title').textContent=trash?'Papelera':'Historial';
    $('archive-eyebrow').textContent=trash?'RECUPERACIÓN DE DOCUMENTOS':'DOCUMENTOS GUARDADOS';
    $('archive-description').textContent=trash?'Podés recuperar cada documento durante 30 días desde su eliminación.':'Tus presupuestos y facturas, siempre disponibles.';
    $('sequence-cards').hidden=trash;
    renderSequenceCards();
    if(changed){void loadBootstrap();void loadArchive();window.scrollTo({top:0,behavior:'smooth'});}
  }
  function renderSequenceCards() {
    $('sequence-cards').innerHTML=['quote','invoice'].map(kind=>{const s=suggestions[kind];return '<article class="sequence-card"><div class="sequence-card-top"><span class="document-kind '+kind+'">'+(kind==='quote'?'Presupuestos':'Facturas')+'</span><span>Serie '+year()+'</span></div><div class="sequence-number">'+engine.escape(s?.next||'—')+'<small>Siguiente número</small></div><div class="sequence-card-bottom"><span>Último: <strong>'+engine.escape(s?.last||'—')+'</strong></span><button type="button" data-new-type="'+kind+'">'+(kind==='quote'?'Nuevo presupuesto':'Nueva factura')+'</button></div></article>';}).join('');
  }
  const icons={duplicate:'<rect x="8" y="8" width="12" height="13" rx="1"/><path d="M16 8V3H3v13h5"/>',conversion:'<path d="M13 3h7v7M20 3l-8 8M9 3H4v18h16v-8M8 15h7m-7 3h7"/>',download:'<path d="M12 3v12m-4-4 4 4 4-4M5 16v5h14v-5"/>',edit:'<path d="m15 4 5 5M4 20l4-1L20 7l-4-4L4 15z"/>',trash:'<path d="M4 7h16M9 7V4h6v3m-9 0 1 14h10l1-14M10 11v6m4-6v6"/>',restore:'<path d="M3 5v6h6M4 10a8 8 0 1 1 1 9M12 7v5l3 2"/>'};
  function actionButton(action,row,label,visible='') {return '<button type="button" class="archive-action '+action+'" data-action="'+action+'" data-id="'+row.id+'" aria-label="'+engine.escape(label+' '+row.number)+'" title="'+engine.escape(label)+'"><svg viewBox="0 0 24 24" aria-hidden="true">'+icons[action]+'</svg>'+(visible?'<span>'+visible+'</span>':'')+'</button>';}
  function pdfLink(row, inReport=false) {
    return '<a class="'+(inReport?'button secondary':'archive-action download')+'" href="'+engine.escape(savedPdfUrl(row.id))+'" data-pdf-id="'+engine.escape(row.id)+'" data-pdf-type="'+(row.type==='invoice'?'invoice':'quote')+'" aria-label="'+engine.escape('Descargar PDF '+row.number)+'" title="Descargar PDF">'+(inReport?'PDF':'<svg viewBox="0 0 24 24" aria-hidden="true">'+icons.download+'</svg>')+'</a>';
  }
  function displayPdfOptions(event) {
    const link=event.target.closest('[data-pdf-id]');
    if(link)showSavedPdf(link.dataset.pdfId,link.dataset.pdfType);
  }
  function renderArchive() {
    if(!archiveRows.length){$('archive-list').innerHTML='<div class="archive-empty"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h9l4 4v14H6zM14 3v5h5M9 12h7m-7 4h5"/></svg><h2>'+(view==='trash'?'La papelera está vacía':'Todavía no hay documentos'+(filter?' de este tipo':''))+'</h2><p>'+(view==='trash'?'Los documentos eliminados aparecerán aquí durante 30 días.':'Guardá un presupuesto o una factura para verlo aquí.')+'</p></div>';return;}
    $('archive-list').innerHTML=archiveRows.map(row=>{
      const name=row.type==='invoice'?'Factura':'Presupuesto';
      const expiry=row.expiresAt?new Intl.DateTimeFormat('es-ES',{timeZone:'Europe/Madrid',day:'2-digit',month:'2-digit',year:'numeric'}).format(new Date(row.expiresAt)):'';
      return '<article class="archive-row"><div class="archive-document"><span class="document-kind '+row.type+'">'+name+'</span><strong>'+engine.escape(row.number)+'</strong><span class="archive-date">'+engine.formatDate(row.date)+'</span></div><div class="archive-client"><strong>'+engine.escape(row.clientName||'Sin cliente indicado')+'</strong><span>'+(view==='trash'?'Recuperable hasta '+expiry:'IVA '+row.taxRate+' % · Base '+engine.euro(row.base))+'</span></div><div class="archive-amount">'+engine.euro(row.total)+'<small>IVA incluido</small></div><div class="archive-actions">'+(view==='trash'?actionButton('restore',row,'Recuperar','Recuperar'):actionButton('edit',row,'Editar documento')+actionButton('duplicate',row,'Duplicar documento','Duplicar')+(row.type==='quote'?actionButton('conversion',row,'Convertir en factura','Facturar'):''))+pdfLink(row)+(view==='trash'?'':actionButton('trash',row,'Enviar a la papelera'))+'</div></article>';
    }).join('');
  }
  async function loadArchive(more=false) {
    if(!['history','trash'].includes(view))return;
    const version=++archiveVersion, currentView=view;
    $('archive-error').hidden=true;$('load-more').disabled=true;
    if(!more)$('archive-list').innerHTML='<div class="archive-empty">Cargando documentos…</div>';
    try {
      const result=await api('/api/documents?status='+(view==='trash'?'trash':'active')+(filter?'&type='+filter:'')+'&offset='+(more?(archiveNext||0):0));
      if(version!==archiveVersion||currentView!==view)return;
      archiveRows=more?[...archiveRows,...result.documents]:result.documents;archiveNext=result.nextOffset;
      $('archive-total').textContent=result.total+' '+(result.total===1?'documento':'documentos');
      $('load-more').hidden=archiveNext===null;renderArchive();
    }catch(error){if(version===archiveVersion){$('archive-error').textContent=error.message;$('archive-error').hidden=false;if(!more)$('archive-list').innerHTML='';}}
    finally{$('load-more').disabled=false;}
  }
  function resetDocument(kind=type) {
    if(saving){toast('Esperá a que termine el guardado.');return;}
    closePdf();documentSource=null;clientResolved=null;resetClientLookup('Ingresá el DNI, NIF, CIF o NIE y pulsá Buscar cliente.');editingId=null;editingRevision=null;requestId=uuid();savedFingerprint=null;savedNumber=null;loadingRecord=true;setType(kind);loadingRecord=false;
    $('date').value=new Intl.DateTimeFormat('sv-SE',{timeZone:'Europe/Madrid',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
    $('number').value='';numberIsAutomatic=true;$('tax').value='21';
    clientFields.forEach(key=>{$('client-'+key).value='';});
    $('payment-method').value='';$('payment-terms').value='';
    $('notes').value='';
    $('clause').value=clauseDefault;$('validity').value='30';setCompany(savedCompany);companyTouched=false;
    rows=[{id:nextId++,description:'',quantity:'1',price:'0'}];renderRows();clearError();updateSaveLabels();setView('editor');
    document.querySelector('.workspace').classList.remove('preview-visible');$('preview-toggle').textContent='Vista previa';
    void loadBootstrap();$('number').focus();window.scrollTo({top:0,behavior:'smooth'});
  }
  async function openDocument(id, mode='edit') {
    if(saving){throw new Error('Esperá a que termine el guardado antes de abrir otro documento.');}
    const result=await api('/api/documents/'+id),data=result.data;
    if(mode==='conversion'&&data.type!=='quote')throw new Error('Solo se pueden convertir presupuestos.');
    closePdf();documentSource=data.source||null;clientResolved=null;resetClientLookup();
    loadingRecord=true;setType(data.type);loadingRecord=false;
    editingId=result.document.id;editingRevision=result.document.revision;
    $('number').value=data.number;numberIsAutomatic=false;$('date').value=data.date;$('tax').value=String(data.tax);
    clientFields.forEach(key=>{$('client-'+key).value=data.client[key]||'';});$('client-lookup-status').textContent='Datos del cliente conservados en este documento.';
    setCompany(data.company);$('company-iban').value=data.company.iban??data.iban??'';
    $('payment-method').value=data.paymentMethod;$('payment-terms').value=data.paymentTerms;$('notes').value=data.notes;$('clause').value=data.clause;$('validity').value=data.validity;
    rows=data.items.map(row=>({...row,id:nextId++}));renderRows();clearError();updateSaveLabels();setView('editor');
    savedFingerprint=JSON.stringify(readData());savedNumber=data.number;
    if(mode!=='edit'){
      documentSource={id:result.document.id,type:data.type,number:data.number,action:mode==='conversion'?'conversion':'duplicate'};
      if(mode==='conversion'){loadingRecord=true;setType('invoice');loadingRecord=false;$('payment-terms').value='';$('notes').value='';}
      editingId=null;editingRevision=null;requestId=uuid();savedFingerprint=null;savedNumber=null;
      $('date').value=new Intl.DateTimeFormat('sv-SE',{timeZone:'Europe/Madrid',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());$('number').value='';
      numberIsAutomatic = true;
      await loadBootstrap();
      if(!value('number'))throw new Error('No se pudo obtener el nuevo número. Volvé a intentarlo.');
      toast(mode==='conversion'?'Factura preparada. Revisá los datos y guardala; el presupuesto se conserva.':'Copia preparada con un número nuevo. Revisala y guardala.');
    }
    update();updateSaveLabels();
    document.querySelector('.workspace').classList.remove('preview-visible');$('preview-toggle').textContent='Vista previa';void loadBootstrap();window.scrollTo({top:0,behavior:'smooth'});
  }
  document.querySelectorAll('.nav-tab[data-view]').forEach(button=>{
    button.addEventListener('click',()=>{
      const next=button.dataset.view;
      setView(next);
      if(next==='editor')window.scrollTo({top:0,behavior:'auto'});
    });
  });

  const appHeader=document.querySelector('.app-header');
  if(appHeader&&typeof ResizeObserver==='function'){
    const measureHeader=()=>document.documentElement.style.setProperty('--app-header-height',Math.ceil(appHeader.getBoundingClientRect().height)+'px');
    measureHeader();
    new ResizeObserver(measureHeader).observe(appHeader);
  }
  document.querySelectorAll('[data-filter]').forEach(button=>button.addEventListener('click',()=>{filter=button.dataset.filter;document.querySelectorAll('[data-filter]').forEach(item=>item.classList.toggle('active',item===button));void loadArchive();}));
  $('new-document').addEventListener('click',()=>resetDocument());$('leave-edit').addEventListener('click',()=>resetDocument());
  $('use-suggestion').addEventListener('click',()=>{useNumberSuggestion();update();});
  $('save-company').addEventListener('click',()=>void saveCompany());
  $('retry-connection').addEventListener('click',()=>{void loadBootstrap(!companyTouched);if(view!=='editor')void loadArchive();});
  $('refresh-archive').addEventListener('click',()=>{void loadBootstrap();void loadArchive();});$('load-more').addEventListener('click',()=>void loadArchive(true));
  $('sequence-cards').addEventListener('click',event=>{const button=event.target.closest('[data-new-type]');if(button)resetDocument(button.dataset.newType);});
  $('archive-list').addEventListener('click',displayPdfOptions);
  $('archive-list').addEventListener('click',async event=>{
    const button=event.target.closest('[data-action]');if(!button)return;button.disabled=true;
    const {action,id}=button.dataset;
    try {
      if(action==='edit')await openDocument(id);
      else if(action==='duplicate'||action==='conversion')await openDocument(id,action);
      else {
        const result = await api('/api/documents/'+id+(action==='restore'?'/restore':''),{method:action==='restore'?'POST':'DELETE'});
        if (action === 'trash' && editingId === id) {
          numberIsAutomatic = type === 'quote' && value('number') === result.document.number;
          editingId = null;
          editingRevision = null;
          requestId = uuid();
          savedFingerprint = null;
          savedNumber = null;
          updateSaveLabels();
        }
        toast(action==='restore'?'Documento recuperado.':'Documento enviado a la papelera. Podés recuperarlo durante 30 días.');
        await loadBootstrap();await loadArchive();
      }
    }catch(error){if(view==='editor')showError(error.message);else{$('archive-error').textContent=error.message;$('archive-error').hidden=false;}}
    finally{button.disabled=false;}
  });
  function clientKey(nif) {let key=String(nif||'').trim().toUpperCase().replace(/[\s.\-]/g,'');return /^ES[A-Z0-9]{9}$/.test(key)?key.slice(2):key;}
  function resetClientLookup(message=null) {
    clientLookupVersion++;clientLookupController?.abort();clientLookupController=null;
    $('lookup-client').disabled=false;$('lookup-client').textContent='Buscar cliente';
    $('client-search-results').hidden=true;lookupRows=[];
    if(message!==null)$('client-lookup-status').textContent=message;
  }
  function fillClient(client) {
    resetClientLookup();
    clientFields.forEach(key=>{$('client-'+key).value=client[key]||'';});
    $('client-search').value='';
    clientResolved=client.id?client:null;
    $('client-lookup-status').textContent=client.id?'Cliente recuperado: '+client.name+'.':'Datos del cliente cargados.';
    update();
  }
  async function lookupClient() {
    clearTimeout(searchTimer);
    resetClientLookup();
    const key=value('client-search')||value('client-nif')||value('client-name'),version=++clientLookupVersion,context=requestId;
    clientResolved=null;
    if(key.length<2){$('client-lookup-status').textContent='Escribí al menos dos caracteres del nombre o DNI.';$('client-search').focus();return;}
    const controller=new AbortController();clientLookupController=controller;
    $('lookup-client').disabled=true;$('lookup-client').textContent='Buscando…';
    $('client-lookup-status').textContent='Buscando cliente…';
    try {
      const result=await api('/api/clients?search='+encodeURIComponent(key),{signal:controller.signal});
      if(version!==clientLookupVersion||context!==requestId)return;
      lookupRows=result.clients;
      if(!lookupRows.length){$('client-lookup-status').textContent='No hay coincidencias en esta marca. Podés agregar un cliente nuevo.';return;}
      $('client-search-results').innerHTML=lookupRows.map((client,index)=>'<li><button type="button" data-lookup-index="'+index+'"><strong>'+engine.escape(client.name)+'</strong><span>'+engine.escape(client.nif)+'</span></button></li>').join('');
      $('client-search-results').hidden=false;
      $('client-lookup-status').textContent=result.total+' coincidencia'+(result.total===1?'':'s')+'. Elegí el cliente para cargar sus datos.';
    }catch(error){if(error.name!=='AbortError'&&version===clientLookupVersion)$('client-lookup-status').textContent='No se pudo consultar el cliente. '+error.message;}
    finally{if(clientLookupController===controller){clientLookupController=null;$('lookup-client').disabled=false;$('lookup-client').textContent='Buscar cliente';}}
  }
  $('lookup-client').addEventListener('click',()=>void lookupClient());
  $('client-search').addEventListener('input',()=>{clearTimeout(searchTimer);if(value('client-search').length>=2)searchTimer=setTimeout(()=>void lookupClient(),300);});
  $('client-search').addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();clearTimeout(searchTimer);void lookupClient();}});
  $('client-search-results').addEventListener('click',event=>{const button=event.target.closest('[data-lookup-index]');if(button){const client=lookupRows[Number(button.dataset.lookupIndex)];if(client){fillClient(client);persistDraft();}}});
  function clientError(message){$('clients-error').textContent=message;$('clients-error').hidden=false;}
  function renderClients() {
    $('clients-list').innerHTML=clientsRows.length?clientsRows.map(client=>'<article class="client-row"><div><strong>'+engine.escape(client.name)+'</strong><span>'+engine.escape(client.nif)+'</span><p>'+engine.escape([client.address,client.postcode,client.city].filter(Boolean).join(' · '))+'</p><small>'+engine.escape([client.email,client.phone].filter(Boolean).join(' · '))+'</small></div><div class="client-row-actions"><button type="button" class="button secondary" data-client-edit="'+client.id+'">Editar</button><button type="button" class="button secondary" data-client-use="'+client.id+'">Usar en documento</button></div></article>').join(''):'<div class="archive-empty"><h2>No hay clientes'+(value('clients-search')?' con esta búsqueda':' guardados')+'</h2><p>Podés guardarlos desde el documento o con Nuevo cliente.</p></div>';
  }
  async function loadClients(more=false) {
    const version=++clientsVersion,search=more?clientsQuery:value('clients-search');$('clients-error').hidden=true;$('clients-more').disabled=true;
    if(!more)$('clients-list').innerHTML='<div class="archive-empty">Cargando clientes…</div>';
    try{
      const result=await api('/api/clients?search='+encodeURIComponent(search)+'&offset='+(more?(clientsNext||0):0));
      if(version!==clientsVersion)return;
      clientsRows=more?[...clientsRows,...result.clients]:result.clients;clientsNext=result.nextOffset;clientsQuery=search;
      $('clients-total').textContent=result.total+' '+(result.total===1?'cliente':'clientes');$('clients-more').hidden=clientsNext===null;renderClients();
    }catch(error){if(version===clientsVersion){clientError(error.message);if(!more)$('clients-list').innerHTML='';}}
    finally{if(version===clientsVersion)$('clients-more').disabled=false;}
  }
  function openClientDialog(client={},fromDocument=false) {
    clientModal={id:client.id||null,revision:client.revision||null,fromDocument,documentRequestId:requestId,requestId:uuid()};
    clientFields.forEach(key=>{$('cd-'+key).value=client[key]||'';});$('client-form-error').hidden=true;
    $('client-dialog-title').textContent=client.id?'Actualizar cliente':'Guardar cliente';$('client-submit').textContent=client.id?'Guardar cambios':'Guardar cliente';
    $('client-dialog').showModal();
  }
  $('client-form').addEventListener('submit',async event=>{
    event.preventDefault();const button=$('client-submit');if(button.disabled||!clientModal)return;button.disabled=true;
    const modal=clientModal,client=Object.fromEntries(clientFields.map(key=>[key,value('cd-'+key)]));
    try{
      const result=await api('/api/clients'+(modal.id?'/'+modal.id:''),{method:modal.id?'PUT':'POST',body:JSON.stringify({id:modal.requestId,revision:modal.revision,client})});
      if(modal.fromDocument&&modal.documentRequestId===requestId)fillClient(result.client);$('client-dialog').close();toast('Cliente guardado. Ya podés recuperarlo con su identificación.');
      if(view==='clients')await loadClients();
    }catch(error){$('client-form-error').textContent=error.message;$('client-form-error').hidden=false;}
    finally{button.disabled=false;}
  });
  ['client-dialog-close','client-dialog-cancel'].forEach(id=>$(id).addEventListener('click',()=>{$('client-dialog').close();}));
  $('new-client').addEventListener('click',()=>openClientDialog());
  $('save-client-from-document').addEventListener('click',async()=>{
    const client=readData().client;
    if(!client.nif){showError('Completá el DNI, NIF, CIF o NIE para guardar el cliente.',$('client-nif'));return;}
    const button=$('save-client-from-document');button.disabled=true;
    try{const result=await api('/api/clients/lookup?nif='+encodeURIComponent(client.nif));openClientDialog({...client,...(result.client?{id:result.client.id,revision:result.client.revision}:{})},true);}
    catch(error){showError(error.message);}
    finally{button.disabled=false;}
  });
  $('clients-search-button').addEventListener('click',()=>void loadClients());
  $('clients-search').addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();void loadClients();}});
  $('clients-more').addEventListener('click',()=>void loadClients(true));
  $('clients-list').addEventListener('click',event=>{
    const button=event.target.closest('[data-client-edit],[data-client-use]');if(!button)return;
    const client=clientsRows.find(row=>row.id===(button.dataset.clientEdit||button.dataset.clientUse));if(!client)return;
    if(button.dataset.clientEdit)openClientDialog(client);
    else {fillClient(client);setView('editor');$('client-name').focus();toast('Cliente cargado en el documento abierto.');}
  });
  function reportParams(){return 'year='+encodeURIComponent(value('report-year'))+'&quarter='+encodeURIComponent(value('report-quarter'));}
  function reportError(message){$('reports-error').textContent=message;$('reports-error').hidden=false;}
  function setReportDownloads(enabled){for(const format of ['zip','csv']){const link=$('report-'+format);if(enabled)link.href=apiUrl('/api/reports/quarter.'+format+'?'+reportPeriod);else link.removeAttribute('href');link.setAttribute('aria-disabled',String(!enabled));}}
  function renderReportRows() {
    $('report-list').innerHTML=reportRows.length?reportRows.map(row=>'<article class="report-row"><div><strong>Factura '+engine.escape(row.number)+'</strong><span>'+engine.formatDate(row.date)+'</span></div><div class="report-client">'+engine.escape(row.clientName)+'</div><div class="report-amount"><strong>'+engine.euro(row.total)+'</strong><small>Base '+engine.euro(row.base)+' · IVA '+engine.euro(row.tax)+'</small></div>'+pdfLink(row,true)+'</article>').join(''):'<div class="archive-empty"><h2>No hay facturas en este trimestre</h2><p>Las facturas guardadas con fecha de este período aparecerán aquí.</p></div>';
  }
  async function loadReport(more=false) {
    const yearInput=$('report-year');if(!yearInput.checkValidity()||!value('report-year')){reportError('Ingresá un año entre 1900 y 2199.');setReportDownloads(false);return;}
    const version=++reportVersion,params=reportParams();$('reports-error').hidden=true;$('reports-more').disabled=true;setReportDownloads(false);
    if(!more){reportPeriod=null;$('report-summary').innerHTML='';$('report-rates').innerHTML='';$('report-caption').textContent='';$('reports-more').hidden=true;$('report-list').innerHTML='<div class="archive-empty">Consultando trimestre…</div>';}
    try{
      const result=await api('/api/reports/quarter?'+params+'&offset='+(more?(reportNext||0):0));
      if(version!==reportVersion||params!==reportParams())return;
      reportPeriod=params;reportRows=more?[...reportRows,...result.documents]:result.documents;reportNext=result.nextOffset;
      const summary=result.summary;
      $('report-summary').innerHTML=[['Facturas',String(summary.count)],['Base imponible',engine.euro(summary.base)],['IVA repercutido',engine.euro(summary.tax)],['Total facturado',engine.euro(summary.total)]].map(([label,amount])=>'<article><span>'+label+'</span><strong>'+amount+'</strong></article>').join('');
      $('report-rates').innerHTML=result.byRate.length?'<div class="report-rate-list">'+result.byRate.map(rate=>'<span><strong>IVA '+rate.rate+' %</strong> · Base '+engine.euro(rate.base)+' · Cuota '+engine.euro(rate.tax)+'</span>').join('')+'</div>':'';
      $('report-caption').textContent='Trimestre '+result.range.quarter+' de '+result.range.year+' · '+summary.count+' '+(summary.count===1?'factura emitida':'facturas emitidas');
      $('reports-more').hidden=reportNext===null;setReportDownloads(summary.count>0);renderReportRows();
    }catch(error){if(version===reportVersion){reportError(error.message);if(!more)$('report-list').innerHTML='';}}
    finally{if(version===reportVersion)$('reports-more').disabled=false;}
  }
  const todayParts=new Intl.DateTimeFormat('sv-SE',{timeZone:'Europe/Madrid',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date()).split('-');
  $('report-year').value=todayParts[0];$('report-quarter').value=String(Math.ceil(Number(todayParts[1])/3));
  ['report-year','report-quarter'].forEach(id=>$(id).addEventListener('change',()=>void loadReport()));
  $('report-year').addEventListener('input',()=>{reportVersion++;setReportDownloads(false);});
  $('report-refresh').addEventListener('click',()=>void loadReport());$('reports-more').addEventListener('click',()=>void loadReport(true));
  $('report-list').addEventListener('click',displayPdfOptions);
  ['duplicate-document','convert-document'].forEach(buttonId=>$(buttonId).addEventListener('click',async()=>{
    if(!editingId||saving)return;
    if(savedFingerprint!==JSON.stringify(readData())){showError('Guardá los cambios antes de crear la copia.');return;}
    const button=$(buttonId);button.disabled=true;try{await openDocument(editingId,buttonId==='convert-document'?'conversion':'duplicate');}catch(error){showError(error.message);}finally{button.disabled=false;}
  }));
  function registerTools() {
    const context = document.modelContext;
    if (!context || typeof context.registerTool !== 'function') return;
    const lifecycle = new AbortController();
    const inputSchema = {
      type: 'object', additionalProperties: false,
      properties: {
        type: { type: 'string', enum: ['quote', 'invoice'] }, number: { type: 'string', maxLength: 40 }, date: { type: 'string', pattern: '^\\d{4}-\\d{2}-\\d{2}$' }, tax: { type: 'integer', enum: [0,4,10,21] },
        client: { type: 'object', additionalProperties: false, properties: Object.fromEntries(['name', 'nif', 'address', 'postcode', 'city'].map(key => [key, { type: 'string', maxLength: 220 }])) },
        items: { type: 'array', minItems: 1, maxItems: 100, items: { type: 'object', additionalProperties: false, required: ['description', 'quantity', 'price'], properties: { description: { type: 'string', minLength: 1, maxLength: 2000 }, quantity: { type: 'string' }, price: { type: 'string' } } } }
      }
    };
    const tools = [
      { name: 'read_document', title: 'Consultar documento', description: 'Devuelve los datos y el desglose del documento abierto, sin modificarlo.', inputSchema: { type: 'object', properties: {}, additionalProperties: false }, annotations: { readOnlyHint: true, untrustedContentHint: true }, execute(input) { if (!input || Object.keys(input).length) throw new Error('No se esperan parámetros.'); const data = readData(); return { ...data, amounts: engine.calculate(data.items, data.tax) }; } },
      { name: 'configure_document', title: 'Completar presupuesto o factura', description: 'Carga datos y partidas en el formulario visible; devuelve el desglose. No descarga ni emite el PDF.', inputSchema, annotations: { readOnlyHint: false, untrustedContentHint: true }, execute(input) {
        if (!input || typeof input !== 'object' || Array.isArray(input)) throw new Error('Datos no válidos.');
        const allowed = ['type', 'number', 'date', 'tax', 'client', 'items'];
        if (Object.keys(input).some(key => !allowed.includes(key))) throw new Error('Campo desconocido.');
        if (input.type !== undefined && !['quote', 'invoice'].includes(input.type)) throw new Error('Tipo no válido.');
        if (input.tax !== undefined && ![0,4,10,21].includes(input.tax)) throw new Error('IVA no válido.');
        if (input.number !== undefined && (typeof input.number !== 'string' || input.number.length > 40)) throw new Error('Número no válido.');
        if (input.date !== undefined && (typeof input.date !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(input.date) || new Date(input.date + 'T12:00:00Z').toISOString().slice(0,10) !== input.date)) throw new Error('Fecha no válida.');
        if (input.client !== undefined && (!input.client || typeof input.client !== 'object' || Array.isArray(input.client) || Object.entries(input.client).some(([key, val]) => !['name','nif','address','postcode','city'].includes(key) || typeof val !== 'string' || val.length > 220))) throw new Error('Cliente no válido.');
        if (input.items !== undefined) {
          if (!Array.isArray(input.items) || !input.items.length || input.items.length > 100 || input.items.some(row => !row || typeof row.description !== 'string' || !row.description.trim() || row.description.length > 2000 || typeof row.quantity !== 'string' || typeof row.price !== 'string' || Object.keys(row).some(key => !['description','quantity','price'].includes(key)))) throw new Error('Partidas no válidas.');
          engine.calculate(input.items, input.tax ?? Number(value('tax')));
        }
        if (input.type) setType(input.type);
        if (input.number !== undefined) {
          $('number').value = input.number;
          numberIsAutomatic = false;
        }
        if (input.date !== undefined) $('date').value = input.date;
        if (input.tax !== undefined) $('tax').value = String(input.tax);
        if (input.client) Object.entries(input.client).forEach(([key,val]) => { $('client-' + key).value = val; });
        if (input.items) { rows = input.items.map(row => ({ ...row, id: nextId++ })); renderRows(); }
        update(); clearError();
        return { type, number: value('number'), ...engine.calculate(readData().items, Number(value('tax'))) };
      } }
    ];
    for (const tool of tools) {
      try { Promise.resolve(context.registerTool(tool, { signal: lifecycle.signal })).catch(() => {}); } catch (_) {}
    }
    window.addEventListener('pagehide', () => lifecycle.abort(), { once: true });
  }
  function persistDraft() {
    if(!storeReady||loadingRecord)return;
    try{sessionStorage.setItem(draftKey,JSON.stringify({data:readData(),numberIsAutomatic,editingId,editingRevision,requestId,savedFingerprint,savedNumber,time:Date.now()}));}catch(_){}
  }
  function restoreDraft() {
    try{
      const raw=sessionStorage.getItem(draftKey);if(!raw)return;
      const draft=JSON.parse(raw),data=draft.data;
      if(!data||data.brand!==brandId||Date.now()-draft.time>8*3600000||!['quote','invoice'].includes(data.type)||!Array.isArray(data.items)||!data.items.length||data.items.length>200){sessionStorage.removeItem(draftKey);return;}
      loadingRecord=true;setType(data.type);
      for(const key of ['number','date','tax','notes','clause','validity'])$(key).value=data[key]??'';
      numberIsAutomatic = draft.numberIsAutomatic === true;
      clientFields.forEach(key=>{$('client-'+key).value=data.client?.[key]||'';});
      setCompany(data.company);companyTouched=true;
      $('payment-method').value=data.paymentMethod||'';$('payment-terms').value=data.paymentTerms||'';
      documentSource=data.source||null;editingId=draft.editingId||null;editingRevision=draft.editingRevision||null;
      requestId=draft.requestId||uuid();savedFingerprint=draft.savedFingerprint||null;savedNumber=draft.savedNumber||null;
      rows=data.items.map(row=>({...row,id:nextId++}));renderRows();updateSaveLabels();
    }catch(_){try{sessionStorage.removeItem(draftKey);}catch(_){}}
    finally{loadingRecord=false;}
  }
  $('switch-brand').addEventListener('click',()=>{persistDraft();$('brand-dialog').showModal();});
  $('brand-dialog-close').addEventListener('click',()=>$('brand-dialog').close());
  document.querySelectorAll('.switch-brand-link,.all-brands').forEach(link=>link.addEventListener('click',event=>{if(saving||busy){event.preventDefault();toast('Esperá a que termine la operación.');return;}persistDraft();}));
  window.addEventListener('pagehide',persistDraft);
  renderRows();
  updateSaveLabels();
  void loadBootstrap(true);
  const generatorReady=(async () => {
    try {
      const [image,font]=await Promise.all([
        fetch(apiUrl('/api/pdf-logo'), {cache:'no-store', credentials:'same-origin'}),
        fetch(new URL('document-font.ttf',assetBase)),
      ]);
      if (!image.ok) throw new Error('No se pudo cargar el logo.');
      const fallback=font.ok&&window.fontkit?{kit:window.fontkit,bytes:new Uint8Array(await font.arrayBuffer())}:undefined;
      await engine.init(window.PDFLib,new Uint8Array(await image.arrayBuffer()),fallback);
      ready = true; update(); registerTools();
    } catch (error) {
      $('preview-pages').innerHTML = '<div class="preview-loading">No se pudo preparar el documento. Recargá la página para volver a intentarlo.</div>';
    }
  })();
})();
