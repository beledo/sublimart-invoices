<?php
declare(strict_types=1);

function hh_quarter_bounds(): array {
    $year=filter_var($_GET['year']??null,FILTER_VALIDATE_INT);
    $quarter=filter_var($_GET['quarter']??null,FILTER_VALIDATE_INT);
    if ($year<1900 || $year>2199 || $quarter<1 || $quarter>4) throw new HttpError(400,'Elegí un año y un trimestre válidos.');
    return ['year'=>$year,'quarter'=>$quarter,'start'=>sprintf('%04d-%02d-01',$year,($quarter-1)*3+1),'end'=>$quarter===4?sprintf('%04d-01-01',$year+1):sprintf('%04d-%02d-01',$year,$quarter*3+1)];
}
function hh_csv_field(mixed $value): string {
    $value=(string)($value??'');
    if (preg_match('/^(?:[\s\p{Z}]*[=+\-@]|[\t\r\n])/u',$value)) $value="'".$value;
    return '"'.str_replace('"','""',$value).'"';
}
function hh_report_csv(array $rows,array $range): string {
    $money=static fn(int $cents): string=>intdiv($cents,100).','.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);
    $csvLine=static fn(array $fields): string=>implode(';',array_map('hh_csv_field',$fields))."\r\n";
    $csv="\xEF\xBB\xBF".$csvLine([hh_brand()['name'].' · Facturas emitidas',$range['year'].' · T'.$range['quarter']])
        .$csvLine(['Número','Fecha','Cliente','DNI / NIF / CIF / NIE','Base imponible (€)','IVA (%)','Cuota IVA (€)','Total (€)','Documento de origen']);
    $base=0;$tax=0;$total=0;
    foreach($rows as $row) {
        $base+=(int)$row['base_cents'];$tax+=(int)$row['tax_cents'];$total+=(int)$row['total_cents'];
        $csv.=$csvLine([$row['number'],$row['document_date'],$row['client_name'],$row['client_nif'],$money((int)$row['base_cents']),$row['tax_rate'],$money((int)$row['tax_cents']),$money((int)$row['total_cents']),$row['origin_number']]);
    }
    return $csv.$csvLine(['TOTAL','','','',$money($base),'',$money($tax),$money($total),'']);
}
function hh_reports_api(PDO $db,string $method,string $route): never {
    if ($method!=='GET') throw new HttpError(405,'Esta acción no está disponible.');
    $range=hh_quarter_bounds();
    $where="type='invoice' AND deleted_at IS NULL AND document_date>=? AND document_date<?";
    $params=[$range['start'],$range['end']];
    if ($route==='reports/quarter') {
        $offset=max(0,(int)($_GET['offset']??0));$limit=25;
        $totals=hh_query($db,'SELECT tax_rate AS rate,COUNT(*) AS count,SUM(base_cents) AS base,SUM(tax_cents) AS tax,SUM(total_cents) AS total FROM hh_documents WHERE '.$where.' GROUP BY tax_rate ORDER BY tax_rate',$params)->fetchAll();
        $totals=array_map(static fn(array $row):array=>array_map('intval',$row),$totals);
        $summary=['count'=>0,'base'=>0,'tax'=>0,'total'=>0];
        foreach($totals as $row) foreach($summary as $key=>$amount) $summary[$key]+=$row[$key];
        $rows=hh_query($db,'SELECT * FROM hh_documents WHERE '.$where.' ORDER BY document_date,number_key,id LIMIT ? OFFSET ?',[...$params,$limit,$offset])->fetchAll();
        hh_json(['range'=>$range,'summary'=>$summary,'byRate'=>$totals,'documents'=>array_map('hh_metadata',$rows),'nextOffset'=>$offset+count($rows)<$summary['count']?$offset+count($rows):null]);
    }
    if (!in_array($route,['reports/quarter.zip','reports/quarter.csv'],true)) throw new HttpError(404,'No se encontró el informe.');
    $rows=hh_query($db,"SELECT id,number,pdf_key,document_date,client_name,base_cents,tax_cents,total_cents,tax_rate,JSON_UNQUOTE(JSON_EXTRACT(data,'$.client.nif')) AS client_nif,JSON_UNQUOTE(JSON_EXTRACT(data,'$.source.number')) AS origin_number FROM hh_documents WHERE ".$where.' ORDER BY document_date,number_key,id',$params)->fetchAll();
    $stem=hh_brand()['id'].'_'.$range['year'].'_T'.$range['quarter'];
    $csv=hh_report_csv($rows,$range);
    if ($route==='reports/quarter.csv') {
        header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="'.$stem.'.csv"');
        echo $csv;exit;
    }
    if (!$rows) throw new HttpError(400,'No hay facturas emitidas en este trimestre.');
    if (!class_exists('ZipArchive')) throw new HttpError(503,'Activá la extensión ZIP de PHP en el panel de Hostinger para descargar el trimestre.');
    $target=tempnam(hh_brand_data(),'trimestre-');
    if ($target===false) throw new RuntimeException('No se pudo preparar el ZIP.');
    $zip=new ZipArchive();$opened=false;
    try {
        if ($zip->open($target,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new RuntimeException('No se pudo abrir el ZIP.');
        $opened=true;
        if (!$zip->addFromString($stem.'.csv',$csv)) throw new RuntimeException('No se pudo incorporar el CSV.');
        $prefix=strlen(HH_FILE_PREFIX);
        foreach ($rows as $row) {
            $file=hh_pdf_path($row['pdf_key']);
            $size=is_file($file)?filesize($file):false;
            if ($size===false || $size<=$prefix) throw new HttpError(503,'Falta un PDF del trimestre. Abrí y guardá de nuevo esa factura para regenerarlo.');
            $name='Facturas/Factura_'.preg_replace('/[^a-zA-Z0-9_-]/','_',$row['number']).'_'.substr($row['id'],0,8).'.pdf';
            if (!$zip->addFile($file,$name,$prefix,$size-$prefix)) throw new RuntimeException('No se pudo incorporar una factura.');
        }
        $success=$zip->close();$opened=false;
        if (!$success) throw new RuntimeException('No se pudo cerrar el ZIP.');
        clearstatcache(true,$target);
        header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="'.$stem.'.zip"');
        header('Content-Length: '.filesize($target));
        readfile($target);
    } finally {
        if ($opened) @$zip->close();
        if (is_file($target)) unlink($target);
    }
    exit;
}
