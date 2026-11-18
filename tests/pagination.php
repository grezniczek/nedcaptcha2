<?php
// Set REDCAP_SURVEY_CLASS to also run these requests through a local REDCap's
// real page calculation/navigation methods. Database and data saves stay in memory.
define('PROJECT_ID', 355);
function db_escape($value) { return addslashes($value); }
function db_query($sql) {
    if (stripos($sql, 'SELECT s.project_id') !== false) return surveyContext($_GET['s']);
    if (stripos($sql, 'select participant_id') !== false) return ['participant_id' => 10];
    if (stripos($sql, 'select response_id') !== false) return ['response_id' => 20];
    if (stripos($sql, 'select m.field_name') !== false) return ['field_name' => $GLOBALS['lastSavedField']];
    throw new RuntimeException('Unexpected test query');
}
function db_num_rows($result) { return count($result) ? 1 : 0; }
function db_fetch_assoc($result) { return $result; }
function db_result($result, $row) { return reset($result); }
function pre_query($sql) { return "'survey-record'"; }
class DataEntry {
    public static function parseStopActions($codes) { return array_map('trim', explode(',', $codes)); }
    public static function replaceDotInCheckboxCodingReverse($code) { return str_replace('|', '.', $code); }
}
class Econsent {
    public static function econsentEnabledForSurvey($survey) { return false; }
}
class Records {
    public static function getDataTable($pid) { return 'redcap_data'; }
}
class RCView {
    public static function tt($key) { return $key; }
    public static function script($script, $delayed = false) { return '<script>' . $script . '</script>'; }
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
$GLOBALS['password_algo'] = 'sha256';
$GLOBALS['salt2'] = 'pagination-page-hash';
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

// A second arm has its own public hash, even though the framework's project
// helper returns the first arm. Exercise rendering and capture at that event.
$_SESSION = [];
$arm = ['s' => 'second-arm'];
$module = request($custom, [], $arm);
ob_start();
$module->redcap_survey_page(355, null, 'form_1', 2, null, 'second-arm', null);
check(strpos(ob_get_clean(), Module::CLIENT_KEY) !== false, 'Second arm did not render the challenge controls');
request($custom, $proof + ['captcha' => 'answer'], $arm);
$otherArm = request($custom, $proof + ['submit-action' => 'submit-btn-saverecord']);
check(property($otherArm, 'active') && $_POST === [], 'A different arm accepted the second arm’s proof');
$module = request($custom, $proof + ['submit-action' => 'submit-btn-saverecord'], $arm);
$module->redcap_save_record(355, 'arm-record', 'form_1', 2, null, 'second-arm', 21, 1);
check(REDCap::$data['arm-record'][2]['captcha'] === 'answer', 'Capture missing on second arm');
foreach (['private-hash', 'later-event'] as $hash) {
    $module = request($custom, [], ['s' => $hash]);
    check(!property($module, 'active'), 'Non-public link challenged');
}

function surveyRequest($post = [], $get = [], $params = null, $extra = []) {
    return request($params ?? $GLOBALS['custom'], $post, $get, $extra + ['second' => '', 'form_1_complete' => '']);
}
function pages() {
    [$pages] = Survey::getPageFields('form_1', $GLOBALS['Proj']->surveys[1]['question_by_section']);
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
function submit($post, $get = [], $params = null, $extra = []) {
    $module = surveyRequest($post, $get, $params, $extra);
    // Like REDCap, never enter a save path while CAPTCHA verification is pending.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['submit-action'])) return $module;
    $submittedPage = $_POST['__page__'] ?? 1;
    [$pages] = Survey::getPageFields('form_1', $GLOBALS['Proj']->surveys[1]['question_by_section']);
    // REDCap Surveys/index.php discards fields that belong to a different page
    // before DataEntry::saveRecord. Apply that boundary before simulating saving.
    foreach ($_POST as $field => $value) {
        if (isset($GLOBALS['Proj']->forms['form_1']['fields'][$field]) && $field !== 'record_id' && $field !== 'form_1_complete'
            && !in_array($field, $pages[$submittedPage] ?? [], true)) unset($_POST[$field]);
    }
    foreach ($_POST as $field => $value) {
        if (isset($GLOBALS['Proj']->forms['form_1']['fields'][$field])) REDCap::$data['survey-record'][1][$field] = $value;
        elseif (strpos($field, '__chk__') === 0) {
            list($field, $choice) = explode('_RC_', substr($field, 7), 2);
            if (isset($GLOBALS['Proj']->forms['form_1']['fields'][$field])) REDCap::$data['survey-record'][1][$field][$choice] = $value;
        }
    }
    $surveyProject = $GLOBALS['Proj'];
    $cachedProject = Project::$project_cache[355];
    $module->redcap_save_record(355, 'survey-record', 'form_1', 1, null, 'public-hash', 20, 1);
    check($GLOBALS['Proj'] === $surveyProject && Project::$project_cache[355] === $cachedProject, 'Capture replaced the survey project/cache');
    return $module;
}
function navigate() {
    [$pages, $count] = Survey::getPageFields('form_1', $GLOBALS['Proj']->surveys[1]['question_by_section']);
    Survey::setPageNum($pages, $count);
    return $_GET['__page__'];
}

// Discover the CAPTCHA and helpers on the actual first page, respecting whether
// section headers start new pages. Include fields before a later-section CAPTCHA.
foreach ([false, true] as $paginated) {
    Project::$questionBySection = $paginated;
    foreach ([false, true] as $captchaFirst) {
        $layout = ($paginated ? 'paginated' : 'single-page') . ($captchaFirst ? '/first-section' : '/later-section');
        $fieldOrder = $captchaFirst
            ? ['record_id', 'captcha', 'instructions', 'display', 'normal', 'second', 'form_1_complete']
            : ['record_id', 'normal', 'captcha', 'instructions', 'display', 'second', 'form_1_complete'];
        Project::$headers = $captchaFirst
            ? ['normal' => 'Questions', 'second' => 'More questions']
            : ['captcha' => 'Verification', 'instructions' => 'Instructions', 'display' => 'Display', 'second' => 'More questions'];
        $extra = ['instructions' => '@NEDCAPTCHA-INSTRUCTIONS', 'display' => '@NEDCAPTCHA-DISPLAY', 'second' => '', 'form_1_complete' => ''];
        $_SESSION = [];
        SurveyFramework::$logs = [];
        $module = request($custom, [], [], $extra, [], $fieldOrder);
        if ($paginated && !$captchaFirst) {
            check(!property($module, 'active'), "$layout: later-page tag must stay outside the supported scope");
            check(pages()[1] === ['normal'], "$layout: normal first page changed");
            check(!isset(SurveyFramework::$logs[$key]), "$layout: later-page tag issued a challenge");
            continue;
        }
        check(property($module, 'active'), "$layout: CAPTCHA was not discovered");
        check(strpos($GLOBALS['Proj']->metadata['captcha']['element_label'], 'Question') !== false, "$layout: challenge missing");
        check(isset($GLOBALS['Proj']->metadata['instructions'], $GLOBALS['Proj']->metadata['display']), "$layout: helper fields were not discovered");
        foreach (['normal', 'second'] as $field) {
            check(!isset($GLOBALS['Proj']->metadata[$field]), "$layout: $field leaked onto CAPTCHA page");
        }
        check(isset($GLOBALS['Proj']->metadata['form_1_complete']) === !$paginated, "$layout: form status did not follow the actual page count");

        $module = request($custom, $proof + ['captcha' => 'wrong', 'submit-action' => 'submit-btn-saverecord', 'normal' => 'forged'], [], $extra, [], $fieldOrder);
        check(property($module, 'active') && !isset($GLOBALS['Proj']->metadata['normal']), "$layout: incorrect answer bypassed the CAPTCHA");
        check($_POST === [] && $_SERVER['REQUEST_METHOD'] === 'GET', "$layout: unverified answers could be saved");
        request($custom, $proof + ['captcha' => 'answer'], [], $extra, [], $fieldOrder);
        check(SurveyFramework::$logs[$key]['passed'], "$layout: correct answer did not solve the CAPTCHA");
        foreach (['captcha', 'instructions', 'display'] as $field) {
            check(!isset($GLOBALS['Proj']->metadata[$field]), "$layout: $field remained after solving");
        }
        $expected = $paginated ? [1 => ['normal'], 2 => ['second']] : [1 => ['normal', 'second']];
        check(pages() === $expected, "$layout: solved survey layout does not match pagination setting");
        $post = $proof + ['submit-action' => 'submit-btn-saverecord', '__page__' => 1, 'normal' => 'answer'];
        request($custom, $post, [], $extra, [], $fieldOrder);
        check($_POST === $post && pages() === $expected, "$layout: verified submission or layout changed");
    }
}
Project::$questionBySection = true;

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
Project::$questionBySection = false;
Project::$headers = [];
solve();
submit($proof + ['submit-action' => 'submit-btn-saverecord', '__page__' => 1, 'normal' => 'first answer', 'second' => 'second answer']);
check(pages() === [1 => ['normal', 'second']], 'Single-page submission changed layout');
check(REDCap::$data['survey-record'][1] === ['normal' => 'first answer', 'second' => 'second answer', 'captcha' => 'answer'], 'Single-page submission lost answers/capture');
Project::$questionBySection = true;
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

// First submissions survive expiry/session loss, without saving anything before
// re-verification. Exercise both normal submission and Save & Return Later.
Project::$repeating = '';
Project::$fieldTypes = ['choices' => 'checkbox'];
$extra = ['notes' => '', 'zero' => '', 'choices' => '', 'required' => ''];
$freshGuid = '12345678-1234-4234-8234-123456789abd';
$freshKey = Module::STORE_KEY . 'public-hash_' . $freshGuid;
$pendingKey = Module::PENDING_SUBMISSION_SESSION_KEY;
$recoveries = 0;
foreach ([false, true] as $paginated) {
    Project::$questionBySection = $paginated;
    Project::$headers = ['normal' => 'Questions', 'second' => 'More questions'];
    foreach (['expired', 'lost-session', 'missing-log'] as $loss) {
        foreach (['submit-btn-saverecord', 'submit-btn-savereturnlater'] as $action) {
            foreach ([false, true] as $capture) {
                $case = ($paginated ? 'paginated' : 'single-page') . "/$loss/$action/capture=" . (int)$capture;
                $params = array_replace($custom, ['capture' => $capture]);
                $_SESSION = [];
                SurveyFramework::$logs = [];
                REDCap::$data = REDCap::$saves = [];
                surveyRequest([], [], $params, $extra);
                surveyRequest($proof + ['captcha' => 'answer'], [], $params, $extra);
                if ($loss === 'expired') $_SESSION[Module::CHALLENGE_SESSION_KEY][$key] = time() - 7201;
                elseif ($loss === 'lost-session') $_SESSION = [];
                else unset(SurveyFramework::$logs[$key]);
                $expectedKey = $loss === 'missing-log' ? $key : $freshKey;
                $newProof = [Module::CLIENT_KEY => $loss === 'missing-log' ? Crypto::getGuid() : $freshGuid];
                if ($loss !== 'missing-log') Crypto::$nextGuids = [$freshGuid];
                $unsafe = '</script><script>window.recovered=true</script>&"';
                $answers = ['normal' => $unsafe, 'notes' => "First line\nSecond line: ü", 'zero' => '0', 'required' => '',
                    '__chk__choices_RC_1' => '1', '__chk__choices_RC_2' => ''];
                $controls = ['submit-action' => $action, '__page__' => '1', '__page_hash__' => Survey::getPageNumHash(1),
                    '__start_time__' => '2026-11-16 10:00:00', '__start_time_hash__' => 'signed-start',
                    '__file_upload_context__' => 'native-upload-ticket', 'file_upload_delete_reason' => 'Original reason',
                    'empty-required-field' => ['required', 'record_id', 'captcha', 'unrelated', ['invalid']]];
                $forged = ['record_id' => 'someone-else', 'form_1_complete' => '2', 'captcha' => 'tampered',
                    '__GROUPID__' => '3', '__response_id__' => '999', '__code' => 'forged', '__prefill' => '1',
                    'redcap_csrf_token' => 'old-token', 'unrelated' => 'forged', '__chk__choices' => 'malformed'];
                $original = $proof + $answers + $controls + $forged;
                if ($paginated) $original['second'] = 'later-page injection';
                else $original['second'] = 'second section answer';
                $module = submit($original, ['__return' => 1, '__add_participant' => 'forged@example.org', 'id' => 'someone-else'], $params, $extra);
                check(property($module, 'active') && $_POST === [] && $_SERVER['REQUEST_METHOD'] === 'GET', "$case: stale submission reached saving");
                check(REDCap::$data === [] && REDCap::$saves === [], "$case: answers were saved before verification");
                $pending = $_SESSION[$pendingKey][$expectedKey]['post'] ?? null;
                check($pending !== null && array_intersect_key($pending, $answers) === $answers, "$case: submitted answers were not buffered exactly");
                check($pending['submit-action'] === $action && $pending['empty-required-field'] === ['required'], "$case: submit action/required-field context lost");
                foreach (array_keys($forged) as $name) check(!array_key_exists($name, $pending), "$case: unsafe control was buffered: $name");
                check(isset($pending['second']) === !$paginated, "$case: buffering ignored the first-page boundary");
                $scripts = implode("\n", array_merge(property($module, 'scripts_top'), property($module, 'scripts_regular'), property($module, 'scripts_delayed')));
                check(strpos($scripts, $unsafe) === false && strpos(serialize(SurveyFramework::$logs), $unsafe) === false, "$case: answers leaked into script output/module logs");
                check(strpos($scripts, 'Your answers have been kept temporarily') !== false, "$case: recovery notice missing");

                // CAPTCHA retries cannot replace the held answers or the original action.
                submit($newProof + ['captcha' => 'wrong', 'normal' => 'replacement', 'submit-action' => 'submit-btn-saverecord'], [], $params, $extra);
                check($_POST === [] && REDCap::$data === [], "$case: wrong answer saved pending data");
                check($_SESSION[$pendingKey][$expectedKey]['post'] === $pending, "$case: retry changed pending answers");
                // A different browser cannot retrieve/submit another browser's pending data.
                $browserSession = $_SESSION;
                $_SESSION = [];
                Crypto::$nextGuids = ['12345678-1234-4234-8234-123456789abe'];
                submit($newProof + ['captcha' => 'answer'], [], $params, $extra);
                check($_POST === [] && REDCap::$data === [] && empty($_SESSION[$pendingKey]), "$case: another browser recovered pending answers");
                $_SESSION = $browserSession;

                $module = submit($newProof + ['captcha' => 'answer', 'normal' => 'replacement', 'submit-action' => 'submit-btn-saverecord'], [], $params, $extra);
                check(!property($module, 'active') && $_SERVER['REQUEST_METHOD'] === 'POST', "$case: solved recovery did not resume native saving");
                check($_POST['submit-action'] === $action && $_POST['__page__'] === 1 && $_POST['__page_hash__'] === Survey::getPageNumHash(1), "$case: native submission controls changed");
                check(isset($_GET['__return']) === ($action === 'submit-btn-savereturnlater'), "$case: Save & Return intent changed");
                check(!isset($_GET['__add_participant']) && !isset($_GET['id']), "$case: unsafe query controls were restored");
                foreach ($answers as $name => $value) check($_POST[$name] === $value, "$case: answer changed during recovery: $name");
                foreach (['__start_time__', '__start_time_hash__', '__file_upload_context__', 'file_upload_delete_reason'] as $name) {
                    check($_POST[$name] === $controls[$name], "$case: native context lost: $name");
                }
                foreach (array_keys($forged) as $name) check(!array_key_exists($name, $_POST), "$case: unsafe control was replayed: $name");
                $data = REDCap::$data['survey-record'][1];
                check($data['normal'] === $unsafe && $data['notes'] === $answers['notes'] && $data['zero'] === '0', "$case: native save lost text answers");
                check($data['choices'] === ['1' => '1', '2' => ''], "$case: native checkbox controls lost");
                check(isset($data['captcha']) === $capture, "$case: capture option changed during recovery");
                if ($capture) check($data['captcha'] === 'answer', "$case: capture used the stale/tampered answer");
                check(!isset($_SESSION[$pendingKey][$expectedKey]) && !isset(SurveyFramework::$logs[$expectedKey]), "$case: saved buffer/proof were not consumed");
                check(!isset($_SESSION[Module::CHALLENGE_SESSION_KEY][$expectedKey]), "$case: saved proof ownership remained");
                check(pages() === ($paginated ? [1 => ['normal', 'notes', 'zero', 'choices', 'required'], 2 => ['second']] : [1 => ['normal', 'notes', 'zero', 'choices', 'required', 'second']]), "$case: recovered page layout changed");
                if ($action === 'submit-btn-saverecord' && $paginated) check(navigate() == 2, "$case: recovered Next skipped a page");
                if ($action === 'submit-btn-savereturnlater') {
                    surveyRequest(['__code' => 'return-code'], [], $params, $extra);
                    $GLOBALS['return_code'] = 'return-code';
                    $GLOBALS['lastSavedField'] = 'normal';
                    $_GET += ['event_id' => 1, 'instance' => 1, 'page' => 'form_1'];
                    check(navigate() == 1, "$case: Save & Return resumed on the wrong page");
                    $GLOBALS['return_code'] = '';
                }
                $saved = REDCap::$data;
                submit($newProof + ['captcha' => 'answer'], [], $params, $extra);
                check($_POST === [] && REDCap::$data === $saved, "$case: consumed recovery was replayed");
                $recoveries++;
            }
        }
    }
}

// Forged page/control values must not turn a challenge POST into a buffered save.
$recoverablePost = $proof + ['normal' => 'held answer', 'submit-action' => 'submit-btn-saverecord', '__page__' => 1, '__page_hash__' => Survey::getPageNumHash(1)];
foreach ([['__page_hash__' => 'forged'], ['__page_hash__' => []], ['__page__' => 2, '__page_hash__' => Survey::getPageNumHash(2)],
    ['submit-action' => 'submit-btn-deleteform'], ['submit-action' => []]] as $invalid) {
    $_SESSION = [];
    SurveyFramework::$logs = [];
    REDCap::$data = REDCap::$saves = [];
    submit(array_replace($recoverablePost, $invalid));
    check($_POST === [] && empty($_SESSION[$pendingKey]) && REDCap::$data === [], 'Invalid submission controls created a pending save');
}
Project::$saveAndReturn = false;
$_SESSION = [];
submit(array_replace($recoverablePost, ['submit-action' => 'submit-btn-savereturnlater']));
check(empty($_SESSION[$pendingKey]), 'Disabled Save & Return action was buffered');
Project::$saveAndReturn = true;

// Two tabs retain independent answers. A renewed proof can rotate again while
// its pending answers are still fresh, and consuming it leaves the other tab alone.
$_SESSION = [];
SurveyFramework::$logs = [];
REDCap::$data = REDCap::$saves = [];
Crypto::$nextGuids = [$freshGuid];
submit($recoverablePost);
$held = $_SESSION[$pendingKey][$freshKey];
$otherGuid = '12345678-1234-4234-8234-123456789abe';
$otherKey = Module::STORE_KEY . 'public-hash_' . $otherGuid;
Crypto::$nextGuids = [$otherGuid];
submit(array_replace($recoverablePost, ['normal' => 'other tab answer']));
check($_SESSION[$pendingKey][$freshKey] === $held && $_SESSION[$pendingKey][$otherKey]['post']['normal'] === 'other tab answer', 'A second tab overwrote held answers');
$_SESSION[Module::CHALLENGE_SESSION_KEY][$freshKey] = time() - 7201;
$rotatedGuid = '12345678-1234-4234-8234-123456789abf';
$rotatedKey = Module::STORE_KEY . 'public-hash_' . $rotatedGuid;
Crypto::$nextGuids = [$rotatedGuid];
submit([Module::CLIENT_KEY => $freshGuid, 'captcha' => 'wrong']);
check(!isset($_SESSION[$pendingKey][$freshKey]) && $_SESSION[$pendingKey][$rotatedKey] === $held, 'Proof rotation dropped pending answers');
submit([Module::CLIENT_KEY => $rotatedGuid, 'captcha' => 'answer']);
check(REDCap::$data['survey-record'][1]['normal'] === 'held answer' && !isset($_SESSION[$pendingKey][$rotatedKey]), 'Rotated recovery did not save/consume the original answers');
check($_SESSION[$pendingKey][$otherKey]['post']['normal'] === 'other tab answer', 'Saving one tab consumed another tab\'s answers');

// Buffers have the same two-hour retention bound as CAPTCHA proof.
$_SESSION[$pendingKey][$otherKey]['created_at'] = time() - 7201;
surveyRequest();
check(!isset($_SESSION[$pendingKey][$otherKey]), 'Expired pending answers were retained');

// REDCap submits its hidden submit-action control when a Stop Action is confirmed.
foreach ([false, true] as $discard) {
    foreach (['radio', 'checkbox'] as $type) {
        $_SESSION = SurveyFramework::$logs = [];
        Project::$discardOnStop = $discard;
        Project::$stopActions = ['normal' => '0, 2.5'];
        Project::$fieldTypes = ['normal' => $type];
        $answers = $type === 'radio' ? ['normal' => '0'] : ['__chk__normal_RC_2|5' => '2.5'];
        $post = $answers + ['submit-action' => 'submit-action'] + $recoverablePost;
        Crypto::$nextGuids = [$freshGuid];
        surveyRequest($post, ['__stopaction' => 1, '__endsurvey' => 1]);
        check($_POST === [] && !isset($_GET['__stopaction']) && !isset($_GET['__endsurvey']), 'Stop routing reached the challenge');
        surveyRequest([Module::CLIENT_KEY => $freshGuid, 'captcha' => 'wrong']);
        check($_POST === [], 'Wrong answer resumed Stop Action');
        $module = surveyRequest([Module::CLIENT_KEY => $freshGuid, 'captcha' => 'answer']);
        check(isset($_GET['__stopaction'], $_GET['__endsurvey']) && array_intersect_key($_POST, $answers) === $answers, 'Confirmed Stop Action lost');
        check(isset(SurveyFramework::$logs[$freshKey]) !== $discard, 'Discarded response waited for a save hook');
        if (!$discard) $module->redcap_save_record(355, 'stop-record', 'form_1', 1, null, 'public-hash', 20, 1);
        check(!isset($_SESSION[$pendingKey][$freshKey]), 'Finished Stop Action retained held answers');
    }
}
Project::$discardOnStop = false;
Project::$stopActions = ['normal' => 'stop'];
Project::$fieldTypes = ['normal' => 'radio', 'file' => 'file'];
$_SESSION = SurveyFramework::$logs = [];
Crypto::$nextGuids = [$freshGuid];
surveyRequest(['submit-action' => 'submit-action', 'normal' => 'continue'] + $recoverablePost, ['__stopaction' => 1, '__endsurvey' => 1]);
check(empty($_SESSION[$pendingKey]), 'Unmatched choice registered a Stop Action');
foreach (['stop', 'continue'] as $replacementChoice) {
    $_SESSION = SurveyFramework::$logs = [];
    Crypto::$nextGuids = [$freshGuid];
    surveyRequest(['normal' => 'stop', 'file' => '1234'] + $recoverablePost, ['__endsurvey' => 1, '__stopaction' => 1], null, ['file' => '']);
    surveyRequest([Module::CLIENT_KEY => $freshGuid, 'captcha' => 'answer'], [], null, ['file' => '']);
    check(isset($_POST['__prefill']) && !isset($_GET['__stopaction']), 'Re-upload prefill prematurely ended survey');
    surveyRequest([Module::CLIENT_KEY => $freshGuid, 'normal' => $replacementChoice, 'file' => '5678'] + $recoverablePost, [], null, ['file' => '']);
    check(isset($_GET['__endsurvey'], $_GET['__stopaction']) === ($replacementChoice === 'stop') && $_POST['file'] === '5678', 'Re-upload did not respect the final selected choice');
}
Project::$stopActions = Project::$fieldTypes = [];

// Recovery also works for generated math and for a native validation redisplay
// that has not yet called the save hook. Re-verify before the first resumed POST.
$_SESSION = [];
SurveyFramework::$logs = [];
REDCap::$data = REDCap::$saves = [];
$math = ['type' => 'math', 'showAsText' => true, 'capture' => true];
Crypto::$nextGuids = [$freshGuid];
submit($recoverablePost, [], $math);
$mathAnswer = SurveyFramework::$logs[$freshKey]['expected'];
$module = surveyRequest([Module::CLIENT_KEY => $freshGuid, 'captcha' => $mathAnswer], [], $math);
check($_POST['normal'] === 'held answer' && $_SERVER['REQUEST_METHOD'] === 'POST', 'Math recovery did not restore the held submission');
check(isset($_SESSION[$pendingKey][$freshKey]) && REDCap::$data === [], 'Unsaved recovery consumed pending answers');
foreach ([['s' => 'private-hash'], ['__return' => 1]] as $outside) {
    surveyRequest([Module::CLIENT_KEY => $freshGuid], $outside, $math);
    check(!isset($_POST['normal']) && isset($_SESSION[$pendingKey][$freshKey]), 'An unrelated survey flow restored pending answers');
}
surveyRequest([Module::CLIENT_KEY => $freshGuid, '__response_hash__' => $responseHash], [], $math);
check(!isset($_POST['normal']) && isset($_SESSION[$pendingKey][$freshKey]), 'An existing response inherited a pending new submission');
submit([Module::CLIENT_KEY => $freshGuid], [], $math);
check(REDCap::$data['survey-record'][1]['normal'] === 'held answer' && REDCap::$data['survey-record'][1]['captcha'] === $mathAnswer, 'Verified recovery retry lost its answers/capture');
check(!isset($_SESSION[$pendingKey][$freshKey]), 'Verified retry did not consume the buffer');
foreach (['errors', 'throw'] as $failure) {
    $_SESSION = [];
    SurveyFramework::$logs = [];
    REDCap::$data = REDCap::$saves = [];
    Crypto::$nextGuids = [$freshGuid];
    submit($recoverablePost, [], $math);
    $mathAnswer = SurveyFramework::$logs[$freshKey]['expected'];
    REDCap::$failure = $failure;
    submit([Module::CLIENT_KEY => $freshGuid, 'captcha' => $mathAnswer], [], $math);
    check(REDCap::$data['survey-record'][1]['normal'] === 'held answer', 'Capture failure lost recovered survey answers');
    check(!isset($_SESSION[$pendingKey][$freshKey]) && !isset(SurveyFramework::$logs[$freshKey]), 'Capture failure retained a saved buffer/proof');
    check(isset(SurveyFramework::$logs['CAPTCHA response capture failed']), 'Recovery capture failure was not logged');
    REDCap::$failure = null;
}
Project::$fieldTypes = [];

// An expired CAPTCHA may retain native upload ownership, but a lost PHP session
// must return the filled form for re-upload instead of silently saving no file.
Project::$fieldTypes = ['file' => 'file', 'choices' => 'checkbox'];
$uploadRecoveries = 0;
foreach ([false, true] as $paginated) {
    Project::$questionBySection = $paginated;
    foreach ([true, false] as $uploadOwned) {
        foreach (['submit-btn-saverecord', 'submit-btn-savereturnlater'] as $action) {
            $_SESSION = [];
            SurveyFramework::$logs = [];
            REDCap::$data = REDCap::$saves = [];
            if ($uploadOwned) $_SESSION['native_test_uploads']['original-ticket'] = true;
            Crypto::$nextGuids = [$freshGuid];
            $extra = ['file' => '', 'choices' => '', 'note' => '', 'zero' => ''];
            $post = array_replace($recoverablePost, ['submit-action' => $action, 'file' => '1234',
                '__file_upload_context__' => 'original-ticket', '__chk__choices_RC_1' => '1', '__chk__choices_RC_2' => '',
                'note' => "<b>literal</b>\nUnicode: ü", 'zero' => '0']);
            submit($post, [], null, $extra);
            $module = surveyRequest([Module::CLIENT_KEY => $freshGuid, 'captcha' => 'answer'], [], null, $extra);
            $uploadRecoveries++;
            if ($uploadOwned) {
                check($_POST['file'] === '1234' && $_POST['submit-action'] === $action && !isset($_POST['__prefill']), 'Owned upload recovery changed');
                continue;
            }
            check(!isset($_POST['submit-action']) && ($_POST['__prefill'] ?? null) === '1', 'Lost upload ownership must not auto-save');
            check(!isset($_POST['file']) && !isset($_POST['__file_upload_context__']), 'Recovery reused an unowned upload');
            check($_POST['normal'] === 'held answer' && $_POST['zero'] === '0' && $_POST['note'] === $post['note'], 'Re-upload recovery lost other answers');
            check(($_POST['choices___1'] ?? null) === '1' && !isset($_POST['choices___2']), 'Native checkbox prefill was incorrect');
            check(REDCap::$data === [] && !isset($_SESSION[$pendingKey][$freshKey]), 'Re-upload form saved data or retained stale submission');
            check(isset($_SESSION[Module::CHALLENGE_SESSION_KEY][$freshKey]), 'Re-upload form consumed the verified proof');
            ob_start();
            $module->redcap_survey_page(355, 'unsaved-record', 'form_1', 1, null, 'public-hash', null, 1);
            $html = ob_get_clean();
            check(strpos($html, 'upload the highlighted files again') !== false && strpos($html, $freshGuid) !== false, 'Re-upload notice/proof missing on the native prefill page');
            check(strpos($html, $post['note']) === false, 'Prefilled answers leaked into JavaScript');
            $_SESSION['native_test_uploads']['new-ticket'] = true;
            submit([Module::CLIENT_KEY => $freshGuid, 'submit-action' => $action, '__page__' => 1,
                'normal' => 'held answer', 'file' => '5678', '__file_upload_context__' => 'new-ticket'], [], null, $extra);
            check(REDCap::$data['survey-record'][1]['file'] === '5678' && REDCap::$data['survey-record'][1]['captcha'] === 'answer', 'Re-uploaded file/verified answer did not save');
        }
    }
}
Project::$fieldTypes = [];

echo 'Pagination, Save & Return, ' . $recoveries . ' submission-recovery cases, and ' . $uploadRecoveries . ' upload-recovery cases passed (' . (getenv('REDCAP_SURVEY_CLASS') ? 'native REDCap page methods' : 'in-memory page methods') . ").\n";
