<?php
declare(strict_types=1);
require_once __DIR__ . '/app/domain.php';
require_once __DIR__ . '/app/clients.php';
require_once __DIR__ . '/app/reports.php';
hh_headers();

function hh_saved(PDO $db, array $row): array {
    return ['document'=>hh_metadata($row),'suggestions'=>hh_suggestions($db,(int)substr($row['document_date'],0,4))];
}
function hh_pdf_bytes(mixed $encoded): string {
    if (!is_string($encoded) || strlen($encoded) > 17000000) throw new HttpError(400, 'No se pudo leer el PDF del documento.');
    $bytes=base64_decode($encoded,true);
    if($bytes===false||strlen($bytes)<100||strlen($bytes)>12000000||!str_starts_with($bytes,'%PDF-')||!str_contains(substr($bytes,-1024),'%%EOF'))throw new HttpError(400,'El PDF del documento no es válido.');
    return $bytes;
}
function hh_pdf_headers(string $type,string $number,int $size): void {
    $safeNumber=preg_replace('/[^a-zA-Z0-9_-]/','_',$number);
    $name=($type==='invoice'?'Factura_':'Presupuesto_').($safeNumber===''?'borrador':$safeNumber).'_'.hh_brand()['id'].'.pdf';
    $disposition=($_GET['view']??null)==='1'?'inline':'attachment';
    header('Content-Type: application/pdf');
    header('Content-Disposition: '.$disposition.'; filename="'.$name.'"');
    header('Content-Length: '.$size);
    header('Cross-Origin-Resource-Policy: same-origin');
}
function hh_draft_pdf(string $brandId): never {
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'multipart/form-data;')) throw new HttpError(415,'No se pudo leer el formulario del PDF.');
    if((int)($_SERVER['CONTENT_LENGTH']??0)>18000000)throw new HttpError(413,'El documento es demasiado grande.');
    hh_check_csrf(hh_post('csrf'), $brandId);
    session_write_close();
    try {$payload=json_decode(hh_post('document'),true,16,JSON_THROW_ON_ERROR);}
    catch(JsonException $e){throw new HttpError(400,'No se pudo leer el formulario del PDF.');}
    if(!is_array($payload)||!in_array($payload['type']??null,['invoice','quote'],true))throw new HttpError(400,'El tipo de PDF no es válido.');
    $type=$payload['type'];
    $number=hh_string($payload['number']??'',40,'número de documento',$type==='invoice');
    $pdf=hh_pdf_bytes($payload['pdf']??null);
    hh_pdf_headers($type,$number,strlen($pdf));
    echo $pdf;exit;
}
function hh_store(PDO $db, ?string $id): never {
    $body = hh_body(); $now = hh_now();
    $validated = hh_validate($body['data'] ?? null);
    $data = $validated['data']; $amounts = $validated['amounts'];
    $serialized = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $updating = $id !== null; $previous = null; $pdfKey = null; $committed = false;
    if (!$updating) {
        $id = $body['id'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $id)) throw new HttpError(400, 'No se pudo identificar el nuevo documento.');
    }
    try {
        $db->beginTransaction();
        if ($updating) {
            $previous = hh_find($db,$id,$now,true);
            if ($previous['deleted_at'] !== null) throw new HttpError(409, 'Recuperá el documento de la papelera antes de editarlo.');
            if (!is_int($body['revision'] ?? null) || $body['revision'] !== (int)$previous['revision']) throw new HttpError(409, 'Este documento cambió en otra sesión. Abrilo de nuevo desde el historial.');
        } else {
            $existing = hh_query($db,'SELECT * FROM hh_documents WHERE id=? FOR UPDATE',[$id])->fetch();
            if ($existing) {
                if ($existing['data'] === $serialized && $existing['deleted_at'] === null) {
                    $db->commit(); hh_json(hh_saved($db,$existing));
                }
                throw new HttpError(409, 'Este documento ya fue guardado. Abrilo desde el historial para editarlo.');
            }
        }
        $duplicate = hh_query($db,'SELECT id,deleted_at FROM hh_documents WHERE type=? AND reserved_number_key=? AND id<>?',[$data['type'],$validated['key'],$id])->fetch();
        if ($duplicate) throw new HttpError(409, 'Ya existe ' . ($data['type']==='invoice'?'una factura':'un presupuesto') . ' con ese número' . ($duplicate['deleted_at']!==null?' en la papelera.':'.'), ['suggestions'=>hh_suggestions($db,(int)substr($data['date'],0,4))]);
        $bytes=hh_pdf_bytes($body['pdfBase64']??$body['pdf']??null);
        $pdfKey = bin2hex(random_bytes(32)) . '.pdf.php';
        $path = hh_pdf_path($pdfKey);
        if (file_put_contents($path, HH_FILE_PREFIX . $bytes, LOCK_EX) === false) throw new RuntimeException('No se pudo guardar el PDF.');
        chmod($path, 0600);
        $params = [$data['type'],$data['number'],$validated['key'],$data['date'],$data['client']['name'],$serialized,$amounts['base'],$amounts['tax'],$amounts['total'],$data['tax'],$pdfKey];
        if ($updating) {
            $result = hh_query($db,'UPDATE hh_documents SET type=?,number=?,number_key=?,document_date=?,client_name=?,data=?,base_cents=?,tax_cents=?,total_cents=?,tax_rate=?,pdf_key=?,revision=revision+1,updated_at=? WHERE id=? AND revision=? AND deleted_at IS NULL',[...$params,$now,$id,$body['revision']]);
            if ($result->rowCount() !== 1) throw new HttpError(409, 'Este documento cambió en otra sesión. Abrilo de nuevo desde el historial.');
        } else {
            hh_query($db,'INSERT INTO hh_documents (id,type,number,number_key,document_date,client_name,data,base_cents,tax_cents,total_cents,tax_rate,pdf_key,revision,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?,?)',[$id,...$params,$now,$now]);
        }
        hh_sequence($db,$data['type'],$data['number'],$now);
        $db->commit(); $committed = true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ($pdfKey && !$committed && is_file(hh_pdf_path($pdfKey))) unlink(hh_pdf_path($pdfKey));
        if ($e instanceof PDOException && $e->getCode() === '23000') throw new HttpError(409, 'Ese número acaba de guardarse en otra sesión. Elegí otro número.');
        throw $e;
    }
    if ($previous && is_file(hh_pdf_path($previous['pdf_key']))) unlink(hh_pdf_path($previous['pdf_key']));
    hh_json(hh_saved($db,hh_find($db,$id,$now)),$updating?200:201);
}
try {
    $brand = hh_brand();
    $brandId = $brand['id'];
    $config = hh_require_auth($brandId, true);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $route=trim(is_string($_GET['route']??null)?$_GET['route']:'','/');
    if ($route === 'pdf-logo' && $method === 'GET') {
        session_write_close();
        $logo = fopen(__DIR__ . '/' . $brand['logo'], 'rb');
        if (!$logo) throw new HttpError(503, 'No se pudo leer el logo de esta marca.');
        // The PDF needs the original file, even if the CDN serves WebP for the logo in the header.
        header('Content-Type: application/octet-stream');
        header('Cache-Control: private, no-store, no-transform');
        fpassthru($logo);
        fclose($logo);
        exit;
    }
    hh_validate_databases($config['databases']??[]);
    if($route==='draft.pdf'&&$method==='POST')hh_draft_pdf($brandId);
    if (!in_array($method,['GET','HEAD'],true)) hh_check_csrf(null, $brandId);
    $csrf = hh_csrf($brandId);
    session_write_close();
    $db = hh_connect($config); $now = hh_now();
    hh_migrate($db);
    if ($route === 'bootstrap' && $method === 'GET') {
        try { hh_purge($db,$now); } catch (Throwable $e) { error_log('Tus marcas: no se pudo limpiar la papelera.'); }
        $year = isset($_GET['year']) ? filter_var($_GET['year'],FILTER_VALIDATE_INT) : (int)date('Y');
        if (!$year || $year < 1900 || $year > 2199) throw new HttpError(400,'El año no es válido.');
        $company = hh_query($db,'SELECT data FROM hh_company WHERE id=?',['company'])->fetch();
        $counts = hh_query($db,'SELECT SUM(CASE WHEN deleted_at IS NULL THEN 1 ELSE 0 END) AS active,SUM(CASE WHEN deleted_at IS NOT NULL AND expires_at>? THEN 1 ELSE 0 END) AS trash FROM hh_documents',[$now])->fetch();
        hh_json(['brand'=>$brand,'company'=>$company?json_decode($company['data'],true,64,JSON_THROW_ON_ERROR):hh_company_default(),'suggestions'=>hh_suggestions($db,$year),'counts'=>['active'=>(int)($counts['active']??0),'trash'=>(int)($counts['trash']??0)],'csrfToken'=>$csrf,'uploadPdf'=>true]);
    }
    if ($route==='clients' || str_starts_with($route,'clients/')) hh_clients_api($db,$method,$route,$now);
    if (str_starts_with($route,'reports/')) hh_reports_api($db,$method,$route);
    if ($route === 'company' && $method === 'PUT') {
        $body = hh_body(); $company = hh_company($body['company'] ?? null);
        hh_query($db,'INSERT INTO hh_company (id,data,updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE data=VALUES(data),updated_at=VALUES(updated_at)',['company',json_encode($company,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$now]);
        hh_json(['company'=>$company]);
    }
    if ($route === 'documents' && $method === 'POST') hh_store($db,null);
    if ($route === 'documents' && $method === 'GET') {
        $trash = ($_GET['status'] ?? 'active') === 'trash'; $type = (string)($_GET['type'] ?? '');
        $offset = max(0,(int)($_GET['offset'] ?? 0)); $limit = 50;
        if ($type && !in_array($type,['quote','invoice'],true)) throw new HttpError(400,'El tipo de documento no es válido.');
        $where = $trash ? 'deleted_at IS NOT NULL AND expires_at>?' : 'deleted_at IS NULL'; $params = $trash?[$now]:[];
        if ($type) { $where .= ' AND type=?'; $params[] = $type; }
        $rows = hh_query($db,'SELECT * FROM hh_documents WHERE '.$where.' ORDER BY '.($trash?'deleted_at':'updated_at').' DESC,id DESC LIMIT ? OFFSET ?',[...$params,$limit,$offset])->fetchAll();
        $count = (int)hh_query($db,'SELECT COUNT(*) FROM hh_documents WHERE '.$where,$params)->fetchColumn();
        hh_json(['documents'=>array_map('hh_metadata',$rows),'total'=>$count,'offset'=>$offset,'nextOffset'=>$offset+count($rows)<$count?$offset+count($rows):null]);
    }
    if (!preg_match('/^documents\/([a-f0-9-]{36})(?:\/(pdf|restore))?$/iD',$route,$m)) throw new HttpError(404,'No se encontró esta acción.');
    $id=$m[1]; $action=$m[2]??'';
    if ($method==='PUT' && $action==='') hh_store($db,$id);
    $row=hh_find($db,$id,$now);
    if ($method==='GET' && $action==='pdf') {
        $file=hh_pdf_path($row['pdf_key']);
        if (!is_file($file)) throw new HttpError(503,'No se pudo recuperar el PDF. Volvé a intentarlo.');
        $handle=fopen($file,'rb'); if(!$handle)throw new HttpError(503,'No se pudo recuperar el PDF.');
        fseek($handle,strlen(HH_FILE_PREFIX));
        $size=fstat($handle)['size']??0;
        if($size<=strlen(HH_FILE_PREFIX)){fclose($handle);throw new HttpError(503,'No se pudo recuperar el PDF.');}
        hh_pdf_headers($row['type'],$row['number'],$size-strlen(HH_FILE_PREFIX));
        fpassthru($handle); fclose($handle); exit;
    }
    if ($method==='GET' && $action==='') hh_json(['document'=>hh_metadata($row),'data'=>json_decode($row['data'],true,64,JSON_THROW_ON_ERROR)]);
    if ($method==='DELETE' && $action==='') {
        $db->beginTransaction();
        try {
            $row=hh_find($db,$id,$now,true);
            if($row['deleted_at']===null){hh_query($db,'UPDATE hh_documents SET deleted_at=?,expires_at=?,updated_at=? WHERE id=?',[$now,$now+30*HH_DAY,$now,$id]);$row=hh_find($db,$id,$now);}
            $db->commit();
        } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        hh_json(['document'=>hh_metadata($row)]);
    }
    if ($method==='POST' && $action==='restore') {
        $db->beginTransaction();
        try {
            $row=hh_find($db,$id,$now,true);
            if($row['deleted_at']!==null){hh_query($db,'UPDATE hh_documents SET deleted_at=NULL,expires_at=NULL,updated_at=? WHERE id=?',[$now,$id]);$row=hh_find($db,$id,$now);}
            $db->commit();
        } catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            if ($e instanceof PDOException && $e->getCode() === '23000') {
                throw new HttpError(409, 'El número ' . $row['number'] . ' ya está en uso. El presupuesto sigue en la papelera.');
            }
            throw $e;
        }
        hh_json(['document'=>hh_metadata($row)]);
    }
    throw new HttpError(405,'Esta acción no está disponible.');
} catch (HttpError $e) { hh_json(['error'=>$e->getMessage(),...$e->details],$e->status); }
catch (Throwable $e) {
    error_log('Tus marcas: falló una operación ('.get_class($e).').');
    hh_json(['error'=>'No se pudo completar la operación. Revisá la conexión MySQL y los permisos del directorio privado. Tus datos siguen en el formulario.'],503);
}
