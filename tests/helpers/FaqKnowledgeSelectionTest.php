<?php
/** php tests/helpers/FaqKnowledgeSelectionTest.php — no database or AI calls. */
define('BASEPATH', __DIR__);
require_once __DIR__.'/../../application/helpers/faq_suggestion_helper.php';

function check($label, $expected, $actual)
{
    if ($expected!==$actual) {
        fwrite(STDERR,'FAIL '.$label.': '.json_encode($actual).PHP_EOL);
        exit(1);
    }
    echo 'PASS '.$label.PHP_EOL;
}

$today='2026-10-07';
$source=array('SourceID'=>1,'Status'=>'approved','VerifiedBy'=>1,'VerifiedDate'=>$today,
    'Title'=>'Breakfast','Excerpt'=>'Breakfast is included.','DestinationID'=>10,'ProductID'=>null,
    'ResortName'=>'','RoomType'=>'','Topic'=>'breakfast','AppliesToAllRooms'=>0,
    'ValidFrom'=>null,'ValidTo'=>null,'ReviewDue'=>null,'SourceUrl'=>'','FilePath'=>'');
$rows=array($source,
    array_replace($source,array('SourceID'=>2,'DestinationID'=>20)),
    array_replace($source,array('SourceID'=>3,'DestinationID'=>null)),
    array_replace($source,array('SourceID'=>4,'Status'=>'pending')),
    array_replace($source,array('SourceID'=>5,'ValidTo'=>'2026-10-06')),
    array_replace($source,array('SourceID'=>6,'ReviewDue'=>'2026-10-06')),
    array_replace($source,array('SourceID'=>7,'VerifiedBy'=>null)));
$options=array('attached_destinations_only'=>true,'all_destination_sources'=>true,
    'destinations'=>array(array('CategoryID'=>10,'Name'=>'Redang'),array('CategoryID'=>20,'Name'=>'Tioman')));
$query=array('focus'=>'Is breakfast included in Redang?','context'=>'Agent: Tioman includes breakfast.','destination_ids'=>array());
$selection=faq_knowledge_select($query,$rows,array(),$today,$options);
check('destination text alone cannot supply knowledge',array(),$selection['input']);
check('no attached destinations remain empty',array(),$selection['selection']['destination_ids']);
$query['destination_ids']=array(999);
check('unknown destination cannot fall back to text',array(),faq_knowledge_select($query,$rows,array(),$today,$options)['input']);
$query['destination_ids']=array(10);
$selection=faq_knowledge_select($query,$rows,array(),$today,$options);
check('only valid sources for the attached destination are supplied',array('K1'),array_keys($selection['map']));
check('knowledge retains its full excerpt',$source['Excerpt'],$selection['input'][0]['excerpt']);
check('knowledge reports the attached destination',array(10),$selection['selection']['destination_ids']);
$query['destination_ids']=array(10,20);
$selection=faq_knowledge_select($query,$rows,array(),$today,$options);
check('multiple attached destinations are supported',array('K2','K1'),array_keys($selection['map']));

$query['destination_ids']=array(10);
$query['focus']='What activities are available?';
$rows=array($source);
for ($i=10;$i<45;$i++) {
    $rows[]=array_replace($source,array('SourceID'=>$i,'ResortName'=>'Scoped resort','RoomType'=>'Deluxe',
        'Excerpt'=>str_repeat('A full scoped policy with its conditions. ',100)));
}
$selection=faq_knowledge_select($query,$rows,array(),$today,$options+array('limit'=>1));
check('attached destination supplies all its valid topics and scopes',36,count($selection['input']));
check('destination excerpts are not shortened',$rows[35]['Excerpt'],$selection['input'][0]['excerpt']);

$query['focus']='Stay in Redang on 2026-12-15';
$future=array_replace($source,array('ValidFrom'=>'2026-12-01','ValidTo'=>'2026-12-31'));
check('source validity follows the stated travel date',array('K1'),array_keys(faq_knowledge_select($query,array($future),array(),$today,$options)['map']));
$query['focus']='Stay in Redang on 2027-01-15';
check('sources outside the travel date are excluded',array(),faq_knowledge_select($query,array($future),array(),$today,$options)['input']);

// Document generation retains its existing text-based retrieval mode.
$query=array('focus'=>'Redang breakfast','context'=>'');
unset($options['attached_destinations_only']);
check('other retrieval modes retain destination identification',array('K1'),array_keys(faq_knowledge_select($query,array($source),array(),$today,$options)['map']));
echo 'All knowledge selection checks passed.'.PHP_EOL;
