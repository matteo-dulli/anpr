<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/thumbs.php';

$debug=isset($_GET['debug']);

$rel=isset($_GET['path'])?trim((string)$_GET['path']):'';
$plateId=isset($_GET['id'])?(int)$_GET['id']:0;

$w=isset($_GET['w'])?(int)$_GET['w']:(defined('ANPR_THUMB_W')?(int)ANPR_THUMB_W:0);
$q=isset($_GET['q'])?(int)$_GET['q']:(defined('ANPR_THUMB_Q')?(int)ANPR_THUMB_Q:75);
if($w!==0)$w=max(80,min(1200,$w));
$q=max(40,min(95,$q));

function out_json($arr,int $code=200):void{
http_response_code($code);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($arr,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
exit;
}

$imagesRoot=rtrim(str_replace('\\','/',(string)realpath(MONITORED_FOLDER)?:MONITORED_FOLDER),'/');

if($rel!==''){
$rel=str_replace('\\','/',$rel);
$rel=ltrim($rel,'/');
if($rel===''||strpos($rel,'..')!==false)out_json(['error'=>'path non valido'],400);
$abs=$imagesRoot.'/'.$rel;
if(!is_file($abs))out_json(['error'=>'file non trovato','path'=>$rel,'abs'=>$abs],404);
$mtime=(int)@filemtime($abs);
$size=(int)@filesize($abs);
$cacheKey=$rel.'|'.$mtime.'|'.$size;
if($w===0){
$etag='"'.md5($cacheKey.'|orig').'"';
header('ETag: '.$etag);
header('Cache-Control: public, max-age=31536000, immutable');
if(isset($_SERVER['HTTP_IF_NONE_MATCH'])&&trim((string)$_SERVER['HTTP_IF_NONE_MATCH'])===$etag){http_response_code(304);exit;}
$info=@getimagesize($abs);
$mime=$info['mime']??'application/octet-stream';
header('Content-Type: '.$mime);
header('Content-Length: '.$size);
readfile($abs);
exit;
}
$thumbFile=thumbPathFromCacheKey($cacheKey,$w,$q);
$etag='"'.md5($cacheKey."|w=$w|q=$q").'"';
header('ETag: '.$etag);
header('Cache-Control: public, max-age=31536000, immutable');
if(isset($_SERVER['HTTP_IF_NONE_MATCH'])&&trim((string)$_SERVER['HTTP_IF_NONE_MATCH'])===$etag){http_response_code(304);exit;}
if(thumbIsValid($thumbFile)){
header('Content-Type: image/jpeg');
header('Content-Length: '.filesize($thumbFile));
readfile($thumbFile);
exit;
}
$thumb=ensureThumbForImage($abs,$cacheKey,$w,$q);
if(thumbIsValid($thumb)){
header('Content-Type: image/jpeg');
header('Content-Length: '.filesize($thumb));
readfile($thumb);
exit;
}
out_json(['error'=>'thumb non generabile','path'=>$rel],500);
}

if($plateId<=0)out_json(['error'=>'Specificare path oppure id'],400);

try{
$db=getDatabaseConnection();
$stmt=$db->prepare('SELECT plate_number,plate_corrected FROM plates WHERE id=?');
$stmt->execute([$plateId]);
$plate=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$plate)out_json(['error'=>'Targa non trovata','id'=>$plateId],404);
}catch(Throwable $e){
out_json(['error'=>'Errore DB','message'=>$e->getMessage()],500);
}

$plateNumber=$plate['plate_corrected']?:$plate['plate_number'];
$imagePath=findImageByPlateNumber($plateNumber,MONITORED_FOLDER);
if(!$imagePath||!is_file($imagePath))out_json(['error'=>'Immagine non trovata per targa','plate'=>$plateNumber],404);

$abs=str_replace('\\','/',$imagePath);
$mtime=(int)@filemtime($abs);
$size=(int)@filesize($abs);

if($w===0){
$etag='"'.md5($abs."|$mtime|$size|orig").'"';
header('ETag: '.$etag);
header('Cache-Control: public, max-age=31536000, immutable');
if(isset($_SERVER['HTTP_IF_NONE_MATCH'])&&trim((string)$_SERVER['HTTP_IF_NONE_MATCH'])===$etag){http_response_code(304);exit;}
$info=@getimagesize($abs);
$mime=$info['mime']??'application/octet-stream';
header('Content-Type: '.$mime);
header('Content-Length: '.$size);
readfile($abs);
exit;
}

$relGuess=ltrim(str_replace($imagesRoot,'',$abs),'/');
$cacheKey=$relGuess.'|'.$mtime.'|'.$size;
$thumbFile=thumbPathFromCacheKey($cacheKey,$w,$q);
$etag='"'.md5($cacheKey."|w=$w|q=$q").'"';
header('ETag: '.$etag);
header('Cache-Control: public, max-age=31536000, immutable');
if(isset($_SERVER['HTTP_IF_NONE_MATCH'])&&trim((string)$_SERVER['HTTP_IF_NONE_MATCH'])===$etag){http_response_code(304);exit;}

if(thumbIsValid($thumbFile)){
header('Content-Type: image/jpeg');
header('Content-Length: '.filesize($thumbFile));
readfile($thumbFile);
exit;
}

$thumb=ensureThumbForImage($abs,$cacheKey,$w,$q);
if(thumbIsValid($thumb)){
header('Content-Type: image/jpeg');
header('Content-Length: '.filesize($thumb));
readfile($thumb);
exit;
}

out_json(['error'=>'thumb non generabile','id'=>$plateId,'plate'=>$plateNumber],500);