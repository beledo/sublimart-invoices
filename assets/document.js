(function (root) {
  'use strict';
  const W = 595.28, H = 841.89, LEFT = 44, RIGHT = 551.28;
  const COLORS = { ink: '#252525', gray: '#eaecf0', pale: '#f0f0f0', orange: '#d94e22', faint: '#7c8490' };
  let fonts, unicode, logoData, logoBytes, logoFormat, logoSize;
  const euro = value => new Intl.NumberFormat('es-ES', { style: 'currency', currency: 'EUR', minimumFractionDigits: 2 }).format(value / 100);
  function decimal(value, places) {
    const s = String(value ?? '').trim().replace(',', '.');
    if (!new RegExp('^\\d{1,9}(?:\\.\\d{1,' + places + '})?$').test(s)) throw new Error('Usá un número positivo, sin separadores de miles.');
    const [whole, fraction = ''] = s.split('.');
    return BigInt(whole) * (10n ** BigInt(places)) + BigInt(fraction.padEnd(places, '0'));
  }
  function lineCents(item) {
    const qty = decimal(item.quantity, 3), price = decimal(item.price, 2);
    if (qty === 0n) throw new Error('La cantidad debe ser mayor que cero.');
    const cents = (qty * price + 500n) / 1000n;
    if (cents > 999999999999n) throw new Error('El importe de la partida es demasiado grande.');
    return Number(cents);
  }
  function calculate(items, rate) {
    if (![0,4,10,21].includes(Number(rate))) throw new Error('Elegí un tipo de IVA válido.');
    const lines = items.map(lineCents);
    const base = lines.reduce((sum, n) => sum + BigInt(n), 0n);
    const tax = (base * BigInt(rate) + 50n) / 100n;
    if (base + tax > 999999999999n) throw new Error('El importe total es demasiado grande.');
    return { lines, base: Number(base), tax: Number(tax), total: Number(base + tax), rate: Number(rate) };
  }
  function safeText(text) {
    return String(text ?? '').normalize('NFC').replace(/\r\n?/g, '\n').replace(/\t/g, '  ')
      .replace(/[\u0000-\u0008\u000b\u000c\u000e-\u001f\u200b\u200e\u200f\u202a-\u202e\u2066-\u2069\ufe0e\ufe0f\ufeff]/g, '')
      .replace(/[\u2028\u2029]/g, '\n').replace(/[\u2000-\u200a\u202f\u205f\u3000]/g, ' ')
      .replace(/㎡/g, 'm²').replace(/㎥/g, 'm³');
  }
  function fontFor(bold) { return bold ? fonts.bold : fonts.regular; }
  function printable(text) {
    const chars = Array.from(safeText(text));
    if (chars.every(char => char === '\n' || fonts.latin.has(char.codePointAt(0)))) return { value:chars.join(''), fallback:false };
    const symbols = { '✓':'[OK]', '✔':'[OK]', '→':'->', '←':'<-', '≤':'<=', '≥':'>=', '−':'-' };
    let fallback=false;
    const value=chars.map(char=>{
      const code=char.codePointAt(0);
      if (char==='\n'||fonts.latin.has(code)) return char;
      if (unicode?.characters.has(code)) { fallback=true; return char; }
      return symbols[char] || '[U+'+code.toString(16).toUpperCase()+']';
    }).join('');
    // Use one font per line. If any standard character is absent in the fallback
    // face, make that character explicit rather than printing a missing glyph.
    return { value:fallback?Array.from(value).map(char=>char==='\n'||unicode.characters.has(char.codePointAt(0))?char:'[U+'+char.codePointAt(0).toString(16).toUpperCase()+']').join(''):value, fallback };
  }
  function width(text, size, bold) {
    const {value,fallback}=printable(text);
    const font=fallback?unicode.metrics:fontFor(bold);
    return Math.max(...value.split('\n').map(line=>font.widthOfTextAtSize(line,size)));
  }
  function fitSize(text, maxWidth, preferred, bold = false) {
    let size = preferred;
    while (size > 7 && width(text, size, bold) > maxWidth) size -= 0.25;
    return size;
  }
  function wrap(text, maxWidth, size = 10, bold = false) {
    const result = [];
    for (const paragraph of safeText(text).split('\n')) {
      if (!paragraph.trim()) { result.push(''); continue; }
      let line = '';
      for (const word of paragraph.trim().split(/\s+/)) {
        if (width(word, size, bold) > maxWidth) {
          if (line) { result.push(line); line = ''; }
          let fragment = '';
          for (const char of word) {
            if (fragment && width(fragment + char, size, bold) > maxWidth) { result.push(fragment); fragment = ''; }
            fragment += char;
          }
          line = fragment;
        } else if (line && width(line + ' ' + word, size, bold) > maxWidth) {
          result.push(line); line = word;
        } else line += (line ? ' ' : '') + word;
      }
      if (line) result.push(line);
    }
    return result;
  }
  async function init(PDFLib, imageData, fallbackFont) {
    // pdf-lib's JPEG parser reads the underlying ArrayBuffer from offset zero.
    // Always copy the exact image view, including Node Buffer/subarray inputs.
    let source;
    if (typeof imageData === 'string') {
      const match = imageData.match(/^data:image\/(?:jpeg|png);base64,([A-Za-z0-9+/=\s]+)$/);
      if (!match) throw new Error('No se pudo leer el logo del documento.');
      const binary = atob(match[1].replace(/\s/g, ''));
      source = Uint8Array.from(binary, char => char.charCodeAt(0));
    } else if (ArrayBuffer.isView(imageData)) source = new Uint8Array(imageData.buffer, imageData.byteOffset, imageData.byteLength);
    else if (imageData instanceof ArrayBuffer) source = new Uint8Array(imageData);
    else throw new Error('No se pudo leer el logo del documento.');
    logoBytes = new Uint8Array(source.byteLength);
    logoBytes.set(source);
    const png = [137,80,78,71,13,10,26,10].every((byte, i) => logoBytes[i] === byte);
    const jpeg = logoBytes[0] === 255 && logoBytes[1] === 216 && logoBytes[2] === 255;
    if (!png && !jpeg) throw new Error('El logo no es una imagen PNG o JPEG válida. Recargá la página.');
    logoFormat = png ? 'png' : 'jpeg';
    let binary = ''; for (const byte of logoBytes) binary += String.fromCharCode(byte);
    logoData = 'data:image/' + logoFormat + ';base64,' + btoa(binary);
    const metrics = await PDFLib.PDFDocument.create();
    const logo = logoFormat === 'png' ? await metrics.embedPng(logoBytes) : await metrics.embedJpg(logoBytes);
    logoSize={w:logo.width,h:logo.height};
    const regular = await metrics.embedFont(PDFLib.StandardFonts.Helvetica), bold = await metrics.embedFont(PDFLib.StandardFonts.HelveticaBold);
    fonts = {regular,bold,latin:new Set(regular.getCharacterSet())};
    unicode = undefined;
    if (fallbackFont) {
      metrics.registerFontkit(fallbackFont.kit);
      const face = await metrics.embedFont(fallbackFont.bytes);
      unicode = { ...fallbackFont, metrics:face, characters:new Set(face.getCharacterSet()) };
    }
  }
  function layout(data) {
    if(!fonts)throw new Error('El generador de PDF se está preparando.');
    const invoice=data.type==='invoice',title=invoice?'FACTURA':'PRESUPUESTO';
    const accent=data.brand==='argento'?'#146c9b':'#a53662';
    const amounts=calculate(data.items,data.tax),pages=[];let ops,y;
    const bottom=741;
    function text(str,x,top,size=10,bold=false,align='left',color=COLORS.ink){
      const {value:content,fallback}=printable(str);let adjustedX=x;
      if(align==='right')adjustedX-=width(content,size,bold);
      if(align==='center')adjustedX-=width(content,size,bold)/2;
      ops.push({kind:'text',text:content,x:adjustedX,y:top,size,bold,fallback,color});
    }
    function line(x1,top,x2,color='#e9e3e7',thickness=.6){ops.push({kind:'line',x1,y1:top,x2,y2:top,color,thickness});}
    function rect(x,top,w,h,color){ops.push({kind:'rect',x,y:top,w,h,color});}
    function newPage(first=false){
      ops=[];pages.push(ops);rect(0,0,W,5,accent);
      const brand=data.brand||data.company.brand;
      if(brand==='sublimart')ops.push({kind:'image',x:LEFT,y:30,w:150,h:62,crop:{x:265,y:345,w:750,h:220},sourceW:1280,sourceH:905});
      else {const scale=Math.min(82/logoSize.w,82/logoSize.h);ops.push({kind:'image',x:LEFT,y:23,w:logoSize.w*scale,h:logoSize.h*scale});}
      text(title,RIGHT,37,20,true,'right',accent);
      text('N.º '+(data.number||'BORRADOR'),RIGHT,67,11,true,'right');
      text(formatDate(data.date),RIGHT,87,9,false,'right','#837680');
      y=125;
      if(first){
        for(const entry of wrap(data.company.name||'Tu marca',RIGHT-LEFT,13,true)){text(entry,LEFT,y,13,true);y+=17;}
        const companyDetails=[data.company.nif?'NIF: '+data.company.nif:'',data.company.address,[data.company.postcode,data.company.city].filter(Boolean).join(' ')].filter(Boolean);
        for(const entry of companyDetails)for(const value of wrap(entry,RIGHT-LEFT,9)){text(value,LEFT,y,9,false,'left','#7f747d');y+=13;}
        y+=20;
        const client=data.client||{};
        const details=[client.nif?'DNI / NIF: '+client.nif:'',client.address,[client.postcode,client.city].filter(Boolean).join(' '),[client.email,client.phone].filter(Boolean).join(' · ')].filter(Boolean);
        const nameLines=wrap(client.name||'Cliente por definir',RIGHT-LEFT-28,12,true);
        const detailLines=details.flatMap(entry=>wrap(entry,RIGHT-LEFT-28,9));
        const panelHeight=36+nameLines.length*16+detailLines.length*13;
        rect(LEFT,y,RIGHT-LEFT,panelHeight,'#f8f6f7');
        text(invoice?'FACTURAR A':'PREPARADO PARA',LEFT+14,y+11,7.5,true,'left','#94838e');
        let cy=y+27;
        for(const v of nameLines){text(v,LEFT+14,cy,12,true);cy+=16;}
        for(const v of detailLines){text(v,LEFT+14,cy,9,false,'left','#786975');cy+=13;}
        y+=panelHeight+25;
      }
    }
    function tableHeader(){
      rect(LEFT,y,RIGHT-LEFT,27,accent);
      text('PRODUCTO / SERVICIO',LEFT+10,y+9,8,true,'left','#ffffff');
      text('CANT.',350,y+9,8,true,'center','#ffffff');
      text('PRECIO',440,y+9,8,true,'right','#ffffff');
      text('IMPORTE',RIGHT-9,y+9,8,true,'right','#ffffff');y+=39;
    }
    newPage(true);tableHeader();
    data.items.forEach((item,index)=>{
      let lines=wrap(item.description||'Descripción del producto o servicio',270,10),firstPiece=true;
      while(lines.length){
        if(y+26>bottom){newPage();tableHeader();}
        const room=Math.max(1,Math.floor((bottom-y-15)/14)),piece=lines.splice(0,room);
        piece.forEach((v,i)=>text(v,LEFT+8,y+i*14,10));
        if(firstPiece){
          const quantity=String(item.quantity||'1').replace('.',','),price=euro(Number(decimal(item.price||'0',2)));
          text(quantity,350,y,fitSize(quantity,47,10),false,'center');
          text(price,440,y,fitSize(price,73,10),false,'right');
          const amount=euro(amounts.lines[index]);text(amount,RIGHT-9,y,fitSize(amount,85,10,true),true,'right');firstPiece=false;
        }
        y+=Math.max(31,piece.length*14+15);line(LEFT,y-7,RIGHT);
        if(lines.length){newPage();tableHeader();}
      }
    });
    if(y+116>bottom)newPage();
    y+=8;
    text('Subtotal',RIGHT-115,y,10,false,'right','#7f737d');text(euro(amounts.base),RIGHT-9,y,fitSize(euro(amounts.base),100,11),false,'right');y+=23;
    text('IVA '+data.tax+' %',RIGHT-115,y,10,false,'right','#7f737d');text(euro(amounts.tax),RIGHT-9,y,fitSize(euro(amounts.tax),100,11),false,'right');y+=25;
    rect(RIGHT-242,y,242,42,'#f8f3f6');text('TOTAL',RIGHT-121,y+13,12,true,'right',accent);
    text(euro(amounts.total),RIGHT-9,y+11,fitSize(euro(amounts.total),107,16,true),true,'right',accent);y+=65;
    function paragraph(heading,content){
      if(!String(content||'').trim())return;
      const lines=wrap(content,RIGHT-LEFT,9);
      if(y+46>bottom)newPage();
      text(heading,LEFT,y,8,true,'left',accent);y+=18;
      for(const v of lines){if(y+14>bottom)newPage();text(v,LEFT,y,9);y+=13;}
      y+=13;
    }
    paragraph('FORMA DE PAGO',[data.paymentMethod,data.paymentTerms,data.iban?'IBAN: '+data.iban:''].filter(Boolean).join('\n'));
    paragraph('OBSERVACIONES',data.notes);
    paragraph('CONDICIONES',data.clause);
    if(!invoice&&data.validity){if(y+18>bottom)newPage();text('Validez del presupuesto: '+data.validity+' días desde la fecha de emisión.',LEFT,y,8,false,'left','#8b7c87');}
    pages.forEach((p,i)=>{
      ops=p;line(LEFT,779,RIGHT);
      const contact=[data.company.name,data.company.email,data.company.phone].filter(Boolean).join(' · ');
      wrap(contact,RIGHT-LEFT-55,7.5).slice(0,2).forEach((v,n)=>text(v,LEFT,792+n*11,7.5,false,'left','#8d7f89'));
      text((i+1)+' / '+pages.length,RIGHT,792,8,false,'right','#8d7f89');
    });
    return {pages,amounts,data};
  }
  function formatDate(value) {
    if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return '—';
    const [year, month, day] = value.split('-');
    return day + '/' + month + '/' + year;
  }
  function escape(text) { return String(text).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]); }
  function svgPages(document) {
    return document.pages.map((ops, page) => '<svg class="document-page" viewBox="0 0 ' + W + ' ' + H + '" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="' + escape((document.data.type === 'invoice' ? 'Factura' : 'Presupuesto') + ', página ' + (page + 1)) + '"><rect width="100%" height="100%" fill="white"/>' + ops.map(op => {
      if (op.kind === 'rect') return '<rect x="' + op.x + '" y="' + op.y + '" width="' + op.w + '" height="' + op.h + '" fill="' + op.color + '"/>';
      if (op.kind === 'line') return '<line x1="' + op.x1 + '" y1="' + op.y1 + '" x2="' + op.x2 + '" y2="' + op.y2 + '" stroke="' + op.color + '" stroke-width="' + op.thickness + '"/>';
      if(op.kind==='image'&&op.crop)return '<svg x="'+op.x+'" y="'+op.y+'" width="'+op.w+'" height="'+op.h+'" viewBox="'+op.crop.x+' '+op.crop.y+' '+op.crop.w+' '+op.crop.h+'" preserveAspectRatio="xMidYMid meet"><image href="'+logoData+'" width="'+op.sourceW+'" height="'+op.sourceH+'"/></svg>';
      if (op.kind === 'image') return '<image href="' + logoData + '" x="' + op.x + '" y="' + op.y + '" width="' + op.w + '" height="' + op.h + '"/>';
      return '<text x="' + op.x + '" y="' + (op.y + op.size * .82) + '" font-family="'+(op.fallback?'Brand PDF,':'')+'Arial,Helvetica,sans-serif" font-size="' + op.size + '" font-weight="' + (op.bold&&!op.fallback ? '700' : '400') + '" fill="' + op.color + '">' + escape(op.text) + '</text>';
    }).join('') + '</svg>').join('');
  }
  function rgb(PDFLib, hex) { const n = parseInt(hex.slice(1), 16); return PDFLib.rgb((n >> 16 & 255) / 255, (n >> 8 & 255) / 255, (n & 255) / 255); }
  async function pdfBytes(PDFLib, document) {
    const pdf = await PDFLib.PDFDocument.create();
    const regular = await pdf.embedFont(PDFLib.StandardFonts.Helvetica);
    const bold = await pdf.embedFont(PDFLib.StandardFonts.HelveticaBold);
    let fallback;
    if (document.pages.some(page=>page.some(op=>op.fallback))) {
      pdf.registerFontkit(unicode.kit);
      // The source font is already trimmed. Embedding it without another
      // subsetting pass preserves glyph outlines in third-party PDF readers.
      fallback=await pdf.embedFont(unicode.bytes);
    }
    const image = logoFormat === 'png' ? await pdf.embedPng(logoBytes) : await pdf.embedJpg(logoBytes);
    const name = document.data.type === 'invoice' ? 'Factura' : 'Presupuesto';
    pdf.setTitle(name + ' ' + document.data.number + ' · ' + document.data.company.name);
    pdf.setAuthor(document.data.company.name);
    pdf.setSubject('Base imponible: ' + euro(document.amounts.base) + '; IVA ' + document.data.tax + ' %: ' + euro(document.amounts.tax) + '; Total: ' + euro(document.amounts.total));
    for (const ops of document.pages) {
      const page = pdf.addPage([W, H]);
      for (const op of ops) {
        if (op.kind === 'rect') page.drawRectangle({ x: op.x, y: H - op.y - op.h, width: op.w, height: op.h, color: rgb(PDFLib, op.color) });
        if (op.kind === 'line') page.drawLine({ start: { x: op.x1, y: H - op.y1 }, end: { x: op.x2, y: H - op.y2 }, thickness: op.thickness, color: rgb(PDFLib, op.color) });
        if(op.kind==='image'){
          if(op.crop){
            const scale=Math.min(op.w/op.crop.w,op.h/op.crop.h),dx=op.x+(op.w-op.crop.w*scale)/2,dy=op.y+(op.h-op.crop.h*scale)/2;
            page.pushOperators(PDFLib.pushGraphicsState(),PDFLib.rectangle(op.x,H-op.y-op.h,op.w,op.h),PDFLib.clip(),PDFLib.endPath());
            page.drawImage(image,{x:dx-op.crop.x*scale,y:H-dy-(op.sourceH-op.crop.y)*scale,width:op.sourceW*scale,height:op.sourceH*scale});
            page.pushOperators(PDFLib.popGraphicsState());
          }else page.drawImage(image,{x:op.x,y:H-op.y-op.h,width:op.w,height:op.h});
        }
        if (op.kind === 'text') page.drawText(op.text, { x: op.x, y: H - op.y - op.size * .82, size: op.size, font: op.fallback ? fallback : op.bold ? bold : regular, color: rgb(PDFLib, op.color) });
      }
    }
    return pdf.save();
  }
  root.BrandDocument = { init, layout, svgPages, pdfBytes, calculate, lineCents, decimal, euro, escape, formatDate };
})(typeof window === 'undefined' ? globalThis : window);
