<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

const HH_MAX_CENTS = 999999999999;
function hh_company_default(): array {
    $brand=hh_brand();
    return ['name'=>$brand['name'],'nif'=>'','address'=>'','postcode'=>'','city'=>'','email'=>'','phone'=>'','iban'=>'','brand'=>$brand['id']];
}
function hh_string(mixed $value, int $max, string $field, bool $required = false): string {
    if (!is_string($value)) throw new HttpError(400, 'Revisá el campo ' . $field . '.');
    $value = trim($value);
    $length = preg_match_all('/./us', $value);
    if ($length === false || $length > $max || ($required && $value === '')) throw new HttpError(400, 'Completá correctamente ' . $field . '.');
    return $value;
}
function hh_company(mixed $input, bool $fiscal=false): array {
    if (!is_array($input)) throw new HttpError(400, 'Revisá los datos de la marca.');
    $brand=hh_brand();
    if(isset($input['brand'])&&$input['brand']!==$brand['id'])throw new HttpError(400,'Los datos pertenecen a otra marca. Abrí el documento desde su cuenta.');
    $limits = ['name'=>150,'nif'=>30,'address'=>200,'postcode'=>20,'city'=>100,'email'=>100,'phone'=>50,'iban'=>40];
    $company = [];
    foreach ($limits as $key => $max) $company[$key] = hh_string($input[$key] ?? '', $max, $key, $key==='name'||($fiscal&&in_array($key,['nif','address','postcode','city'],true)));
    $company['brand']=$brand['id'];
    if($company['email']!==''&&!filter_var($company['email'],FILTER_VALIDATE_EMAIL))throw new HttpError(400,'El correo de la marca no es válido.');
    return $company;
}
function hh_tax_key(string $value): string {
    $key=strtoupper(preg_replace('/[\s.\-]+/u','',trim($value))??'');
    return preg_match('/^ES[A-Z0-9]{9}$/D',$key) ? substr($key,2) : $key;
}
function hh_client(mixed $input, bool $required = false, bool $fiscal=false): array {
    if (!is_array($input)) throw new HttpError(400,'Revisá los datos del cliente.');
    $client=[];
    foreach (['name'=>150,'nif'=>30,'address'=>220,'postcode'=>20,'city'=>100,'email'=>100,'phone'=>50] as $key=>$max)
        $client[$key]=hh_string($input[$key]??'',$max,'cliente: '.$key,($required&&in_array($key,['name','nif'],true))||($fiscal&&!in_array($key,['email','phone'],true)));
    $client['nif']=strtoupper($client['nif']);
    if ($client['email']!=='' && !preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/uD',$client['email'])) throw new HttpError(400,'El correo del cliente no es válido.');
    if ($client['nif']!=='' && !preg_match('/^[A-Z0-9]{3,30}$/D',hh_tax_key($client['nif']))) throw new HttpError(400,'Revisá el DNI, NIF, CIF o NIE del cliente.');
    return $client;
}
function hh_decimal(string $value, int $places): int {
    $value = str_replace(',', '.', trim($value));
    if (!preg_match('/^\d{1,9}(?:\.\d{1,' . $places . '})?$/D', $value)) throw new HttpError(400, 'Usá un número positivo, sin separadores de miles.');
    $parts = explode('.', $value, 2);
    return (int) $parts[0] * (10 ** $places) + (int) str_pad($parts[1] ?? '', $places, '0');
}
function hh_amounts(array $items, int $rate): array {
    $lines = []; $base = 0;
    foreach ($items as $item) {
        $quantity = hh_decimal($item['quantity'], 3); $price = hh_decimal($item['price'], 2);
        if ($quantity === 0) throw new HttpError(400, 'La cantidad debe ser mayor que cero.');
        $whole = intdiv($quantity, 1000); $fraction = $quantity % 1000;
        if ($price > 0 && $whole > intdiv(HH_MAX_CENTS, $price)) throw new HttpError(400, 'El importe de la partida es demasiado grande.');
        $cents = $whole * $price + intdiv($fraction * $price + 500, 1000);
        if ($cents > HH_MAX_CENTS) throw new HttpError(400, 'El importe de la partida es demasiado grande.');
        $lines[] = $cents; $base += $cents;
        if ($base > HH_MAX_CENTS) throw new HttpError(400, 'El importe total es demasiado grande.');
    }
    $tax = intdiv($base * $rate + 50, 100);
    if ($base + $tax > HH_MAX_CENTS) throw new HttpError(400, 'El importe total es demasiado grande.');
    return ['lines'=>$lines,'base'=>$base,'tax'=>$tax,'total'=>$base+$tax,'rate'=>$rate];
}
function hh_validate(mixed $input): array {
    if (!is_array($input)) throw new HttpError(400, 'Revisá los datos del documento.');
    if (!in_array($input['type'] ?? '', ['quote','invoice'], true)) throw new HttpError(400, 'Elegí presupuesto o factura.');
    $number = hh_string($input['number'] ?? '', 40, 'número de documento', true);
    $date = hh_string($input['date'] ?? '', 10, 'fecha de emisión', true);
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new HttpError(400, 'La fecha de emisión no es válida.');
    $rate = $input['tax'] ?? null;
    if (!in_array($rate, [0,4,10,21], true)) throw new HttpError(400, 'Elegí un tipo de IVA válido.');
    if (!in_array($input['unit'] ?? '', ['Cantidad','Metros²','Horas','Unidades'], true)) throw new HttpError(400, 'Elegí una unidad válida.');
    if(isset($input['brand'])&&$input['brand']!==hh_brand()['id'])throw new HttpError(400,'El documento pertenece a otra marca.');
    $company = hh_company($input['company'] ?? null,$input['type']==='invoice');
    $client = hh_client($input['client']??[],$input['type']==='invoice',$input['type']==='invoice');
    if (!is_array($input['items'] ?? null) || count($input['items']) < 1 || count($input['items']) > 200) throw new HttpError(400, 'El documento debe tener entre 1 y 200 partidas.');
    $items = [];
    foreach ($input['items'] as $index=>$row) {
        if (!is_array($row)) throw new HttpError(400, 'Revisá la partida ' . ($index+1) . '.');
        $items[] = ['description'=>hh_string($row['description'] ?? '',2000,'descripción de partida '.($index+1),true),'quantity'=>hh_string($row['quantity'] ?? '',14,'cantidad de partida '.($index+1),true),'price'=>hh_string($row['price'] ?? '',13,'precio de partida '.($index+1),true)];
    }
    $amounts = hh_amounts($items, $rate);
    $validity = (string) ($input['validity'] ?? '');
    if (!in_array($validity, ['','15','30','60','90'], true)) throw new HttpError(400, 'Elegí una validez válida.');
    $data = ['brand'=>hh_brand()['id'],'type'=>$input['type'],'number'=>$number,'date'=>$date,'tax'=>$rate,'unit'=>$input['unit'],'company'=>$company,'client'=>$client,'items'=>$items,
        'paymentMethod'=>hh_string($input['paymentMethod'] ?? '',200,'forma de pago'),'paymentTerms'=>hh_string($input['paymentTerms'] ?? '',2000,'condiciones de pago'),
        'notes'=>hh_string($input['notes'] ?? '',4000,'observaciones'),'clause'=>hh_string($input['clause'] ?? '',6000,'cláusula'),'validity'=>$validity,'iban'=>$company['iban']];
    if (isset($input['source'])) {
        $source=$input['source'];
        if (!is_array($source) || !is_string($source['id']??null) || !preg_match('/^[a-f0-9-]{36}$/iD',$source['id']) ||
            !in_array($source['type']??null,['quote','invoice'],true) || !in_array($source['action']??null,['duplicate','conversion'],true))
            throw new HttpError(400,'El documento de origen no es válido.');
        $data['source']=['id'=>$source['id'],'type'=>$source['type'],'number'=>hh_string($source['number']??'',40,'número de origen',true),'action'=>$source['action']];
    }
    return ['data'=>$data,'amounts'=>$amounts,'key'=>hh_number_key($number)];
}
function hh_series(string $type, string $number): ?array {
    if (!preg_match('/^(.*?)(\d{1,9})-(\d{2}|\d{4})$/uD', trim($number), $m)) return null;
    $prefix = function_exists('mb_strtoupper') ? mb_strtoupper($m[1], 'UTF-8') : strtoupper($m[1]);
    $year = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];
    return ['id'=>hash('sha256',json_encode([$type,$prefix,$year])),'type'=>$type,'prefix'=>$prefix,'suffix'=>$m[3],'year'=>$year,'highest'=>(int)$m[2],'padding'=>strlen($m[2]),'last'=>trim($number)];
}
function hh_number_key(string $number): string {
    $series = hh_series('', $number);
    $normal = $series ? json_encode([$series['prefix'],$series['highest'],$series['year']]) : (function_exists('mb_strtoupper') ? mb_strtoupper(trim($number),'UTF-8') : strtoupper(trim($number)));
    return hash('sha256', $normal);
}
function hh_suggestions(PDO $db, int $year): array {
    $answer = [];
    foreach (['quote','invoice'] as $type) {
        $row = hh_query($db, 'SELECT * FROM hh_sequences WHERE type=? AND year=? ORDER BY updated_at DESC, id DESC LIMIT 1', [$type,$year])->fetch();
        $answer[$type] = $row ? ['next'=>$row['prefix'].str_pad((string)((int)$row['highest']+1),(int)$row['padding'],'0',STR_PAD_LEFT).'-'.$row['suffix'],'last'=>$row['last_number'],'year'=>$year] : ['next'=>'001-'.substr((string)$year,-2),'last'=>null,'year'=>$year];
    }
    return $answer;
}
function hh_sequence(PDO $db, string $type, string $number, int $now): void {
    $s = hh_series($type, $number); if (!$s) return;
    hh_query($db, 'INSERT INTO hh_sequences (id,type,prefix,suffix,year,highest,padding,last_number,updated_at) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE padding=IF(VALUES(highest)>=highest,VALUES(padding),padding),last_number=IF(VALUES(highest)>=highest,VALUES(last_number),last_number),suffix=VALUES(suffix),highest=GREATEST(highest,VALUES(highest)),updated_at=VALUES(updated_at)', [$s['id'],$type,$s['prefix'],$s['suffix'],$s['year'],$s['highest'],$s['padding'],$s['last'],$now]);
}
function hh_metadata(array $row): array {
    return ['id'=>$row['id'],'type'=>$row['type'],'number'=>$row['number'],'date'=>$row['document_date'],'clientName'=>$row['client_name'],
        'base'=>(int)$row['base_cents'],'tax'=>(int)$row['tax_cents'],'total'=>(int)$row['total_cents'],'taxRate'=>(int)$row['tax_rate'],'revision'=>(int)$row['revision'],
        'createdAt'=>(int)$row['created_at'],'updatedAt'=>(int)$row['updated_at'],'deletedAt'=>$row['deleted_at']===null?null:(int)$row['deleted_at'],'expiresAt'=>$row['expires_at']===null?null:(int)$row['expires_at']];
}
function hh_find(PDO $db, string $id, int $now, bool $lock = false): array {
    $row = hh_query($db, 'SELECT * FROM hh_documents WHERE id=?' . ($lock?' FOR UPDATE':''), [$id])->fetch();
    if (!$row) throw new HttpError(404, 'No se encontró el documento.');
    if ($row['expires_at'] !== null && (int)$row['expires_at'] <= $now) throw new HttpError(410, 'El plazo de recuperación de 30 días ya terminó.');
    return $row;
}
function hh_pdf_path(string $key): string {
    if (!preg_match('/^[a-f0-9]{64}\.pdf\.php$/D', $key)) throw new RuntimeException('Clave PDF inválida.');
    return hh_brand_data() . '/pdf/' . $key;
}
function hh_purge(PDO $db, int $now): int {
    $rows = hh_query($db, 'SELECT id,pdf_key FROM hh_documents WHERE expires_at IS NOT NULL AND expires_at<=? LIMIT 100',[$now])->fetchAll();
    $purged = 0;
    foreach ($rows as $row) {
        $result = hh_query($db, 'DELETE FROM hh_documents WHERE id=? AND expires_at<=?',[$row['id'],$now]);
        if ($result->rowCount() === 1) { $path=hh_pdf_path($row['pdf_key']); if(is_file($path)) unlink($path); $purged++; }
    }
    return $purged;
}
