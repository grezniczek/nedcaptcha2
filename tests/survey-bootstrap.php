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
namespace Vanderbilt\REDCap\Classes\FileFields {
    // Native uploads belong to the PHP session, independently of CAPTCHA proof.
    class FileFieldUploadContext {
        public static function fromTicket($ticket) {
            return isset($_SESSION['native_test_uploads'][$ticket]) ? new self() : null;
        }
    }
}
namespace {
    define('PAGE', 'surveys/index.php');

    function surveyContext($hash) {
        return ['project_id' => 355, 'form_name' => 'form_1', 'survey_id' => 1,
            'event_id' => $hash === 'second-arm' ? 2 : ($hash === 'later-event' ? 3 : 1),
            'participant_email' => $hash === 'private-hash' ? 'participant' : null];
    }

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
        public static $questionBySection = true;
        public static $headers = [];
        public static $stopActions = [];
        public static $discardOnStop = false;
        public static $fieldTypes = [];
        public static $repeating = '';
        public static $project_cache = [];
        public $metadata = [];
        public $forms;
        public $surveys;
        public $firstForm = 'form_1';
        public function isFirstEventIdInArm($event) { return in_array($event, [1, 2], true); }
        public $table_pk = 'record_id';
        public function __construct($projectId) {
            if (isset(self::$project_cache[$projectId])) {
                foreach (self::$project_cache[$projectId] as $key => $value) $this->$key = $value;
                return;
            }
            foreach (self::$annotations as $field => $annotation) {
                $this->metadata[$field] = ['misc' => $annotation, 'element_label' => 'Label', 'form_name' => 'form_1', 'stop_actions' => self::$stopActions[$field] ?? '', 'element_preceding_header' => self::$headers[$field] ?? '', 'element_type' => self::$fieldTypes[$field] ?? 'text'];
            }
            $this->forms = ['form_1' => ['survey_id' => 1, 'fields' => array_fill_keys(array_keys($this->metadata), true)]];
            $this->surveys = [1 => ['stop_action_delete_response' => self::$discardOnStop, 'save_and_return' => self::$saveAndReturn, 'question_by_section' => self::$questionBySection]];
        }
        public function isRepeatingFormOrEvent($event, $form) { return self::$repeating !== ''; }
        public function isRepeatingForm($event, $form) { return self::$repeating === 'form'; }
        public function isDraftMode() { return false; }
    }
    if (getenv('REDCAP_SURVEY_CLASS')) {
        require_once getenv('REDCAP_SURVEY_CLASS');
    } else {
        class Survey {
            public static function getSurveyContextFromSurveyHash($hash) { return surveyContext($hash); }
            public static function getParticipantIdFromHash($hash) { return $hash === 'public-hash' ? 10 : 11; }
            public static function getPageFields($instrument, $bySection) {
                $pages = [];
                $page = 1;
                foreach (array_keys($GLOBALS['Proj']->forms[$instrument]['fields']) as $field) {
                    if ($field === 'record_id' || $field === $instrument . '_complete') continue;
                    if ($bySection && $pages && $GLOBALS['Proj']->metadata[$field]['element_preceding_header'] !== '') $page++;
                    $pages[$page][] = $field;
                }
                return [$pages, count($pages)];
            }
            public static function getQuestionPage($field, $pages) {
                foreach ($pages as $page => $fields) if (in_array($field, $fields, true)) return $page;
                return 1;
            }
            public static function setPageNum($pages, $count) {
                if (!empty($GLOBALS['return_code'])) $_GET['__page__'] = self::getQuestionPage($GLOBALS['lastSavedField'], $pages);
                elseif ($_SERVER['REQUEST_METHOD'] !== 'POST') $_GET['__page__'] = 1;
                elseif (isset($_POST['submit-action'], $pages[$_POST['__page__']])) {
                    $_GET['__page__'] = $_POST['__page__'] + (isset($_GET['__reqmsg']) ? 0 : (isset($_GET['__prevpage']) ? -1 : 1));
                }
                return ['', [], ($_GET['__page__'] ?? 1) === $count];
            }
            public static function decryptResponseHash($hash, $participantId) { return $hash === 'valid-response' && $participantId === 10 ? 20 : ''; }
            public static function getPageNumHash($page) { return hash('sha256', 'test-page-' . $page); }
            public static function verifyPageNumHash($hash, $page) { return $hash === self::getPageNumHash($page); }
        }
    }
    class Crypto {
        public static $nextGuids = [];
        public static function getGuid() { return array_shift(self::$nextGuids) ?? '12345678-1234-4234-8234-123456789abc'; }
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
    function request($params, $post = [], $get = [], $extraAnnotations = [], $settings = [], $fieldOrder = null) {
        // Strings exercise hand-written action-tag parameters, including malformed ones.
        $parameterText = is_string($params) ? $params : json_encode($params);
        Project::$annotations = ['record_id' => '', 'captcha' => '@NEDCAPTCHA=' . $parameterText, 'normal' => ''] + $extraAnnotations;
        if ($fieldOrder !== null) {
            Project::$annotations = array_replace(array_fill_keys($fieldOrder, ''), Project::$annotations);
        }
        ActionTagHelper::$cache = [];
        $_POST = $post;
        $_GET = $get + ['s' => 'public-hash'];
        $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
        $GLOBALS['participant_id'] = 10;
        unset(Project::$project_cache[355]);
        $GLOBALS['Proj'] = new Project(355);
        Project::$project_cache[355] = $GLOBALS['Proj'];
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

}
