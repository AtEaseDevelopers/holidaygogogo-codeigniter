<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class FaqSourceImport
{
    /** Read uploaded text locally; DOCX archives are never extracted to disk. */
    function Document($path)
    {
        if (!is_file($path) || filesize($path)>20*1024*1024) { throw new Exception('Documents must be at most 20 MB.'); }
        $extension=strtolower(pathinfo($path,PATHINFO_EXTENSION));
        if ($extension==='txt') {
            if (filesize($path)>2*1024*1024) { throw new Exception('Text documents must contain at most 2 MB of text.'); }
            $text=file_get_contents($path);
            if (substr($text,0,2)==="\xFF\xFE" || substr($text,0,2)==="\xFE\xFF") {
                $text=mb_convert_encoding(substr($text,2),'UTF-8',substr($text,0,2)==="\xFF\xFE"?'UTF-16LE':'UTF-16BE');
            }
            $text=preg_replace('/^\xEF\xBB\xBF/','',$text);
        } elseif ($extension==='docx') {
            if (!class_exists('ZipArchive')) { throw new Exception('Word imports require the PHP ZIP extension.'); }
            $zip=new ZipArchive();
            if ($zip->open($path)!==true) { throw new Exception('Upload a valid Word (.docx) document.'); }
            try {
                if ($zip->locateName('word/document.xml')===false || $zip->locateName('[Content_Types].xml')===false) { throw new Exception('Upload a valid Word (.docx) document.'); }
                $parts=array(); $bytes=0;
                for ($i=0;$i<$zip->numFiles;$i++) {
                    $stat=$zip->statIndex($i);
                    if (!preg_match('#^word/(document|header[0-9]+|footer[0-9]+|footnotes|endnotes)\.xml$#D',$stat['name'])) { continue; }
                    $bytes+=$stat['size'];
                    if ($bytes>2*1024*1024) { throw new Exception('The Word document contains too much text. Upload a smaller document.'); }
                    $xml=$zip->getFromIndex($i);
                    if (!is_string($xml) || stripos($xml,'<!DOCTYPE')!==false || stripos($xml,'<!ENTITY')!==false) { throw new Exception('The Word document contains invalid XML.'); }
                    $doc=new DOMDocument(); $previous=libxml_use_internal_errors(true);
                    try {
                        if (!$doc->loadXML($xml,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING)) { throw new Exception('The Word document could not be read.'); }
                        $xpath=new DOMXPath($doc);
                        $xpath->registerNamespace('w','http://schemas.openxmlformats.org/wordprocessingml/2006/main');
                        $paragraphs=array();
                        foreach ($xpath->query('//w:p') as $paragraph) {
                            $line='';
                            foreach ($xpath->query('.//w:t|.//w:tab|.//w:br|.//w:cr',$paragraph) as $node) {
                                $line.=$node->localName==='t'?$node->textContent:($node->localName==='tab'?"\t":"\n");
                            }
                            if (trim($line)!=='') { $paragraphs[]=$line; }
                        }
                        $parts[$stat['name']]=implode("\n",$paragraphs);
                    } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
                }
                $main=$parts['word/document.xml']??''; unset($parts['word/document.xml']);
                $text=$main."\n".implode("\n",$parts);
            } finally { $zip->close(); }
        } else { throw new Exception('Upload a Word (.docx) or text (.txt) document.'); }
        if (!mb_check_encoding($text,'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$text)) { throw new Exception('The document must contain readable text. Save text files using UTF-8.'); }
        $text=trim($text);
        if ($text==='') { throw new Exception('The document has no readable text. Upload a PDF for scanned documents.'); }
        return $text;
    }

    function CSV($path)
    {
        require_once __DIR__.'/../helpers/faq_source_import_helper.php';
        if (!is_file($path) || filesize($path)>2*1024*1024) { throw new Exception('CSV must be at most 2 MB.'); }
        $h=fopen($path,'r');
        try {
            $headers=fgetcsv($h,0,',','"','');
            if (!$headers) { throw new Exception('CSV must have a header row.'); }
            $headers[0]=preg_replace('/^\xEF\xBB\xBF/','',$headers[0]);
            $headers=array_map('trim',$headers); $rows=array();
            while (($row=fgetcsv($h,0,',','"',''))!==false) {
                if (count($row)===1 && ($row[0]===null || trim($row[0])==='')) { continue; }
                if (count($row)!==count($headers)) { throw new Exception('CSV row '.(count($rows)+2).' does not match its header columns.'); }
                foreach ($row as $v) { if (!mb_check_encoding($v,'UTF-8')) { throw new Exception('Save the CSV using UTF-8 encoding.'); } }
                $max=faq_source_ai_entry_limit();
                $rows[]=$row; if (count($rows)>$max) { throw new Exception('Import up to '.$max.' CSV entries at a time.'); }
            }
            if (!$rows) { throw new Exception('CSV contains no entries.'); }
            return array('headers'=>$headers,'rows'=>$rows);
        } finally { fclose($h); }
    }

    function Map_CSV($csv, $mapping)
    {
        foreach (array('Title','Excerpt') as $required) {
            if (!isset($mapping[$required]) || !is_string($mapping[$required]) || !ctype_digit($mapping[$required])) { throw new Exception('Map the title and content columns.'); }
        }
        $out=array();
        foreach ($csv['rows'] as $i=>$cells) {
            $row=array('SourceType'=>'csv','SourceUrl'=>'','ProductID'=>'0','Topic'=>'','ResortName'=>'','RoomType'=>'','ValidFrom'=>'','ValidTo'=>'','ReviewDue'=>'');
            foreach (array('Title','Excerpt','Topic','ResortName','RoomType') as $field) {
                $column=$mapping[$field]??'';
                if (!is_string($column) || ($column!=='' && (!ctype_digit($column) || !array_key_exists((int)$column,$cells)))) { throw new Exception('Invalid CSV column mapping.'); }
                $row[$field]=$column===''?'':trim($cells[(int)$column]);
            }
            if ($row['Title']==='' || $row['Excerpt']==='') { throw new Exception('CSV row '.($i+2).' needs a title and content.'); }
            $row['ExtractedText']='CSV row '.($i+2)."\n".$row['Excerpt']; $out[]=$row;
        }
        return $out;
    }

    function Public_URL($url)
    {
        if (!is_string($url) || strlen($url)>2048 || !filter_var($url,FILTER_VALIDATE_URL)) { throw new Exception('Enter a valid public HTTP/HTTPS URL.'); }
        $p=parse_url($url);
        if (!in_array(strtolower($p['scheme']??''),array('http','https'),true) || isset($p['user']) || isset($p['pass']) || (isset($p['port']) && !in_array($p['port'],array(80,443),true))) { throw new Exception('Use a public HTTP/HTTPS page without credentials or a custom port.'); }
        $host=strtolower(trim($p['host'],'[]')); $ips=array();
        if (filter_var($host,FILTER_VALIDATE_IP)) { $ips[]=$host; }
        else {
            if (!preg_match('/^[a-z0-9.-]+$/D',$host)) { throw new Exception('Invalid hostname.'); }
            $records=@dns_get_record($host,DNS_A|DNS_AAAA);
            foreach ((array)$records as $record) { if (isset($record['ip'])) { $ips[]=$record['ip']; } if (isset($record['ipv6'])) { $ips[]=$record['ipv6']; } }
        }
        if (!$ips) { throw new Exception('Could not resolve the URL hostname.'); }
        foreach ($ips as $ip) {
            if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE) || strpos(strtolower($ip),'::ffff:')===0) { throw new Exception('The URL must resolve only to public internet addresses.'); }
        }
        return array('host'=>$host,'port'=>$p['port']??(strtolower($p['scheme'])==='https'?443:80),'ip'=>$ips[0]);
    }

    function Redirect_URL($base, $location)
    {
        if (preg_match('#^https?://#i',$location)) { return $location; }
        $p=parse_url($base); $origin=$p['scheme'].'://'.$p['host'].(isset($p['port'])?':'.$p['port']:'');
        if (strpos($location,'//')===0) { return $p['scheme'].':'.$location; }
        if (strpos($location,'?')===0) { return $origin.($p['path']??'/').$location; }
        $path=strpos($location,'/')===0?$location:rtrim(dirname($p['path']??'/'),'/').'/'.$location;
        return $origin.$path;
    }

    function Read_URL($url)
    {
        for ($redirect=0;$redirect<4;$redirect++) {
            $target=$this->Public_URL($url); $body=''; $location=''; $ch=curl_init($url);
            $ip=strpos($target['ip'],':')!==false?'['.$target['ip'].']':$target['ip'];
            curl_setopt_array($ch,array(CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,
                CURLOPT_RESOLVE=>array($target['host'].':'.$target['port'].':'.$ip),CURLOPT_ENCODING=>'',CURLOPT_USERAGENT=>'HolidayGoGoGo Knowledge Source Reader/1.0',
                CURLOPT_HEADERFUNCTION=>function($c,$line)use(&$location){if (stripos($line,'Location:')===0) { $location=trim(substr($line,9)); } return strlen($line);},
                CURLOPT_WRITEFUNCTION=>function($c,$chunk)use(&$body){if (strlen($body)+strlen($chunk)>2*1024*1024) { return 0; } $body.=$chunk; return strlen($chunk);}));
            $ok=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_RESPONSE_CODE); $type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE); $error=curl_error($ch); curl_close($ch);
            if ($ok===false) { throw new Exception('Could not read the page: '.$error); }
            if ($code>=300 && $code<400 && $location!=='') { $url=$this->Redirect_URL($url,$location); continue; }
            if ($code<200 || $code>=300) { throw new Exception('Page returned HTTP '.$code.'.'); }
            if (stripos($type,'text/plain')===0) { return array('title'=>parse_url($url,PHP_URL_HOST),'text'=>trim($body),'url'=>$url); }
            if (stripos($type,'text/html')!==0 && stripos($type,'application/xhtml+xml')!==0) { throw new Exception('The URL must be an HTML or text page. Import PDF files using Import PDF.'); }
            return $this->HTML($body,$url);
        }
        throw new Exception('The page redirected too many times.');
    }

    function HTML($html, $url)
    {
        $doc=new DOMDocument(); $previous=libxml_use_internal_errors(true);
        try {
            $doc->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
            $title=$doc->getElementsByTagName('title')->item(0); $title=$title?trim($title->textContent):parse_url($url,PHP_URL_HOST);
            $xpath=new DOMXPath($doc);
            foreach ($xpath->query('//script|//style|//noscript|//svg|//nav|//footer|//header') as $node) { $node->parentNode->removeChild($node); }
            foreach ($xpath->query('//p|//div|//li|//h1|//h2|//h3|//tr|//br') as $node) { $node->appendChild($doc->createTextNode("\n")); }
            $body=$doc->getElementsByTagName('body')->item(0);
            $text=trim(preg_replace('/[ \t]+/u',' ',($body?:$doc)->textContent)); $text=preg_replace('/\n\s*\n\s*\n/u',"\n\n",$text);
            if ($text==='') { throw new Exception('The page has no readable text. Use Manual Entry for pages that require login or JavaScript.'); }
            return array('title'=>$title,'text'=>$text,'url'=>$url);
        } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    }
}
