<?php
$source = __DIR__.'/public/icons/nasr-logo.jpg';
$image = imagecreatefromjpeg($source);
foreach ([192=>'app-icon-192.png',512=>'app-icon-512.png',512=>'app-maskable-512.png',192=>'parent-icon-192.png',512=>'parent-icon-512.png',512=>'parent-maskable-512.png',180=>'apple-touch-icon.png'] as $size=>$name) {
    $out=imagecreatetruecolor($size,$size); imagealphablending($out,false); imagesavealpha($out,true); $white=imagecolorallocate($out,255,255,255); imagefilledrectangle($out,0,0,$size,$size,$white); imagecopyresampled($out,$image,0,0,0,0,$size,$size,imagesx($image),imagesy($image)); imagepng($out,__DIR__.'/public/icons/'.$name); imagedestroy($out);
}
imagedestroy($image);
