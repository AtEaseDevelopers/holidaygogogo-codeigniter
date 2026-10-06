<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Domain matching runs locally so preview and evaluation use the same selection. */
function faq_knowledge_norm($text)
{
    $text=preg_replace('/(\d)(\p{L})/u','$1 $2',(string)$text);
    $text=faq_suggestion_norm_msg($text);
    return preg_replace('/\bclubmed\b/u','club med',$text);
}

function faq_knowledge_has($text, $phrase)
{
    $phrase=faq_knowledge_norm($phrase);
    return $phrase!=='' && strpos(' '.$text.' ',' '.$phrase.' ')!==false;
}

/** Short names are accepted only when they identify one resort, not a shared location. */
function faq_knowledge_resorts($text, $names)
{
    $entities=array(); $matched=array();
    foreach (array_unique($names) as $name) {
        $full=faq_knowledge_norm($name); if ($full==='') { continue; }
        $core=trim(preg_replace('/\b(the|hotel|resort|beach|spa|club|med|island|pulau)\b/u','',$full));
        $core=preg_replace('/\s+/u',' ',$core); $entities[$full]=$core;
        if (faq_knowledge_has($text,$full)) { $matched[$full]=true; }
    }
    foreach ($entities as $full=>$core) {
        if ($core==='' || mb_strlen($core,'UTF-8')<3 || !faq_knowledge_has($text,$core)) { continue; }
        $ambiguous=false;
        foreach ($entities as $other=>$other_core) {
            if ($other_core!==$core && faq_knowledge_has($other_core,$core)) { $ambiguous=true; break; }
        }
        if (!$ambiguous) { $matched[$full]=true; }
    }
    return $matched;
}

/** Common English/Malay phrasing is mapped to practical travel subjects. */
function faq_knowledge_intents($text)
{
    $groups=array(
        'children'=>array('child','children','kid','kids','baby','babies','infant','toddler','teen','teenager','budak','anak','bayi','kanak kanak','umur','years old','tahun ke bawah','bawah 2','under 2'),
        'child_eligibility'=>array('can i bring','can we bring','can stay','minimum age','child eligibility','infant eligibility','boleh bawa','umur minimum'),
        'childcare'=>array('childcare','kids club','kids clubs','baby club','nursery','penjagaan kanak kanak','penjagaan bayi'),
        'extra_bed'=>array('mattress','extra bed','additional bed','add bed','tilam','katil tambahan'),
        'occupancy'=>array('occupancy','room capacity','maximum pax','max pax','how many people','how many adults','fit in','muat','berapa orang','kapasiti bilik'),
        'rooms'=>array('room','rooms','room type','suite','deluxe','superior','amenities','bilik','kemudahan bilik','wifi','wi fi','internet','air conditioning','minibar'),
        'accessibility'=>array('wheelchair','accessible','accessibility','stairs','stairway','lift','elevator','kerusi roda','oku','tangga'),
        'meals'=>array('meal','meals','breakfast','lunch','dinner','food','dining','restaurant','sarapan','makan','makanan','restoran'),
        'inclusions'=>array('included','inclusive','inclusions','exclusions','include','what is covered','what do i get','termasuk','pakej termasuk'),
        'transfers'=>array('transfer','transfers','airport','shuttle','pickup','pick up','transport','flight','flights','van','lapangan terbang','pengangkutan','penerbangan'),
        'location'=>array('location','located','address','how to get','how do i get','where is','where are','journey','travel time','distance','lokasi','alamat','kat mana','di mana','berapa jauh','berapa lama'),
        'activities'=>array('activity','activities','sports','kayak','sailing','archery','trapeze','aktiviti','sukan'),
        'facilities'=>array('pool','swimming','zen pool','adults only','facility','facilities','kolam','berenang','dewasa sahaja'),
        'pricing'=>array('price','pricing','rate','rates','cost','charge','charges','discount','how much','harga','berapa harga','berapa caj','berapa kos','bayaran','caj','diskaun'),
        'booking'=>array('book','booking','reserve','reservation','availability','available','tempah','tempahan','kekosongan'),
        'payment'=>array('payment','pay','deposit','instalment','installment','bayar','pembayaran','ansuran'),
        'cancellation'=>array('cancel','cancellation','refund','refundable','change dates','reschedule','batal','pembatalan','tukar tarikh','bayaran balik'),
        'check_in_out'=>array('check in','check out','checkin','checkout','early arrival','late checkout','waktu masuk','waktu keluar'),
        'requirements'=>array('passport','visa','entry requirements','travel requirements','pasport','dokumen perjalanan','syarat kemasukan'),
        'maintenance'=>array('closure','closed','renovation','maintenance','reopen','tutup','penutupan','pengubahsuaian','baik pulih'),
    );
    $intents=array();
    foreach ($groups as $intent=>$phrases) {
        foreach ($phrases as $phrase) { if (faq_knowledge_has($text,$phrase)) { $intents[$intent]=true; break; } }
    }
    return $intents;
}

function faq_knowledge_topic_intents($topic)
{
    $groups=array(
        'children'=>array('child_rates','child_eligibility','childcare','kids_club_childcare','child_policy','children_policy'),
        'child_eligibility'=>array('child_eligibility','child_policy','children_policy'),
        'childcare'=>array('childcare','kids_club_childcare'),
        'extra_bed'=>array('extra_bed','mattress'), 'occupancy'=>array('room_occupancy'),
        'rooms'=>array('room_facilities','room_occupancy','room_inventory','extra_bed','accessibility'),
        'accessibility'=>array('accessibility'), 'meals'=>array('breakfast','dining','all_inclusive_inclusions','package_inclusions'),
        'inclusions'=>array('package_inclusions','package_exclusions','all_inclusive_inclusions','activity_inclusions'),
        'transfers'=>array('transfers','transport_transfers','flight_transfer_booking','kuala_lumpur_access','map_access'),
        'location'=>array('location_access','resort_location','map_access','kuala_lumpur_access','transfers'),
        'activities'=>array('activities','activity_options','activity_categories','activity_inclusions'),
        'facilities'=>array('facility_restrictions','adults_only_facility','room_facilities'),
        'pricing'=>array('pricing','child_rates','extra_bed'), 'booking'=>array('booking_policy','maintenance_closure','renovation_booking_unavailability'),
        'payment'=>array('payment_policy'), 'cancellation'=>array('cancellation_policy'),
        'check_in_out'=>array('check_in_out'), 'requirements'=>array('travel_requirements'),
        'maintenance'=>array('maintenance_closure','renovation_booking_unavailability','restaurant_maintenance'),
    );
    $result=array(); foreach ($groups as $intent=>$topics) { if (in_array($topic,$topics,true)) { $result[$intent]=true; } }
    return $result;
}

/** Dates in a stated stay/travel context; conversation timestamps are not travel dates. */
function faq_knowledge_travel_dates($text)
{
    $dates=array();
    $context='/\b(?:check[ -]?in|check[ -]?out|travel(?:ling)?|stay(?:ing)?|trip|arrival|departure|pergi|bercuti|tarikh(?: perjalanan)?|masuk|keluar)\b[^\n.!?]{0,160}/iu';
    $pattern='/\b((?:\d{4}-\d{2}-\d{2})|(?:\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4})|(?:\d{1,2}\s+(?:January|February|March|April|May|June|July|August|September|October|November|December|Januari|Februari|Mac|Mei|Jun|Julai|Ogos|Oktober|Disember)\s+\d{4}))\b/iu';
    preg_match_all($context,(string)$text,$windows);
    if (preg_match_all($pattern,implode("\n",$windows[0]),$matches)) {
        foreach ($matches[1] as $raw) {
            $date=faq_suggestion_valid_date($raw);
            if ($date==='' && preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/D',$raw,$parts)) { $date=faq_suggestion_valid_date(sprintf('%04d-%02d-%02d',$parts[3],$parts[2],$parts[1])); }
            if ($date==='') {
                $months=array('januari'=>'January','februari'=>'February','mac'=>'March','mei'=>'May','jun'=>'June','julai'=>'July','ogos'=>'August','oktober'=>'October','disember'=>'December');
                $raw=preg_replace_callback('/\b(Januari|Februari|Mac|Mei|Jun|Julai|Ogos|Oktober|Disember)\b/iu',function($m)use($months){return $months[mb_strtolower($m[1],'UTF-8')];},$raw);
                $parsed=date_parse($raw);
                if (!$parsed['error_count'] && !$parsed['warning_count'] && $parsed['year'] && $parsed['month'] && $parsed['day']) { $date=faq_suggestion_valid_date(sprintf('%04d-%02d-%02d',$parsed['year'],$parsed['month'],$parsed['day'])); }
            }
            if ($date!=='') { $dates[$date]=$date; }
        }
    }
    return array_values($dates);
}

/** Old drafted answers do not influence what evidence is retrieved for the question. */
function faq_knowledge_candidate_query($candidate, $additional='', $messages=array())
{
    $candidate=(array)$candidate; $focus=array((string)($candidate['title']??$candidate['Title']??''));
    $items=$candidate['items']??Faq_Model::Decode_Items($candidate['Description']??'');
    foreach ($items as $item) { $focus[]=(string)($item['q']??''); }
    if ($additional!=='') { $focus[]=$additional; }
    $context=array();
    foreach ($messages as $message) { $message=(array)$message; $context[]=(string)($message['text']??$message['excerpt']??$message['SourceExcerpt']??''); }
    return array('focus'=>implode("\n",$focus),'context'=>implode("\n",$context));
}

/** Select complete, applicable excerpts. Scope alone or a zero topic match is insufficient. */
function faq_knowledge_select($query, $rows, $products, $today, $options=array())
{
    $query=is_array($query)?$query:array('focus'=>(string)$query,'context'=>'');
    $focus=faq_knowledge_norm($query['focus']); $context=faq_knowledge_norm($query['context']??'');
    $text=trim($focus.' '.$context); $names=array(); $packages=array(); $matched_products=array();
    $catalog=array_merge($rows,$options['scope_catalog']??array());
    foreach ($catalog as $row) { $row=(array)$row; if (!empty($row['ResortName'])) { $names[]=$row['ResortName']; } }
    $resorts=faq_knowledge_resorts($focus,$names);
    if (!$resorts) { $resorts=faq_knowledge_resorts($context,$names); }
    foreach ($products as $product) {
        $product=(array)$product; $packages[(int)$product['ProductID']]=$product;
        if (faq_knowledge_has($focus,$product['Name']) || faq_knowledge_has($focus,$product['ProductCode']??'')) { $matched_products[(int)$product['ProductID']]=true; }
    }
    if (!$matched_products) { foreach ($packages as $id=>$product) { if (faq_knowledge_has($context,$product['Name']) || faq_knowledge_has($context,$product['ProductCode']??'')) { $matched_products[$id]=true; } } }
    $rooms=array(); foreach ($catalog as $row) { $row=(array)$row; if (!empty($row['RoomType']) && faq_knowledge_has($focus,$row['RoomType'])) { $rooms[faq_knowledge_norm($row['RoomType'])]=true; } }
    foreach (array_keys($rooms) as $room) { foreach (array_keys($rooms) as $other) { if ($room!==$other && faq_knowledge_has($other,$room) && !faq_knowledge_has(trim(str_replace(' '.$other.' ',' ',' '.$focus.' ')),$room)) { unset($rooms[$room]); break; } } }
    $intents=faq_knowledge_intents($focus); if (!$intents) { $intents=faq_knowledge_intents($context); }
    $query_dates=faq_knowledge_travel_dates($query['focus']."\n".($query['context']??''));
    $dates=$options['travel_dates']??(!empty($options['batch'])?array():$query_dates);
    $limit=max(1,min(30,(int)($options['limit']??10))); $ranked=array();
    $entity_terms=faq_knowledge_norm(implode(' ',$names).' '.implode(' ',array_column($packages,'Name')));
    $stop=array_flip(explode(' ','the a an is are can i we you do does how what when which where with for of to in on at and or have has it this that please help policy information question answer customer resort hotel club med beach package saya kami boleh nak mahu ada ke di untuk dan atau berapa apa ini itu tahun years old'));
    $words=array();
    foreach (explode(' ',$focus) as $word) { if (mb_strlen($word,'UTF-8')>=3 && !isset($stop[$word]) && !faq_knowledge_has($entity_terms,$word) && !is_numeric($word)) { $words[$word]=true; } }
    foreach ($rows as $source) {
        $s=(array)$source;
        $valid=faq_workspace_source_valid($s,$today,$dates?reset($dates):'');
        if (!$valid && !empty($options['batch'])) { foreach ($query_dates as $date) { if (faq_workspace_source_valid($s,$today,$date)) { $valid=true; break; } } }
        if (!$valid) { continue; }
        $date_valid=true; foreach ($dates as $date) { if (!faq_workspace_source_valid($s,$today,$date)) { $date_valid=false; break; } }
        if (!$date_valid) { continue; }
        $reasons=array(); $score=0;
        if (!empty($s['ProductID'])) {
            if (!isset($matched_products[(int)$s['ProductID']])) { continue; }
            $reasons[]='Package: '.$packages[(int)$s['ProductID']]['Name']; $score+=35;
        }
        if (!empty($s['ResortName'])) {
            if (!isset($resorts[faq_knowledge_norm($s['ResortName'])])) { continue; }
            $reasons[]='Resort: '.$s['ResortName']; $score+=30;
        }
        if (!empty($s['RoomType'])) {
            if (($rooms && !isset($rooms[faq_knowledge_norm($s['RoomType'])])) || (!$rooms && !faq_knowledge_has($context,$s['RoomType']))) { continue; }
            $reasons[]='Room: '.$s['RoomType']; $score+=20;
        }
        $source_intents=faq_knowledge_topic_intents($s['Topic']);
        $source_text=faq_knowledge_norm($s['Title'].' '.$s['Excerpt']);
        $source_intents+=faq_knowledge_intents(faq_knowledge_norm($s['Excerpt']));
        $matched=array_intersect_key($intents,$source_intents);
        $specific=array_diff_key($intents,array_flip(array('pricing','inclusions','booking','rooms')));
        if ($specific && !array_intersect_key($specific,$source_intents)) { continue; }
        $overlap=array(); foreach ($words as $word=>$unused) { if (faq_knowledge_has($source_text,$word)) { $overlap[]=$word; } }
        if (!$matched && count($overlap)<2) { continue; }
        if ($matched) { $reasons[]='Subject: '.implode(', ',array_map(function($v){return ucfirst(str_replace('_',' ',$v));},array_keys($matched))); $score+=20+5*count($matched); }
        if ($overlap) { $score+=min(15,3*count($overlap)); if (!$matched) { $reasons[]='Question terms: '.implode(', ',array_slice($overlap,0,4)); } }
        if (!$reasons) { continue; }
        if (empty($s['ProductID']) && empty($s['ResortName']) && empty($s['RoomType'])) { $reasons[]='Agency-wide source'; }
        if ($dates) { $reasons[]='Travel date: '.implode(', ',$dates); }
        $ranked[]=array('score'=>$score,'source'=>$s,'reasons'=>$reasons);
    }
    usort($ranked,function($a,$b){return $b['score']-$a['score'] ?: (int)$b['source']['SourceID']-(int)$a['source']['SourceID'];});
    if (!empty($options['batch'])) {
        // Give different resorts/subjects a turn before one subject consumes a batch's budget.
        $groups=array(); foreach ($ranked as $entry) { $s=$entry['source']; $key=json_encode(array($s['ProductID'],$s['ResortName'],$s['RoomType'],$s['Topic'])); $groups[$key][]=$entry; }
        $ranked=array();
        while ($groups) { foreach ($groups as $key=>$group) { $ranked[]=array_shift($groups[$key]); if (!$groups[$key]) { unset($groups[$key]); } } }
    }
    $input=array(); $map=array(); $selection=array(); $bytes=0; $seen=array();
    foreach ($ranked as $entry) {
        $s=$entry['source']; $ref='K'.(int)$s['SourceID'];
        $key=hash('sha256',json_encode(array($s['ProductID'],$s['ResortName'],$s['RoomType'],$s['ValidFrom'],$s['ValidTo'],$s['ReviewDue'],$s['AppliesToAllRooms'],mb_strtolower(trim(preg_replace('/\s+/u',' ',$s['Excerpt'])),'UTF-8'))));
        if (isset($seen[$key])) { continue; }
        $item=array('reference'=>$ref,'title'=>$s['Title'],'excerpt'=>$s['Excerpt'],'topic'=>$s['Topic'],
            'package_name'=>$packages[(int)$s['ProductID']]['Name']??'','resort'=>$s['ResortName'],'room_type'=>$s['RoomType'],
            'applies_to_all_rooms'=>(bool)$s['AppliesToAllRooms'],'valid_from'=>$s['ValidFrom'],'valid_to'=>$s['ValidTo'],
            'selection_reason'=>implode(' · ',$entry['reasons']));
        $size=strlen(json_encode($item,JSON_UNESCAPED_UNICODE));
        if ($bytes+$size>60000) { continue; }
        $bytes+=$size; $seen[$key]=true; $input[]=$item; $s['_travel_dates']=$dates; $map[$ref]=$s;
        $selection[]=$item+array('source_id'=>(int)$s['SourceID'],'source_url'=>$s['SourceUrl']??'');
        if (count($input)>=$limit) { break; }
    }
    $matched_packages=array(); foreach ($matched_products as $id=>$unused) { $matched_packages[]=array('name'=>$packages[$id]['Name'],'code'=>$packages[$id]['ProductCode']); }
    return array('input'=>$input,'map'=>$map,'packages'=>$matched_packages,
        'selection'=>array('sources'=>$selection,'travel_dates'=>$dates,'limit'=>$limit,'batch'=>!empty($options['batch']),'query'=>$query['focus']));
}
