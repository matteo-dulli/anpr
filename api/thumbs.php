<?php
declare(strict_types=1);

function thumbPathFromCacheKey(string $cacheKey,int $w,int $q):?string{
if(!defined('ANPR_THUMBS_DIR'))return null;
$w=max(80,min(1200,$w));
$q=max(40,min(95,$q));
$thumbDir=(string)ANPR_THUMBS_DIR;
return rtrim($thumbDir,"/\\").DIRECTORY_SEPARATOR.md5($cacheKey."|w=$w|q=$q").".jpg";
}

function thumbIsValid(?string $thumbFile):bool{
return is_string($thumbFile)&&$thumbFile!==''&&is_file($thumbFile)&&@filesize($thumbFile)>0;
}

function ensureThumbForImage(string $absImagePath,string $cacheKey,int $w,int $q):?string{
$thumbFile=thumbPathFromCacheKey($cacheKey,$w,$q);
if($thumbFile===null)return null;
if(thumbIsValid($thumbFile))return $thumbFile;
if(!function_exists('imagecreatefromjpeg'))return null;
if(!is_file($absImagePath))return null;

$thumbDir=dirname($thumbFile);
if(!is_dir($thumbDir)&&!@mkdir($thumbDir,0777,true)&&!is_dir($thumbDir))return null;

$lockFp=@fopen($thumbFile.'.lock','c+');
if($lockFp)@flock($lockFp,LOCK_EX);

try{
if(thumbIsValid($thumbFile))return $thumbFile;
$ext=strtolower(pathinfo($absImagePath,PATHINFO_EXTENSION));
$im=false;
switch($ext){
case'jpg':
case'jpeg':$im=@imagecreatefromjpeg($absImagePath);break;
case'png':$im=@imagecreatefrompng($absImagePath);break;
case'gif':$im=@imagecreatefromgif($absImagePath);break;
case'webp':if(function_exists('imagecreatefromwebp'))$im=@imagecreatefromwebp($absImagePath);break;
}
if(!$im)return null;

$srcW=imagesx($im);
$srcH=imagesy($im);
if($srcW<=0||$srcH<=0){imagedestroy($im);return null;}

$w=max(80,min(1200,$w));
$q=max(40,min(95,$q));

$newW=$w;
$newH=(int)round($w*($srcH/$srcW));
$thumb=imagecreatetruecolor($newW,$newH);
imagecopyresampled($thumb,$im,0,0,0,0,$newW,$newH,$srcW,$srcH);

$tmp=$thumbFile.'.tmp.'.getmypid().'.'.bin2hex(random_bytes(4));
$ok=@imagejpeg($thumb,$tmp,$q);

imagedestroy($thumb);
imagedestroy($im);

if(!$ok||!is_file($tmp)||@filesize($tmp)<=0){@unlink($tmp);return null;}

if(!@rename($tmp,$thumbFile)){
if(thumbIsValid($thumbFile)){@unlink($tmp);return $thumbFile;}
if(@copy($tmp,$thumbFile)){@unlink($tmp);return $thumbFile;}
@unlink($tmp);
return null;
}
return $thumbFile;
}finally{
if($lockFp){@flock($lockFp,LOCK_UN);@fclose($lockFp);}
}
}