<?php
declare(strict_types=1);
namespace Pup;

final class Media {
    public function __construct(private App $app) {}
    public function upload(array $files,array $alts=[]): array {
        $result=[];$paths=[];
        try {
            foreach($files as $i=>$file){
                if(($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)continue;
                if(($file['error']??1)!==UPLOAD_ERR_OK)throw new \InvalidArgumentException(match($file['error']??1){
                    UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE=>'A photo exceeded the server upload limit. Try a smaller photo.',
                    UPLOAD_ERR_PARTIAL=>'The photo upload was interrupted. Please try again.',
                    default=>'The server could not receive the photo. Check its temporary upload directory and disk space.'});
                if(count($result)>=10)throw new \InvalidArgumentException('Use up to 10 photos in one post.');
                if(!is_uploaded_file($file['tmp_name']))throw new \InvalidArgumentException('Invalid upload.');
                $image=$this->process($file['tmp_name']);
                $paths[]=$image['_path'];unset($image['_path']);
                $image['name']=mb_substr(trim((string)($alts[$i]??'')),0,1000);$result[]=$image;
            }
        } catch(\Throwable $e) {foreach($paths as $p)@unlink($p);throw $e;}
        return $result;
    }
    public function process(string $file): array {
        if(filesize($file)>15*1024*1024)throw new \InvalidArgumentException('Each photo must be under 15 MB.');
        $info=@getimagesize($file);
        if(!$info||!in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true))throw new \InvalidArgumentException('Choose JPEG, PNG, or WebP photos.');
        [$w,$h]=$info;
        if($w<1||$h<1||$w*$h>16000000)throw new \InvalidArgumentException('Use a photo under 16 megapixels.');
        $memory=ini_get('memory_limit');$limit=(int)$memory;
        if($limit>0){$unit=strtolower(substr(trim($memory),-1));$limit*=match($unit){'g'=>1073741824,'m'=>1048576,'k'=>1024,default=>1};
            if(memory_get_usage(true)+$w*$h*12+16*1024*1024>$limit)throw new \InvalidArgumentException('This photo needs more memory than the server allows. Resize it first.');
        }
        $im=match($info[2]){IMAGETYPE_JPEG=>@imagecreatefromjpeg($file),IMAGETYPE_PNG=>@imagecreatefrompng($file),IMAGETYPE_WEBP=>@imagecreatefromwebp($file)};
        if(!$im)throw new \InvalidArgumentException('This photo could not be decoded.');
        if($info[2]===IMAGETYPE_JPEG&&function_exists('exif_read_data')) {
            $exif=@exif_read_data($file);$orientation=(int)($exif['Orientation']??1);
            if(in_array($orientation,[2,4,5,7],true))imageflip($im,IMG_FLIP_HORIZONTAL);
            $degrees=match($orientation){3,4=>180,5,6=>-90,7,8=>90,default=>0};
            if($degrees){$rotated=imagerotate($im,$degrees,0);imagedestroy($im);$im=$rotated;}
        }
        $w=imagesx($im);$h=imagesy($im);$scale=min(1,2560/max($w,$h));$nw=max(1,(int)round($w*$scale));$nh=max(1,(int)round($h*$scale));
        $out=imagecreatetruecolor($nw,$nh);$white=imagecolorallocate($out,255,255,255);imagefill($out,0,0,$white);
        imagecopyresampled($out,$im,0,0,0,0,$nw,$nh,$w,$h);imagedestroy($im);
        $name=bin2hex(random_bytes(20)).'.jpg';$path=$this->app->config['data_dir'].'/media/'.$name;
        if(!imagejpeg($out,$path,90)){imagedestroy($out);throw new \RuntimeException('The photo could not be saved.');}
        imagedestroy($out);chmod($path,0600);
        return ['type'=>'Document','mediaType'=>'image/jpeg','url'=>$this->app->url('/media/'.$name),
            'width'=>$nw,'height'=>$nh,'name'=>'','_path'=>$path];
    }
}
