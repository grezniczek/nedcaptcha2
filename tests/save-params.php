<?php
// Run with: php tests/save-params.php
// Exercise the real save handler without bootstrapping REDCap or connecting to a database.
namespace ExternalModules {
    class AbstractExternalModule {
        public $framework;
        public function __construct() { $this->framework = new \EditorFramework(); }
        public function getProjectSetting($key) { return false; }
    }
}

namespace {
    class REDCap {
        public static $events = [];
        public static function logEvent(...$arguments) { self::$events[] = $arguments; }
    }
    class EditorFramework {
        public static $authenticated = true;
        public static $design = true;
        public function isAuthenticated() { return self::$authenticated; }
        public function getUser() { return $this; }
        public function hasDesignRights($projectId) { return self::$design; }
    }
    class Project {
        public static $annotation;
        public static $draft = false;
        public static $status = 0;
        public $metadata;
        public $metadata_temp;
        public $forms = [];
        public $forms_temp = [];

        public function __construct($projectId, $forceLoad = false) {
            $this->metadata = ['nc2_resp' => ['misc' => self::$annotation]];
            $this->metadata_temp = $this->metadata;
        }

        public function isDraftMode() { return self::$draft; }
        public function isDevelopment() { return self::$status === 0; }
        public function isProduction() { return self::$status === 1; }
    }

    function db_query($sql, $parameters) {
        $GLOBALS['save_query'] = [$sql, $parameters];
        return $GLOBALS['save_query_succeeds'] ?? true;
    }

    require_once __DIR__ . '/../classes/Color.php';
    require_once __DIR__ . '/../classes/ActionTagHelper.php';
    require_once __DIR__ . '/../NEDCaptcha2ExternalModule.php';

    use DE\RUB\SEG\NEDCaptcha2ExternalModule\ActionTagHelper;
    use DE\RUB\SEG\NEDCaptcha2ExternalModule\NEDCaptcha2ExternalModule;

    function check($condition, $message) {
        if (!$condition) throw new \RuntimeException($message);
    }

    function save($annotation, $params, $draft = false) {
        Project::$annotation = $annotation;
        Project::$draft = $draft;
        ActionTagHelper::$cache = [];
        $GLOBALS['save_query'] = null;
        $module = new NEDCaptcha2ExternalModule();
        return $module->redcap_module_ajax(
            'set-params', ['field' => 'nc2_resp', 'params' => $params], 355,
            null, null, null, null, null, null, null, null, null, null, null
        );
    }

    $cases = [
        '@NEDCAPTCHA',
        '@NEDCAPTCHA={"type":"math"}',
        '@HIDDEN-FORM @NEDCAPTCHA @HIDDEN-PDF',
        "@HIDDEN-FORM\n  @NEDCAPTCHA={\n    \"type\": \"math\"\n}\n@HIDDEN-PDF",
        '@NEDCAPTCHA-INSTRUCTIONS @NEDCAPTCHA={"type":"math"}',
        '@nedCAPTCHA={"type":"image"} @NEDCAPTCHA={"type":"math"}',
        'Text with ünicode before @NEDCAPTCHA',
    ];
    $checks = 0;
    foreach ($cases as $annotation) {
        foreach (['image', 'none'] as $type) {
            // Both bare and configured tags must save; other annotations must survive verbatim.
            $response = save($annotation, ['type' => $type]);
            check($response === ['errors' => [], 'warnings' => []], 'Save rejected: ' . $annotation);
            $saved = $GLOBALS['save_query'][1][0];
            $tags = ActionTagHelper::parseActionTags($saved, '@NEDCAPTCHA');
            check((json_decode($tags[0]['params'], true)['type'] ?? null) === $type, 'Type did not persist: ' . $annotation);
            $originalTag = ActionTagHelper::parseActionTags($annotation, '@NEDCAPTCHA')[0];
            $replacement = '@NEDCAPTCHA=' . json_encode(['type' => $type], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            check($saved === str_replace($originalTag['match'], $replacement, $annotation), 'Surrounding annotations changed');
            check($GLOBALS['save_query'][1][1] === 'nc2_resp' && $GLOBALS['save_query'][1][2] === 355, 'Wrong save target');
            $checks++;
        }
    }

    // Mixed/lowercase names must not activate the module or permit editor saves.
    foreach (['@nedCAPTCHA', '@nedcaptcha', '@NedCaptcha'] as $annotation) {
        $response = save($annotation, ['type' => 'none']);
        check(count($response['errors']) > 0 && $GLOBALS['save_query'] === null, 'Wrong-case tag was saved');
        $module = new NEDCaptcha2ExternalModule();
        $response = $module->redcap_module_ajax(
            'get-params', ['field' => 'nc2_resp'], 355,
            null, null, null, null, null, null, null, null, null, null, null
        );
        check($response === 'ERROR', 'Wrong-case tag opened the editor');
        check(ActionTagHelper::parseActionTags($annotation, '@NEDCAPTCHA') === [], 'Tag filter ignored case');
        $checks++;
    }

    // All module tags use exact names, including helper tags used by the survey.
    $names = ['@NEDCAPTCHA', '@NEDCAPTCHA-INSTRUCTIONS', '@NEDCAPTCHA-FAILMESSAGE', '@NEDCAPTCHA-DISPLAY', '@NEDCAPTCHA-CUSTOM-CHALLENGE', '@NEDCAPTCHA-CUSTOM-EMBED'];
    Project::$annotation = implode(' ', array_merge($names, array_map('strtolower', $names)));
    ActionTagHelper::$cache = [];
    $tags = ActionTagHelper::getActionTags(355, $names, 'nc2_resp');
    check(array_keys($tags) === $names, 'Recognized tag names changed');
    foreach ($names as $name) check(count($tags[$name]['nc2_resp']) === 1, 'Wrong-case helper tag was recognized');
    $checks++;

    Project::$annotation = 'No action tags here';
    ActionTagHelper::$cache = [];
    check(ActionTagHelper::getActionTags(355, $names, 'nc2_resp') === [], 'Untagged field was recognized');
    $checks++;

    // Case-sensitive matching must still parse uppercase hexadecimal Unicode escapes in JSON.
    $tags = ActionTagHelper::parseActionTags('@NEDCAPTCHA={"type":"custom","custom":[{"challenge":"\\uABCD","response":"Case"}]}', '@NEDCAPTCHA');
    check(json_decode($tags[0]['params'], true)['custom'][0]['challenge'] === json_decode('"\\uABCD"'), 'Unicode escape was not parsed');
    $checks++;

    // Custom challenge text must not be interpreted as regex replacement backreferences.
    $custom = 'Price $1 / \\1';
    $response = save('@NEDCAPTCHA', ['type' => 'custom', 'custom' => $custom . '=answer']);
    check($response === ['errors' => [], 'warnings' => []], 'Custom save rejected');
    $tags = ActionTagHelper::parseActionTags($GLOBALS['save_query'][1][0], '@NEDCAPTCHA');
    check(json_decode($tags[0]['params'], true)['custom'][0]['challenge'] === $custom, 'Custom text corrupted');
    $checks++;

    // Re-saving identical settings is a valid success, even if the database reports no changes.
    $annotation = '@NEDCAPTCHA=' . json_encode(['type' => 'none'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    check(save($annotation, ['type' => 'none']) === ['errors' => [], 'warnings' => []], 'Unchanged settings rejected');
    check($GLOBALS['save_query'][1][0] === $annotation, 'Unchanged settings modified');
    $checks++;

    $response = save('@NEDCAPTCHA', ['type' => 'none'], true);
    check($response === ['errors' => [], 'warnings' => []], 'Draft save rejected');
    check(strpos($GLOBALS['save_query'][0], 'UPDATE redcap_metadata_temp ') === 0, 'Wrong draft metadata table');
    $checks++;

    foreach (['bgColor' => [], 'noiseColor' => 7, 'textColor' => '256,1,2'] as $option => $invalidColor) {
        $response = save('@NEDCAPTCHA', ['type' => 'image', $option => $invalidColor]);
        check($response['warnings'] && $GLOBALS['save_query'] === null, 'Invalid color was saved');
        $checks++;
    }

    // A stale cached tag must not lead to a successful write after the actual tag was removed.
    $cachedAnnotation = '@NEDCAPTCHA';
    Project::$annotation = $cachedAnnotation;
    Project::$draft = false;
    ActionTagHelper::$cache = [];
    ActionTagHelper::getActionTags(355, ['@NEDCAPTCHA'], 'nc2_resp', null, null, true);
    Project::$annotation = '@HIDDEN-FORM';
    $GLOBALS['save_query'] = null;
    $module = new NEDCaptcha2ExternalModule();
    $response = $module->redcap_module_ajax(
        'set-params', ['field' => 'nc2_resp', 'params' => ['type' => 'none']], 355,
        null, null, null, null, null, null, null, null, null, null, null
    );
    check(count($response['errors']) > 0 && $GLOBALS['save_query'] === null, 'Missing tag silently saved');
    $checks++;

    // The survey and editor must both keep the default math CAPTCHA for a bare tag.
    foreach ([true, false] as $withDefaults) {
        $module = new NEDCaptcha2ExternalModule();
        $params = $module->validate_params('', $withDefaults);
        check($params['type'] === 'math', 'Bare tag must use the default math CAPTCHA');
        $checks++;
    }
    // Explicit JSON configurations retain the existing type/default behavior.
    foreach (['{"type":"math"}' => 'math', '{"type":"image"}' => 'image', '{"type":"none"}' => 'none', '{}' => 'math'] as $json => $type) {
        $module = new NEDCaptcha2ExternalModule();
        check($module->validate_params($json, true)['type'] === $type, 'JSON configuration type changed');
        $checks++;
    }

    // Invalid JSON/scalars and invalid type values must never disable protection.
    foreach (['garbage', '{"type":', 'null', 'false', 'true', '"none"', 0, false, null, new \stdClass(),
        ['type' => true], ['type' => 1], ['type' => 0], ['type' => null], ['type' => []]] as $invalid) {
        foreach ([false, true] as $withDefaults) {
            $module = new NEDCaptcha2ExternalModule();
            check($module->validate_params($invalid, $withDefaults)['type'] === 'math', 'Invalid configuration disabled the CAPTCHA');
            $checks++;
        }
    }

    // The editor offers math defaults and warnings for invalid stored parameters,
    // without changing metadata until a valid configuration is explicitly saved.
    foreach (['garbage', 'null', '"none"', '{"type":true}'] as $invalid) {
        Project::$annotation = '@HIDDEN-FORM @NEDCAPTCHA=' . $invalid . ' @HIDDEN-PDF';
        ActionTagHelper::$cache = [];
        $GLOBALS['save_query'] = null;
        $module = new NEDCaptcha2ExternalModule();
        $response = $module->redcap_module_ajax(
            'get-params', ['field' => 'nc2_resp'], 355,
            null, null, null, null, null, null, null, null, null, null, null
        );
        check($response['params']['type'] === 'math', 'Invalid configuration did not open with math defaults');
        check(preg_match('/<input[^>]*id="type-math"[^>]*checked/', $response['html']) === 1, 'Fallback math selection missing');
        check($response['warnings'] !== [] && $response['errors'] === [], 'Recoverable configuration error must produce a warning');
        check($GLOBALS['save_query'] === null, 'Reading invalid parameters changed metadata');
        check(save(Project::$annotation, ['type' => 'math']) === ['errors' => [], 'warnings' => []], 'Invalid configuration could not be repaired');
        check(strpos($GLOBALS['save_query'][1][0], '@HIDDEN-FORM @NEDCAPTCHA=') === 0
            && substr($GLOBALS['save_query'][1][0], -strlen(' @HIDDEN-PDF')) === ' @HIDDEN-PDF', 'Repair changed surrounding action tags');
        $checks++;
    }
    foreach (['garbage', ['type' => true], ['type' => 1]] as $invalid) {
        $response = save('@NEDCAPTCHA', $invalid);
        check($response['warnings'] !== [] && $GLOBALS['save_query'] === null, 'Invalid editor payload was saved');
        $checks++;
    }

    // The initial editor selection and the params sent back on a later edit must agree.
    Project::$annotation = '@NEDCAPTCHA';
    ActionTagHelper::$cache = [];
    $GLOBALS['save_query'] = null;
    $module = new NEDCaptcha2ExternalModule();
    $response = $module->redcap_module_ajax(
        'get-params', ['field' => 'nc2_resp'], 355,
        null, null, null, null, null, null, null, null, null, null, null
    );
    check($response['params']['type'] === 'math', 'Bare tag must start with the default math CAPTCHA');
    check(preg_match('/<input[^>]*id="type-math"[^>]*checked/', $response['html']) === 1, 'Math Problem radio is not selected');
    check($response['errors'] === [] && $response['warnings'] === [], 'Bare tag produced validation messages');
    check($GLOBALS['save_query'] === null, 'Opening the editor must not write metadata');
    $checks++;

    Project::$annotation = '@NEDCAPTCHA={"type":"image"}';
    ActionTagHelper::$cache = [];
    $module = new NEDCaptcha2ExternalModule();
    $response = $module->redcap_module_ajax(
        'get-params', ['field' => 'nc2_resp'], 355,
        null, null, null, null, null, null, null, null, null, null, null
    );
    check($response['params']['type'] === 'image', 'Configured type must be preserved');
    $checks++;

    $response = save('@NEDCAPTCHA', ['type' => 'math', 'minValue' => 20, 'maxValue' => 10]);
    check(count($response['warnings']) > 0 && $GLOBALS['save_query'] === null, 'Inverted operand range was saved');
    $module = new NEDCaptcha2ExternalModule();
    $params = $module->validate_params(['type' => 'math', 'minValue' => 20, 'maxValue' => 10, 'showAsText' => true], true);
    check($params['minValue'] === 1 && $params['maxValue'] === 10, 'Existing inverted ranges were not normalized');
    $generator = new \DE\RUB\SEG\NEDCaptcha2ExternalModule\CaptchaGenerator($params);
    check(!empty($generator->challenge), 'Normalized math configuration is not usable');
    $checks++;

    $text = '</textarea><script>window.audit = true</script>&"';
    Project::$annotation = '@NEDCAPTCHA=' . json_encode(['type' => 'custom', 'custom' => [['challenge' => $text, 'response' => 'answer']]]);
    ActionTagHelper::$cache = [];
    $module = new NEDCaptcha2ExternalModule();
    $response = $module->redcap_module_ajax(
        'get-params', ['field' => 'nc2_resp'], 355,
        null, null, null, null, null, null, null, null, null, null, null
    );
    check(strpos($response['html'], $text) === false, 'Custom text escaped its textarea');
    check(strpos($response['html'], htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) !== false, 'Custom text was not HTML encoded');
    check(strpos($response['params']['custom'], $text) === 0, 'Editable custom text was changed');
    $checks++;

    foreach ([[false, true, 0, false], [true, false, 0, false], [true, true, 1, false], [true, true, 2, true]] as [$authenticated, $design, $status, $draft]) {
        EditorFramework::$authenticated = $authenticated;
        EditorFramework::$design = $design;
        Project::$status = $status;
        $response = save('@NEDCAPTCHA', ['type' => 'none'], $draft);
        check(count($response['errors']) > 0 && $GLOBALS['save_query'] === null, 'Unauthorized or noneditable project save succeeded');
        $loadResponse = (new NEDCaptcha2ExternalModule())->redcap_module_ajax(
            'get-params', ['field' => 'nc2_resp'], 355,
            null, null, null, null, null, null, null, null, null, null, null
        );
        check($loadResponse === $response, 'Load must report the same access/draft error as save');
        check(!isset($loadResponse['html']) && $GLOBALS['save_query'] === null, 'Refused load exposed editor HTML or wrote metadata');
        $checks++;
    }
    EditorFramework::$authenticated = EditorFramework::$design = true;
    Project::$status = 1;
    $response = save('@NEDCAPTCHA', ['type' => 'none'], true);
    check($response['errors'] === [] && strpos($GLOBALS['save_query'][0], 'redcap_metadata_temp') !== false, 'Production draft save failed');
    Project::$status = 0;
    $checks++;

    REDCap::$events = [];
    save('@NEDCAPTCHA={"type":"math"}', ['type' => 'none']);
    check(count(REDCap::$events) === 1 && REDCap::$events[0][5] === 355, 'Configuration audit event missing or in wrong project');
    check(strpos(REDCap::$events[0][1], 'before = {"type":"math"}') !== false && strpos(REDCap::$events[0][1], 'after = {"type":"none"}') !== false, 'Audit before/after missing');
    $old = '@NEDCAPTCHA={"type":"custom","custom":[{"challenge":"Key","response":"old-secret"}]}';
    save($old, ['type' => 'custom', 'custom' => 'Key=new-secret']);
    $event = REDCap::$events[1][1];
    check(strpos($event, 'old-secret') === false && strpos($event, 'new-secret') === false && strpos($event, '[REDACTED]') !== false, 'Audit event exposed an access key');
    $eventCount = count(REDCap::$events);
    save('@NEDCAPTCHA=' . json_encode(['type' => 'none'], JSON_PRETTY_PRINT), ['type' => 'none']);
    $GLOBALS['save_query_succeeds'] = false;
    save('@NEDCAPTCHA', ['type' => 'none']);
    $GLOBALS['save_query_succeeds'] = true;
    check(count(REDCap::$events) === $eventCount, 'Failed/unchanged save should not log an update');
    $checks++;

    echo "Passed $checks editor/save-handler regression cases (in-memory metadata/database).\n";
}
