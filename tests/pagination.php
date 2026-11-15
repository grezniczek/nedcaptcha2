<?php
// Set REDCAP_SURVEY_CLASS to also run these requests through a local REDCap's
// real page calculation/navigation methods. Database and data saves stay in memory.
define('PROJECT_ID', 355);
function db_escape($value) { return addslashes($value); }
function db_query($sql) {
    if (stripos($sql, 'SELECT s.project_id') !== false) return ['project_id' => 355, 'form_name' => 'form_1', 'survey_id' => 1];
    if (stripos($sql, 'select participant_id') !== false) return ['participant_id' => 10];
    if (stripos($sql, 'select response_id') !== false) return ['response_id' => 20];
    if (stripos($sql, 'select m.field_name') !== false) return ['field_name' => $GLOBALS['lastSavedField']];
    throw new RuntimeException('Unexpected test query');
}
function db_num_rows($result) { return count($result) ? 1 : 0; }
function db_fetch_assoc($result) { return $result; }
function db_result($result, $row) { return reset($result); }
function pre_query($sql) { return "'survey-record'"; }
class Econsent {
    public static function econsentEnabledForSurvey($survey) { return false; }
}
class Records {
    public static function getDataTable($pid) { return 'redcap_data'; }
}
class RCView {
    public static function tt($key) { return $key; }
}
class REDCap {
    public static $data = [];
    public static $saves = [];
    public static $failure = null;
    public static function saveData($args) {
        self::$saves[] = $args;
        // Like the real data API, read the cached Project, not just global Proj.
        $project = new Project($args['project_id']);
        check(isset($project->metadata['captcha'], $project->forms['form_1']['fields']['captcha']), 'Data API cannot see the capture field');
        check(isset($GLOBALS['Proj']->metadata['captcha']), 'Data API global metadata missing');
        if (self::$failure === 'throw') throw new RuntimeException('Simulated save failure');
        if (self::$failure === 'errors') return ['errors' => ['Simulated validation failure']];
        self::$data = array_replace_recursive(self::$data, $args['data']);
        return ['errors' => []];
    }
}

require_once __DIR__ . '/survey-bootstrap.php';
use DE\RUB\SEG\NEDCaptcha2ExternalModule\NEDCaptcha2ExternalModule as Module;

$GLOBALS['table_pk'] = 'record_id';
$GLOBALS['__SALT__'] = 'pagination-regression';
$GLOBALS['save_and_return_code_bypass'] = '';
$GLOBALS['public_survey'] = true;
$GLOBALS['return_code'] = '';
$GLOBALS['survey_btn_text_next_page'] = '';
$GLOBALS['survey_btn_text_submit'] = '';
Project::$saveAndReturn = true;
$custom = ['type' => 'custom', 'capture' => true, 'custom' => [['challenge' => 'Question', 'response' => 'answer']]];
$proof = [Module::CLIENT_KEY => Crypto::getGuid()];
$responseHash = getenv('REDCAP_SURVEY_CLASS') ? Survey::encryptResponseHash(20, 10) : 'valid-response';
$continuation = ['__response_hash__' => $responseHash];
$key = Module::STORE_KEY . 'public-hash_' . Crypto::getGuid();

function surveyRequest($post = [], $get = [], $params = null) {
    return request($params ?? $GLOBALS['custom'], $post, $get, ['second' => '', 'form_1_complete' => '']);
}
function pages() {
    [$pages] = Survey::getPageFields('form_1', true);
    return array_map('array_values', $pages);
}
function solve($params = null) {
    $_SESSION = [];
    SurveyFramework::$logs = [];
    REDCap::$data = REDCap::$saves = [];
    surveyRequest([], [], $params);
    $module = surveyRequest($GLOBALS['proof'] + ['captcha' => 'answer'], [], $params);
    check(!isset($GLOBALS['Proj']->metadata['captcha']), 'Solved display still includes CAPTCHA');
    return $module;
}
function submit($post, $get = [], $params = null) {
    $module = surveyRequest($post, $get, $params);
    [$pages] = Survey::getPageFields('form_1', true);
    // REDCap Surveys/index.php discards fields that belong to a different page
    // before DataEntry::saveRecord. Apply that boundary before simulating saving.
    foreach ($_POST as $field => $value) {
        if (isset($GLOBALS['Proj']->forms['form_1']['fields'][$field]) && $field !== 'record_id' && $field !== 'form_1_complete'
            && !in_array($field, $pages[$post['__page__']] ?? [], true)) unset($_POST[$field]);
    }
    foreach ($_POST as $field => $value) {
        if (isset($GLOBALS['Proj']->forms['form_1']['fields'][$field])) REDCap::$data['survey-record'][1][$field] = $value;
    }
    $surveyProject = $GLOBALS['Proj'];
    $cachedProject = Project::$project_cache[355];
    $module->redcap_save_record(355, 'survey-record', 'form_1', 1, null, 'public-hash', 20, 1);
    check($GLOBALS['Proj'] === $surveyProject && Project::$project_cache[355] === $cachedProject, 'Capture replaced the survey project/cache');
    return $module;
}
function navigate() {
    [$pages, $count] = Survey::getPageFields('form_1', true);
    Survey::setPageNum($pages, $count);
    return $_GET['__page__'];
}

// A CAPTCHA-only first section, as in the bundled demo, must disappear on every
// ordinary request. Also cover a helper sharing the first section with questions.
foreach (['separate' => ['normal' => 'Section 1', 'second' => 'Section 2'], 'shared' => ['second' => 'Section 2']] as $layout => $headers) {
    Project::$headers = $headers;
    solve();
    $expectedPages = [1 => ['normal'], 2 => ['second']];
    check(pages() === $expectedPages, "$layout: display page layout incorrect");
    $post = $proof + ['submit-action' => 'submit-btn-saverecord', '__page__' => 1, 'normal' => 'first answer', 'captcha' => 'tampered'];
    $module = submit($post);
    check(pages() === $expectedPages, "$layout: first submission changed page layout");
    check(REDCap::$data['survey-record'][1] === ['normal' => 'first answer', 'captcha' => 'answer'], "$layout: survey/captured answers were lost or capture trusted POST");
    check(navigate() == 2, "$layout: Next skipped/repeated a page");
    check(!isset(SurveyFramework::$logs[$key]), "$layout: capture did not consume proof");
    $module->redcap_save_record(355, 'survey-record', 'form_1', 1, null, 'public-hash', 20, 1);
    check(count(REDCap::$saves) === 1, "$layout: duplicate save hook repeated capture");

    submit($continuation + ['submit-action' => 'submit-btn-saverecord', '__page__' => 2, 'second' => 'second answer'], ['__prevpage' => 1]);
    check(pages() === $expectedPages && navigate() == 1, "$layout: Previous returned to wrong page");
    submit($continuation + ['submit-action' => 'submit-btn-saverecord', '__page__' => 1, 'normal' => 'edited']);
    check(navigate() == 2 && REDCap::$data['survey-record'][1]['normal'] === 'edited', "$layout: revisited page did not save");
    submit($continuation + ['submit-action' => 'submit-btn-saverecord', '__page__' => 2, 'second' => 'finished']);
    check(pages() === $expectedPages && REDCap::$data['survey-record'][1]['second'] === 'finished', "$layout: final-page response lost");
    check(count(REDCap::$saves) === 1, "$layout: continuation repeated CAPTCHA capture");

    // Required-field redisplay must preserve both the layout and the first proof.
    solve();
    surveyRequest($post, ['__reqmsg' => 1]);
    check(pages() === $expectedPages && navigate() == 1, "$layout: required-field redisplay shifted pages");
    check(isset(SurveyFramework::$logs[$key]), "$layout: unsaved validation failure consumed proof");

    // Save & Return on either page, code entry, then continued editing.
    foreach ([1, 2] as $savePage) {
        solve();
        if ($savePage === 2) submit($post);
        $field = $savePage === 1 ? 'normal' : 'second';
        submit(($savePage === 1 ? $proof : $continuation) + ['submit-action' => 'submit-btn-savereturnlater', '__page__' => $savePage, $field => 'saved before return'], ['__return' => 1]);
        check(pages() === $expectedPages, "$layout: Save & Return changed page numbering");
        check(REDCap::$data['survey-record'][1][$field] === 'saved before return', "$layout: Save & Return dropped answers");
        check(REDCap::$data['survey-record'][1]['captcha'] === 'answer', "$layout: Save & Return lost capture");
        surveyRequest([], ['__return' => 1]);
        check(pages() === $expectedPages, "$layout: return-code entry layout shifted");
        surveyRequest(['__code' => 'return-code']);
        $GLOBALS['return_code'] = 'return-code'; // REDCap has validated the code.
        $GLOBALS['lastSavedField'] = $field;
        $_GET += ['event_id' => 1, 'instance' => 1, 'page' => 'form_1'];
        check(pages() === $expectedPages && navigate() == $savePage, "$layout: resumed on wrong page");
        $GLOBALS['return_code'] = '';
        submit($continuation + ['submit-action' => 'submit-btn-saverecord', '__page__' => $savePage, $field => 'edited after return']);
        check(REDCap::$data['survey-record'][1][$field] === 'edited after return' && count(REDCap::$saves) === 1, "$layout: resumed save lost data or repeated capture");
    }
}

// Single-page surveys must retain their ordinary fields and capture too.
Project::$headers = [];
solve();
submit($proof + ['submit-action' => 'submit-btn-saverecord', '__page__' => 1, 'normal' => 'first answer', 'second' => 'second answer']);
check(pages() === [1 => ['normal', 'second']], 'Single-page submission changed layout');
check(REDCap::$data['survey-record'][1] === ['normal' => 'first answer', 'second' => 'second answer', 'captcha' => 'answer'], 'Single-page submission lost answers/capture');
Project::$headers = ['normal' => 'Section 1', 'second' => 'Section 2'];

// Saving before answering any survey question still captures the CAPTCHA and
// returns to page one (the only saved field is the now-hidden CAPTCHA field).
solve();
submit($proof + ['submit-action' => 'submit-btn-savereturnlater', '__page__' => 1], ['__return' => 1]);
check(REDCap::$data['survey-record'][1] === ['captcha' => 'answer'], 'Empty Save & Return did not capture the CAPTCHA');
surveyRequest(['__code' => 'return-code']);
$GLOBALS['return_code'] = 'return-code';
$GLOBALS['lastSavedField'] = 'captcha';
$_GET += ['event_id' => 1, 'instance' => 1, 'page' => 'form_1'];
check(navigate() == 1, 'Capture alone resumed on the wrong page');
$GLOBALS['return_code'] = '';

// Capture remains optional; a capture API failure must restore pagination and
// consume the proof without interrupting the successful native survey save.
foreach ([false, true] as $capture) {
    $params = array_replace($custom, ['capture' => $capture]);
    solve($params);
    submit($post, [], $params);
    check(count(REDCap::$saves) === ($capture ? 1 : 0), 'Capture option ignored');
}
foreach (['throw', 'errors'] as $failure) {
    solve();
    REDCap::$failure = $failure;
    submit($post);
    check(pages() === $expectedPages && !isset(Project::$project_cache[355]->metadata['captcha']), 'Capture failure leaked full metadata');
    check(REDCap::$data['survey-record'][1]['normal'] === 'first answer' && !isset(SurveyFramework::$logs[$key]), 'Capture failure interrupted saving/proof consumption');
    check(isset(SurveyFramework::$logs['CAPTCHA response capture failed']), 'Capture failure was not logged');
    REDCap::$failure = null;
}
foreach (['form', 'event'] as $repeating) {
    Project::$repeating = $repeating;
    solve();
    $module = surveyRequest($post);
    $module->redcap_save_record(355, 'survey-record', 'form_1', 1, null, 'public-hash', 20, 3);
    $repeatForm = $repeating === 'form' ? 'form_1' : '';
    check(REDCap::$data['survey-record']['repeat_instances'][1][$repeatForm][3]['captcha'] === 'answer', 'Capture saved to wrong repeating instance');
}

echo 'Pagination and Save & Return regressions passed (' . (getenv('REDCAP_SURVEY_CLASS') ? 'native REDCap page methods' : 'in-memory page methods') . ").\n";
