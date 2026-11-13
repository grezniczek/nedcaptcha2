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
        public $metadata = [];
        public $forms;
        public $table_pk = 'record_id';
        public function __construct($projectId) {
            foreach (self::$annotations as $field => $annotation) {
                $this->metadata[$field] = ['misc' => $annotation, 'element_label' => 'Label'];
            }
            $this->forms = ['form_1' => ['fields' => array_fill_keys(array_keys($this->metadata), true)]];
        }
        public function isDraftMode() { return false; }
    }
    class Survey {
        public static function getSurveyContextFromSurveyHash($hash) { return ['form_name' => 'form_1']; }
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
    function request($params, $post = [], $get = []) {
        Project::$annotations = ['record_id' => '', 'captcha' => '@NEDCAPTCHA=' . json_encode($params), 'normal' => ''];
        ActionTagHelper::$cache = [];
        $_POST = $post;
        $_GET = $get + ['s' => 'public-hash'];
        $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
        $GLOBALS['participant_id'] = 10;
        $GLOBALS['Proj'] = new Project(355);
        $module = new NEDCaptcha2ExternalModule();
        $module->redcap_every_page_before_render(355);
        return $module;
    }

    // Optional helper fields must not be required for a simple custom/password CAPTCHA.
    set_error_handler(function($severity, $message) { throw new \ErrorException($message, 0, $severity); });
    $custom = ['type' => 'custom', 'custom' => [['challenge' => 'Question', 'response' => 'answer']]];
    $module = request($custom);
    check(property($module, 'active'), 'Custom CAPTCHA should be displayed without helper fields');
    check(strpos($GLOBALS['Proj']->metadata['captcha']['element_label'], 'Question') !== false, 'Custom challenge missing');
    restore_error_handler();

    $key = NEDCaptcha2ExternalModule::STORE_KEY . 'public-hash_' . Crypto::getGuid();
    foreach ([['0123', '123', false], ['0123', '0123', true], ['0e123', '0', false], ['0e123', '0e123', true], ['Secret', 'secret', false]] as [$expected, $answer, $passes]) {
        SurveyFramework::$logs[$key] = ['expected' => $expected, 'passed' => false];
        request(['type' => 'custom', 'custom' => [['challenge' => 'Key', 'response' => $expected]]], [NEDCaptcha2ExternalModule::CLIENT_KEY => Crypto::getGuid(), 'captcha' => $answer]);
        check(SurveyFramework::$logs[$key]['passed'] === $passes, 'Custom answers must match literally');
    }
    SurveyFramework::$logs[$key] = ['expected' => 'secret', 'passed' => false];
    request(['type' => 'custom', 'caseInsensitive' => true, 'custom' => [['challenge' => 'Key', 'response' => 'Secret']]], [NEDCaptcha2ExternalModule::CLIENT_KEY => Crypto::getGuid(), 'captcha' => 'SECRET']);
    check(SurveyFramework::$logs[$key]['passed'] === true, 'Case-insensitive comparison was lost');

    echo "Survey hook regressions passed.\n";
}
