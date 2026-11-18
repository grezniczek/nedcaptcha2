<?php
namespace {
    require_once __DIR__ . '/survey-bootstrap.php';
    use DE\RUB\SEG\NEDCaptcha2ExternalModule\NEDCaptcha2ExternalModule;
    // Optional helper fields must not be required for a simple custom/password CAPTCHA.
    set_error_handler(function($severity, $message) { throw new \ErrorException($message, 0, $severity); });
    $custom = ['type' => 'custom', 'custom' => [['challenge' => 'Question', 'response' => 'answer']]];
    $module = request($custom);
    check(property($module, 'active'), 'Custom CAPTCHA should be displayed without helper fields');
    check(strpos($GLOBALS['Proj']->metadata['captcha']['element_label'], 'Question') !== false, 'Custom challenge missing');
    restore_error_handler();

    $key = NEDCaptcha2ExternalModule::STORE_KEY . 'public-hash_' . Crypto::getGuid();
    // Hand-written invalid parameters must still gate the survey with solvable math.
    foreach (['garbage', 'null', 'false', 'true', '"none"', '["none"]', '{"type":',
        ['type' => 'custom', 'custom' => [['challenge' => 'Key?', 'response' => " \t"]]],
        ['type' => 'custom', 'custom' => [['challenge' => [], 'response' => 'answer']]],
        '{"type":true}', '{"type":1}', '{"type":0}', '{"type":null}', '{"type":[]}', '{"type":"unknown"}'] as $invalid) {
        $_SESSION = [];
        SurveyFramework::$logs = [];
        $module = request($invalid);
        check(property($module, 'active') && !isset($GLOBALS['Proj']->metadata['normal']), 'Invalid parameters exposed the survey: ' . json_encode($invalid));
        check(SurveyFramework::$logs[$key]['type'] === 'math' && SurveyFramework::$logs[$key]['expected'] !== '', 'Fallback math challenge missing');
        check(strpos($GLOBALS['Proj']->metadata['captcha']['element_label'], 'nedcaptcha2-challenge') !== false, 'Fallback challenge was not rendered');
        check(property($module, 'errors') === [], 'Configuration recovery must not stop survey initialization');
        $proof = [NEDCaptcha2ExternalModule::CLIENT_KEY => Crypto::getGuid()];
        $module = request($invalid, $proof + ['captcha' => 'incorrect', 'submit-action' => 'submit-btn-saverecord', 'normal' => 'forged']);
        check(property($module, 'active') && $_POST === [] && !isset($GLOBALS['Proj']->metadata['normal']), 'Incorrect fallback answer bypassed protection');
        $answer = SurveyFramework::$logs[$key]['expected'];
        request($invalid, $proof + ['captcha' => $answer, 'submit-action' => 'submit-btn-saverecord', 'normal' => 'injected']);
        check(SurveyFramework::$logs[$key]['passed'] && isset($GLOBALS['Proj']->metadata['normal']), 'Correct fallback answer did not reveal the survey');
        check($_POST === [] && $_SERVER['REQUEST_METHOD'] === 'GET', 'Fallback solve also submitted survey answers');
        $post = $proof + ['submit-action' => 'submit-btn-saverecord', 'normal' => 'real answer'];
        request($invalid, $post);
        check($_POST === $post && isset($GLOBALS['Proj']->metadata['normal']), 'Solved fallback did not authorize the first submission');
    }
    // Invalid colors cannot interrupt survey rendering or disable the challenge.
    $_SESSION = SurveyFramework::$logs = [];
    $module = request(['type' => 'custom', 'bgColor' => [], 'textColor' => 'not a color', 'noiseColor' => 42,
        'custom' => [['challenge' => 'Bad', 'response' => ' '], ['challenge' => 'Key?', 'response' => '0']]]);
    check(SurveyFramework::$logs[$key]['expected'] === '0' && count(property($module, 'warnings')) === 4, 'Invalid color/pair handling lost the usable challenge');
    // Deliberately selecting the exact type "none" remains supported.
    $_SESSION = [];
    SurveyFramework::$logs = [];
    $module = request(['type' => 'none']);
    check(!property($module, 'active') && isset($GLOBALS['Proj']->metadata['normal']), 'Explicit disabled mode was changed');
    check(!isset(SurveyFramework::$logs[$key]), 'Explicit disabled mode issued a challenge');

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
    check(strpos($captureScript, $unsafe) === false && strpos($captureScript, '</script>') === false, 'Captured response reached survey JavaScript');
    check(SurveyFramework::$logs[$key]['capture_response'] === $unsafe, 'Verified response should be kept server-side');

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
