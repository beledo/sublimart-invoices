<?php
declare(strict_types=1);

function hh_client_record(array $row): array {
    return ['id'=>$row['id'],...json_decode($row['data'],true,64,JSON_THROW_ON_ERROR),'revision'=>(int)$row['revision'],'updatedAt'=>(int)$row['updated_at']];
}
function hh_search_term(string $value): string {
    return '%'.strtr($value,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
}
function hh_clients_api(PDO $db,string $method,string $route,int $now): never {
    if ($route==='clients/lookup' && $method==='GET') {
        $key=hh_tax_key((string)($_GET['nif']??''));
        $row=hh_query($db,'SELECT * FROM hh_clients WHERE tax_key=?',[$key])->fetch();
        hh_json(['client'=>$row ? hh_client_record($row) : null]);
    }
    if ($route==='clients' && $method==='GET') {
        $search=hh_string((string)($_GET['search']??''),150,'búsqueda de cliente');
        $offset=max(0,(int)($_GET['offset']??0));$limit=25;
        $where=$search==='' ? '' : " WHERE name LIKE ? ESCAPE '!' OR tax_key LIKE ? ESCAPE '!'";
        $params=$search==='' ? [] : [hh_search_term($search),hh_search_term(hh_tax_key($search))];
        $rows=hh_query($db,'SELECT * FROM hh_clients'.$where.' ORDER BY name,id LIMIT ? OFFSET ?',[...$params,$limit,$offset])->fetchAll();
        $count=(int)hh_query($db,'SELECT COUNT(*) FROM hh_clients'.$where,$params)->fetchColumn();
        hh_json(['clients'=>array_map('hh_client_record',$rows),'total'=>$count,'nextOffset'=>$offset+count($rows)<$count?$offset+count($rows):null]);
    }
    $updating=$method==='PUT' && preg_match('/^clients\/([a-f0-9-]{36})$/iD',$route,$match);
    if (!$updating && !($route==='clients' && $method==='POST')) throw new HttpError(405,'Esta acción de clientes no está disponible.');
    $body=hh_body();$data=hh_client($body['client']??null,true);$key=hh_tax_key($data['nif']);
    $id=$updating?$match[1]:($body['id']??null);
    if (!is_string($id) || !preg_match('/^[a-f0-9-]{36}$/iD',$id)) throw new HttpError(400,'No se pudo identificar el cliente.');
    $serialized=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $previous=hh_query($db,'SELECT * FROM hh_clients WHERE id=?',[$id])->fetch();
    $duplicate=hh_query($db,'SELECT id FROM hh_clients WHERE tax_key=? AND id<>?',[$key,$id])->fetch();
    if ($duplicate) throw new HttpError(409,'Ya hay un cliente con ese DNI, NIF, CIF o NIE. Buscalo en Clientes para actualizar sus datos.');
    if ($updating && !$previous) throw new HttpError(404,'No se encontró el cliente.');
    if ($previous && !$updating) {
        if ($previous['data']===$serialized) hh_json(['client'=>hh_client_record($previous)]);
        throw new HttpError(409,'Este cliente ya está guardado. Abrilo desde Clientes.');
    }
    try {
        if ($updating) {
            if (($body['revision']??null)!==(int)$previous['revision']) throw new HttpError(409,'El cliente cambió en otra sesión. Abrilo de nuevo.');
            $result=hh_query($db,'UPDATE hh_clients SET tax_key=?, name=?, data=?, revision=revision+1, updated_at=? WHERE id=? AND revision=?',[$key,$data['name'],$serialized,$now,$id,$body['revision']]);
            if ($result->rowCount()!==1) throw new HttpError(409,'El cliente cambió en otra sesión. Abrilo de nuevo.');
        } else {
            hh_query($db,'INSERT INTO hh_clients (id,tax_key,name,data,revision,created_at,updated_at) VALUES (?,?,?,?,1,?,?)',[$id,$key,$data['name'],$serialized,$now,$now]);
        }
    } catch (PDOException $error) {
        if ($error->getCode()==='23000') throw new HttpError(409,'Ese cliente ya se guardó en otra sesión. Buscalo desde Clientes.');
        throw $error;
    }
    hh_json(['client'=>hh_client_record(hh_query($db,'SELECT * FROM hh_clients WHERE id=?',[$id])->fetch())],$updating?200:201);
}
