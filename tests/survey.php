<?php
// Exercise the real survey hook with in-memory REDCap/project/log doubles.
namespace ExternalModules {
    class AbstractExternalModule {
        public $framework;
        public function __construct() { $this->framework = new \SurveyFramework(); }
        public function getProjectSetting($key) { return $this->framework->settings[$key] ?? false; }
        public function queryLogs($sql, $key) { return $this->framework->queryLogs($sql, $key); }
        public function getPublicSurveyHash($projectId) { return $this->framework->getPublicSurveyHash($projectId); }
    }
}
namespace {
    define('PAGE', 'surveys/index.php');

    class SurveyFramework {
        public $settings = [];
        public static $logs = [];
        public function getPublicSurveyHash($projectId) { return 'public-hash'; }
        public function getJavascriptModuleObjectName() { return 'surveyModule'; }
        public function escape($value) { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
        public function queryLogs($sql, $key) { return new SurveyResult(self::$logs[$key] ?? null); }
        public function removeLogs($sql, $key) { unset(self::$logs[$key]); }
        public function log($key, $data) { self::$logs[$key] = $data; }
    }
    class SurveyResult {
        private $row;
        public function __construct($row) { $this->row = $row; }
        public function fetch_assoc() { return $this->row; }
    }
    class Project {
        public static $annotations = [];
        public static $saveAndReturn = false;
        public $metadata = [];
        public $forms;
        public $surveys;
        public $table_pk = 'record_id';
        public function __construct($projectId) {
            foreach (self::$annotations as $field => $annotation) {
                $this->metadata[$field] = ['misc' => $annotation, 'element_label' => 'Label'];
            }
            $this->forms = ['form_1' => ['fields' => array_fill_keys(array_keys($this->metadata), true)]];
            $this->surveys = [1 => ['save_and_return' => self::$saveAndReturn]];
        }
        public function isDraftMode() { return false; }
    }
    class Survey {
        public static function getSurveyContextFromSurveyHash($hash) { return ['form_name' => 'form_1', 'survey_id' => 1]; }
        public static function getParticipantIdFromHash($hash) { return $hash === 'public-hash' ? 10 : 11; }
        public static function getPageFields($instrument, $all) { return [[1 => array_keys(Project::$annotations)], 1]; }
        public static function decryptResponseHash($hash, $participantId) { return $hash === 'valid-response' && $participantId === 10 ? 20 : ''; }
    }
    class Crypto {
        public static function getGuid() { return '12345678-1234-4234-8234-123456789abc'; }
    }

    require_once __DIR__ . '/../NEDCaptcha2ExternalModule.php';
    use DE\RUB\SEG\NEDCaptcha2ExternalModule\NEDCaptcha2ExternalModule;
    use DE\RUB\SEG\NEDCaptcha2ExternalModule\ActionTagHelper;

    function check($condition, $message) {
        if (!$condition) throw new \RuntimeException($message);
    }
    function property($module, $name) {
        return (new \ReflectionProperty($module, $name))->getValue($module);
    }
    function request($params, $post = [], $get = [], $extraAnnotations = [], $settings = []) {
        Project::$annotations = ['record_id' => '', 'captcha' => '@NEDCAPTCHA=' . json_encode($params), 'normal' => ''] + $extraAnnotations;
        ActionTagHelper::$cache = [];
        $_POST = $post;
        $_GET = $get + ['s' => 'public-hash'];
        $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
        $GLOBALS['participant_id'] = 10;
        $GLOBALS['Proj'] = new Project(355);
        $module = new NEDCaptcha2ExternalModule();
        $module->framework->settings = $settings;
        $module->redcap_every_page_before_render(355);
        return $module;
    }

    function seedChallenge($expected) {
        $key = NEDCaptcha2ExternalModule::STORE_KEY . 'public-hash_' . Crypto::getGuid();
        SurveyFramework::$logs[$key] = ['expected' => $expected, 'passed' => false];
        $_SESSION[NEDCaptcha2ExternalModule::CHALLENGE_SESSION_KEY][$key] = time();
    }

    // Optional helper fields must not be required for a simple custom/password CAPTCHA.
    set_error_handler(function($severity, $message) { throw new \ErrorException($message, 0, $severity); });
    $custom = ['type' => 'custom', 'custom' => [['challenge' => 'Question', 'response' => 'answer']]];
    $module = request($custom);
    check(property($module, 'active'), 'Custom CAPTCHA should be displayed without helper fields');
    check(strpos($GLOBALS['Proj']->metadata['captcha']['element_label'], 'Question') !== false, 'Custom challenge missing');
    restore_error_handler();

    $key = NEDCaptcha2ExternalModule::STORE_KEY . 'public-hash_' . Crypto::getGuid();
    foreach ([['0123', '123', false], ['0123', '0123', true], ['0e123', '0', false], ['0e123', '0e123', true], ['Secret', 'secret', false], ['', '', false]] as [$expected, $answer, $passes]) {
        seedChallenge($expected);
        request(['type' => 'custom', 'custom' => [['challenge' => 'Key', 'response' => $expected]]], [NEDCaptcha2ExternalModule::CLIENT_KEY => Crypto::getGuid(), 'captcha' => $answer]);
        check(SurveyFramework::$logs[$key]['passed'] === $passes, 'Custom answers must match literally');
    }
    seedChallenge('secret');
    request(['type' => 'custom', 'caseInsensitive' => true, 'custom' => [['challenge' => 'Key', 'response' => 'Secret']]], [NEDCaptcha2ExternalModule::CLIENT_KEY => Crypto::getGuid(), 'captcha' => 'SECRET']);
    check(SurveyFramework::$logs[$key]['passed'] === true, 'Case-insensitive comparison was lost');

    foreach (['A "quoted" key', 'a\\b', 'Ünicode', '0'] as $challenge) {
        SurveyFramework::$logs = [];
        request(['type' => 'custom', 'custom' => [['challenge' => $challenge, 'response' => 'answer']]], [], [], ['embedded' => '@NEDCAPTCHA-CUSTOM-EMBED=' . json_encode($challenge)]);
        check(isset($GLOBALS['Proj']->metadata['embedded']), 'Escaped custom embedding key did not match');
    }

    SurveyFramework::$logs = [];
    $unsafe = '\'"></script><script>window.audit=true</script>&';
    $module = request($custom, [NEDCaptcha2ExternalModule::CLIENT_KEY => $unsafe]);
    check(strpos(implode("\n", property($module, 'scripts_delayed')), $unsafe) === false, 'Unsafe client key reached survey JavaScript');
    $module = request(['type' => 'custom', 'custom' => [['challenge' => $unsafe, 'response' => 'answer']]]);
    check(strpos($GLOBALS['Proj']->metadata['captcha']['element_label'], '<script>') === false, 'Custom challenge injected survey markup');
    seedChallenge($unsafe);
    $module = request(['type' => 'custom', 'capture' => true, 'custom' => [['challenge' => 'Key', 'response' => $unsafe]]], [NEDCaptcha2ExternalModule::CLIENT_KEY => Crypto::getGuid(), 'captcha' => $unsafe]);
    $captureScript = implode("\n", property($module, 'scripts_regular'));
    check(strpos($captureScript, '</script>') === false && strpos($captureScript, '\\u003C') !== false, 'Captured response is unsafe inside a script');
    check(strpos($captureScript, ').val(') !== false, 'Captured response should be set as a DOM value');

    if (!function_exists('imagecreate')) {
        SurveyFramework::$logs = [];
        $module = request(['type' => 'image']);
        check(property($module, 'active'), 'Rendering failure must still present a usable CAPTCHA');
        check(SurveyFramework::$logs[$key]['type'] === 'math', 'Effective fallback type was not stored');
        check(preg_match('/\d+ \+ \d+/', $GLOBALS['Proj']->metadata['captcha']['element_label']) === 1, 'Text fallback challenge missing');
        $answer = SurveyFramework::$logs[$key]['expected'];
        $module = request(['type' => 'image'], [NEDCaptcha2ExternalModule::CLIENT_KEY => Crypto::getGuid(), 'captcha' => $answer]);
        check(isset($GLOBALS['Proj']->metadata['normal']), 'Answering the fallback did not unlock the survey');
        check($_SERVER['REQUEST_METHOD'] === 'GET', 'Fallback response must not be saved as a survey record');
    }

    // Request-controlled flags must not bypass the CAPTCHA or leave a savable POST.
    foreach ([
        [['submit-action' => 'submit-btn-saverecord', 'normal' => 'forged'], []],
        [['submit-action' => 'submit-btn-saverecord', '__start_time__' => '1'], []],
        [['submit-action' => 'submit-btn-saverecord', '__page__' => 2, '__page_hash__' => 'forged'], []],
        [['submit-action' => 'submit-btn-saverecord', '__code' => 'forged'], ['__return' => '1']],
        [['submit-action' => 'submit-btn-saverecord'], ['__passthru' => '']],
        [['submit-action' => 'submit-btn-saverecord'], ['__passthru' => '0']],
        [['submit-action' => 'submit-btn-saverecord'], ['__rh' => 'valid-response']],
        [['submit-action' => 'submit-btn-saverecord', '__response_hash__' => 'forged'], []],
        [[], ['__return' => '1', '__add_participant' => 'test@example.org']],
    ] as [$post, $get]) {
        $_SESSION = [];
        SurveyFramework::$logs = [];
        $module = request($custom, $post, $get);
        check(property($module, 'active') && !isset($GLOBALS['Proj']->metadata['normal']), 'Forged request bypassed CAPTCHA');
        check($_POST === [] && $_SERVER['REQUEST_METHOD'] === 'GET', 'Unverified POST could reach record saving');
        check(!isset($_GET['__add_participant']), 'Unverified request could issue a private participant link');
    }

    // Solving only reveals the form; its subsequent real submission consumes the proof.
    $_SESSION = [];
    SurveyFramework::$logs = [];
    request($custom);
    $module = request($custom, [NEDCaptcha2ExternalModule::CLIENT_KEY => Crypto::getGuid(), 'captcha' => 'answer', 'submit-action' => 'submit-btn-saverecord', 'normal' => 'injected']);
    check($_POST === [] && $_SERVER['REQUEST_METHOD'] === 'GET', 'Solving request also submitted survey data');
    check(isset($GLOBALS['Proj']->metadata['normal']), 'Solved CAPTCHA did not reveal the survey');
    check(strpos(implode("\n", property($module, 'scripts_regular')), NEDCaptcha2ExternalModule::CLIENT_KEY) !== false, 'First submission proof missing from form');
    $post = [NEDCaptcha2ExternalModule::CLIENT_KEY => Crypto::getGuid(), 'submit-action' => 'submit-btn-saverecord', 'normal' => 'real answer'];
    $module = request($custom, $post);
    check($_POST === $post && isset($GLOBALS['Proj']->metadata['normal']), 'Verified first submission was blocked');
    $module->redcap_save_record(355, '1', 'form_1', 1, null, null, null, 1);
    check(isset(SurveyFramework::$logs[$key]), 'Unrelated data entry save consumed survey proof');
    $module->redcap_save_record(355, '1', 'form_1', 1, null, 'public-hash', 20, 1);
    check(!isset(SurveyFramework::$logs[$key]) && !isset($_SESSION[NEDCaptcha2ExternalModule::CHALLENGE_SESSION_KEY][$key]), 'Saved proof was not consumed');
    $module = request($custom, $post);
    check(property($module, 'active') && $_POST === [], 'Consumed proof was replayed');

    // A solved key from another browser and expired keys cannot authorize a new record.
    foreach ([[], [NEDCaptcha2ExternalModule::CHALLENGE_SESSION_KEY => [$key => time() - 7201]]] as $session) {
        $_SESSION = $session;
        SurveyFramework::$logs[$key] = ['expected' => 'answer', 'passed' => true];
        $module = request($custom, $post);
        check(property($module, 'active') && $_POST === [], 'Unowned/expired proof was accepted');
    }

    // Later pages and end pages require a valid REDCap response hash.
    $_SESSION = [];
    $post = ['submit-action' => 'submit-btn-saverecord', '__response_hash__' => 'valid-response', '__page__' => 2];
    request($custom, $post);
    check($_POST === $post && isset($GLOBALS['Proj']->metadata['normal']), 'Verified response continuation was blocked');
    $module = request($custom, [], ['__endpublicsurvey' => '1', '__rh' => 'valid-response']);
    check(!property($module, 'active'), 'Verified acknowledgement page was blocked');
    // REDCap handles these dedicated return requests, which contain no survey submission.
    Project::$saveAndReturn = true;
    $post = ['__code' => 'return-code'];
    request($custom, $post);
    check($_POST === $post, 'Dedicated return-code request was intercepted');
    $module = request($custom, $post + ['submit-action' => 'submit-btn-saverecord']);
    check($_POST === [] && property($module, 'active'), 'Return-code flag allowed an unverified submission');
    $module = request($custom, $post + ['__prefill' => '1']);
    check($_POST === [] && property($module, 'active'), 'Return-code flag allowed unverified prefill');
    Project::$saveAndReturn = false;

    // Explicitly enabled solved sessions still authorize a new entry in the same browser.
    $_SESSION = [];
    SurveyFramework::$logs = [];
    $token = str_repeat('a', 64);
    $_COOKIE[NEDCaptcha2ExternalModule::SESSION_COOKIE_PREFIX . '355'] = $token;
    $sessionKey = NEDCaptcha2ExternalModule::SESSION_STORE_KEY . '355_' . $token;
    SurveyFramework::$logs[$sessionKey] = ['project_id' => 355, 'publicSurveyHash' => 'public-hash', 'createdAt' => time(), 'lastSeen' => time(), 'expiresAt' => time() + 600];
    $module = request($custom, [], [], [], ['allow-sessions' => true]);
    check(!property($module, 'active') && isset($GLOBALS['Proj']->metadata['normal']), 'Enabled solved session was blocked');
    $module = request($custom);
    check(property($module, 'active'), 'Disabled solved sessions still bypassed CAPTCHA');
    $_COOKIE = [];

    echo "Survey hook regressions passed.\n";
}
