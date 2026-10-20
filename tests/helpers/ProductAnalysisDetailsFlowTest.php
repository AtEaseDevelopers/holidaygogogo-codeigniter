<?php
/**
 * php tests/helpers/ProductAnalysisDetailsFlowTest.php
 *
 * Exercises analysis -> persistence -> translation -> screen/PDF/Excel without
 * a database or network. The API reply and DB are fakes; the service, model,
 * translation helpers, templates and spreadsheet exporter are the real code.
 */
define('BASEPATH', __DIR__);
define('FCPATH', dirname(__DIR__, 2) . '/');
define('APPPATH', FCPATH . 'application/');
require_once APPPATH . 'helpers/competitor_analysis_helper.php';
require_once APPPATH . 'libraries/CompetitorAnalysisService.php';

$checks = 0;
function expect_details($condition, $label)
{
    global $checks;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $label); }
    $checks++;
}

function get_env($key) { return null; }

class ProductDetailsServiceFake extends CompetitorAnalysisService
{
    public $reply;
    public $requests = array();
    public function __construct() {}
    protected function request($instructions, $input, $tools = array(), $label = '', $json_object = false)
    {
        $this->requests[] = array('instructions' => $instructions, 'input' => $input);
        return json_encode($this->reply);
    }
    protected function log_crawl($event, array $data = array()) {}
}

// A complete source with optional dining, a transfer and proposed hotels. These
// conditions must survive every consumer rather than being reduced to names.
$source = "Harbour & Highlands 3D2N. Malaysia. RM1200 per adult, twin sharing.\n"
    . "Day 1: George Town heritage walk, 90 minutes with steps. Included hawker dinner; drinks extra.\n"
    . "Overnight: Harbour Inn or similar, one night, twin room.\n"
    . "Day 2: Breakfast. Included coach George Town to Cameron Highlands, 4 hours. Tea plantation visit.\n"
    . "Included garden lunch. Optional tea tasting RM35 per person. Dinner at own expense.\n"
    . "Overnight: Hillside Inn or similar, one night, twin room.\n"
    . "Day 3: Breakfast and coach departure transfer.\n"
    . "Includes 2 hotel nights, 2 breakfasts (Days 2 and 3), 1 lunch (Day 2), 1 dinner (Day 1), coach and guided visits.\n"
    . "Hotel ratings, child policy and special dietary arrangements are not specified.";
$reply = array(
    'product_name' => 'Harbour & Highlands', 'destination' => 'George Town and Cameron Highlands',
    'duration' => '3D2N', 'price' => 'RM1200 per adult, twin sharing', 'currency' => 'MYR',
    'countries' => array('Malaysia'), 'cities' => array('George Town', 'Cameron Highlands'),
    'destination_details' => array(
        'George Town, Malaysia: a 90-minute heritage walk and hawker dinner, followed by one overnight stay.',
        'Cameron Highlands, Malaysia: a tea plantation visit and garden lunch, followed by one overnight stay.',
    ),
    'main_usp' => 'Combine a George Town heritage walk with a Cameron Highlands tea plantation visit in one 3-day route.',
    'local_transport' => array('Day 2: included coach from George Town to Cameron Highlands, 4 hours.'),
    'hotels' => array('George Town: Harbour Inn or similar, 1 night, twin room.', 'Cameron Highlands: Hillside Inn or similar, 1 night, twin room.'),
    'meals' => array('breakfast' => '2 (Days 2, 3)', 'lunch' => '1 (Day 2)', 'dinner' => '1 (Day 1)'),
    'food_beverage' => array('Day 1: included hawker dinner; drinks cost extra.', 'Day 2: optional tea tasting, RM35 per person.', 'Day 2: dinner at your own expense.'),
    'customer_advice' => array('Allow for the 4-hour coach journey on Day 2 when deciding whether this pace suits you.', 'Budget RM35 per person if you want the optional tea tasting.', 'Confirm special dietary arrangements before booking if you need them.'),
    'inclusions' => array('2 hotel nights in twin rooms', 'Coach and guided visits'),
    'traveller_segments' => array(array('segment' => 'elderly', 'suitability' => 'Medium', 'justification' => 'Consider whether the 90-minute walk with steps and 4-hour coach journey suit your mobility and comfort needs.')),
    'itinerary' => array(
        array('day' => 'Day 1', 'title' => 'George Town', 'description' => '90-minute heritage walk with steps. Included hawker dinner, drinks extra. Overnight Harbour Inn or similar.'),
        array('day' => 'Day 2', 'title' => 'Cameron Highlands', 'description' => 'Breakfast; 4-hour coach journey, tea plantation visit and garden lunch. Optional RM35 tea tasting. Dinner at own expense; overnight Hillside Inn or similar.'),
        array('day' => 'Day 3', 'title' => 'Departure', 'description' => 'Breakfast and coach departure transfer.'),
    ),
);
$new_keys = array('main_usp', 'destination_details', 'food_beverage', 'customer_advice');
$service = new ProductDetailsServiceFake();
$service->reply = $reply;
$record = $service->analyze_paste($source, array());
foreach ($new_keys as $key) { expect_details($record[$key] === $reply[$key], 'paste preserves ' . $key); }
$batch = $service->analyze_texts(array(array('url' => 'https://example.test/harbour-highlands', 'text' => $source)), array());
foreach ($new_keys as $key) { expect_details($batch['products'][0][$key] === $reply[$key], 'crawl preserves ' . $key); }

// All entry points use the same expanded contract, including PDF/image upload.
foreach (array(
    competitor_build_agent_input('https://example.test/tour', array()),
    competitor_build_scraped_agent('https://example.test/tour', $source, array()),
    competitor_build_file_agent(array()),
    competitor_build_paste_agent($source, array(), array(), false),
) as $spec) {
    foreach ($new_keys as $key) {
        expect_details(strpos($spec['instructions'], '"' . $key . '"') !== false, 'entry point requests ' . $key);
    }
}

// Real persistence code against a tiny in-memory DB double: catches a missing
// details_json allowlist entry, wrong scalar/list default or feature mismatch.
class CI_Model { public $db; }
class ProductDetailsDbFake
{
    private $rows = array();
    public function insert($table, $row)
    {
        $row['id'] = count($this->rows) + 1;
        $row['created_at'] = '2026-10-20 12:00:00';
        $this->rows[$row['id']] = (object) $row;
    }
    public function insert_id() { return count($this->rows); }
    public function get_where($table, $where)
    {
        $row = isset($this->rows[$where['id']]) ? $this->rows[$where['id']] : null;
        return new ProductDetailsResultFake($row && $row->feature === $where['feature'] ? clone $row : null);
    }
}
class ProductDetailsResultFake
{
    private $value;
    public function __construct($value) { $this->value = $value; }
    public function row() { return $this->value; }
}
require_once APPPATH . 'models/Competitor_Analysis_Model.php';
require_once APPPATH . 'models/Our_Product_Model.php';
require_once APPPATH . 'libraries/CompetitorExcelExport.php';

function render_details_template($file, $row, $language = 'en')
{
    $a = $row;
    $p = competitor_row_to_product($row);
    $lang = $language;
    $labels = competitor_ui_labels($lang);
    ob_start();
    include APPPATH . 'views/' . $file;
    return ob_get_clean();
}

$db = new ProductDetailsDbFake();
foreach (array('competitor_product' => new Competitor_Analysis_Model(), 'our_product' => new Our_Product_Model()) as $tool => $model) {
    $model->db = $db;
    $row = $model->Read_One($model->Create($record));
    foreach ($new_keys as $key) { expect_details($row->$key === $reply[$key], $tool . ' database round trip: ' . $key); }
    $combined = $model->Read_One($model->Create(array('products' => $batch['products'])));
    foreach ($new_keys as $key) { expect_details(competitor_display_products($combined)[0][$key] === $reply[$key], 'combined record: ' . $key); }

    foreach (array('_product.php', 'pdf.php') as $template) {
        $html = render_details_template($tool . '/' . $template, $row);
        foreach ($new_keys as $key) {
            foreach ((array) $reply[$key] as $value) {
                expect_details(strpos($html, htmlspecialchars($value)) !== false, $tool . '/' . $template . ' renders ' . $key);
            }
        }
        foreach (array('local_transport', 'hotels') as $key) {
            expect_details(strpos($html, htmlspecialchars($reply[$key][0])) !== false, $template . ' keeps ' . $key . ' conditions');
        }
        expect_details(strpos($html, htmlspecialchars($reply['itinerary'][1]['description'])) !== false, $template . ' keeps daily optional costs');
    }

    $book = (new CompetitorExcelExport())->analysis(array($row));
    $sheet = $book->getSheetByName('Products');
    $data = $sheet->toArray();
    foreach (array('main_usp' => 'Main Selling Point', 'destination_details' => 'Destination Details', 'food_beverage' => 'Food & Beverages', 'customer_advice' => 'Advice for Your Trip') as $key => $header) {
        $column = array_search($header, $data[0], true);
        expect_details($column !== false && $data[1][$column] === implode("\n", (array) $reply[$key]), 'Excel keeps ' . $key);
    }
    expect_details($book->getSheetByName('Itinerary')->getCell('F3')->getValue() === $reply['itinerary'][1]['description'], 'Excel preserves daily meal and optional-cost conditions');
    $book->disconnectWorksheets();

    $translated = competitor_apply_translation_to_row(clone $row, array('products' => array(array(
        'scalars' => array('main_usp' => '乔治市文化漫步与金马仑茶园体验。'),
        'lists' => array('destination_details' => array('乔治市：文化漫步并住宿一晚。'), 'food_beverage' => array('第一天晚餐包含在内，饮料另付。'), 'customer_advice' => array('如需特殊餐食，请在预订前确认。')),
    ))));
    foreach (array('_product.php', 'pdf.php') as $template) {
        $html = render_details_template($tool . '/' . $template, $translated, 'cn');
        expect_details(strpos($html, '核心卖点') !== false && strpos($html, '乔治市文化漫步与金马仑茶园体验。') !== false, 'Chinese main USP renders');
        expect_details(strpos($html, '如需特殊餐食，请在预订前确认。') !== false, 'Chinese advice renders');
    }

    // Old analyses remain readable and do not acquire invented recommendations.
    $old = $model->Read_One($model->Create(array('product_name' => 'Legacy tour')));
    expect_details($old->main_usp === '' && $old->customer_advice === array(), 'legacy records keep unknown details empty');
    foreach (array('_product.php', 'pdf.php') as $template) {
        $html = render_details_template($tool . '/' . $template, $old);
        expect_details(strpos($html, 'Advice for Your Trip') === false && strpos($html, 'Main Selling Point') === false, 'legacy view omits unavailable advice');
    }
}

$translatable = competitor_extract_translatable($record);
expect_details($translatable['scalars']['main_usp'] === $reply['main_usp'], 'translator receives main USP');
foreach (array('destination_details', 'food_beverage', 'customer_advice') as $key) {
    expect_details($translatable['lists'][$key] === $reply[$key], 'translator receives ' . $key);
}
$unknown = competitor_parse_ai_response('{"product_name":"Sparse tour"}');
expect_details($unknown['main_usp'] === '' && $unknown['destination_details'] === array() && $unknown['food_beverage'] === array() && $unknown['customer_advice'] === array(), 'missing evidence stays blank');

echo 'PASS: ' . $checks . " product analysis flow checks (no DB/network).\n";
