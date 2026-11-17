const assert=require('node:assert/strict');
const fs=require('node:fs'),path=require('node:path');
const root=path.resolve(__dirname,'..');
const PDFLib=require(path.join(root,'assets/pdf-lib.min.js'));
require(path.join(root,'assets/document.js'));
(async()=>{
 for(const [brand,name] of [['sublimart','Sublimart'],['argento','Argento 100x100'],['merchandising','El Merchandising de tu club']]){
  await BrandDocument.init(PDFLib,fs.readFileSync(path.join(root,'assets/logos',brand+'.png')));
  const data={brand,type:'quote',number:'001-26',date:'2026-11-17',tax:21,unit:'Cantidad',company:{name,brand},client:{name:'Cliente de prueba',nif:'12345678'},items:[{description:'Camiseta personalizada',quantity:'12',price:'11.50'},{description:'Taza con diseño a color',quantity:'6',price:'6.90'}],validity:'30'};
  const doc=BrandDocument.layout(data),bytes=await BrandDocument.pdfBytes(PDFLib,doc);
  assert.equal(doc.amounts.base,17940);assert.equal(doc.amounts.total,21707);
  const parsed=await PDFLib.PDFDocument.load(bytes);assert.equal(parsed.getPageCount(),1);assert.ok(parsed.getTitle().includes(name));
  assert.ok(doc.pages[0].some(op=>op.kind==='image'));
  assert.match(BrandDocument.svgPages(doc), /<image href="data:image\/png;base64,iVBOR/);
  if(process.env.PDF_TEST_OUTPUT)fs.writeFileSync(path.join(process.env.PDF_TEST_OUTPUT,brand+'.pdf'),bytes);
  for(const rate of [0,4,10,21])assert.equal(BrandDocument.calculate([{quantity:'1',price:'100'}],rate).total,10000+rate*100);
  const long={...data,items:Array.from({length:100},(_,i)=>({description:'Producto '+i+' · '+('Descripción muy extensa con palabras '.repeat(12)),quantity:'1',price:'2.50'})),notes:'Observación final '.repeat(300)};
  const pages=BrandDocument.layout(long);assert.ok(pages.pages.length>3);
  for(const ops of pages.pages)for(const op of ops)if(op.kind==='text')assert.ok(op.y>=0&&op.y<830,'Text must stay on the page');
  console.log(name+': logo, PDF, IVA, importes y paginación OK');
 }
})().catch(error=>{console.error(error);process.exit(1);});
