<?php namespace DE\RUB\SEG\NEDCaptcha2ExternalModule;

use ExternalModules\AbstractExternalModule;

class NEDCaptcha2ExternalModule extends AbstractExternalModule {
	
	const CLIENT_KEY = "__nedcaptcha2__ck__";
	const STORE_KEY = "__nedcaptcha2__";
	const CHALLENGE_SESSION_KEY = '__nedcaptcha2_challenges__';
	const SESSION_COOKIE_PREFIX = "__nedcaptcha2session__";
	const SESSION_STORE_KEY = "__nedcaptcha2session__";

	const AT_SETUP = "@NEDCAPTCHA";
	const AT_INSTRUCTIONS = "@NEDCAPTCHA-INSTRUCTIONS";
	const AT_FAILMESSAGE = "@NEDCAPTCHA-FAILMESSAGE";
	const AT_DISPLAY = "@NEDCAPTCHA-DISPLAY";
	const AT_CUSTOMCHALLENGE = "@NEDCAPTCHA-CUSTOM-CHALLENGE";
	const AT_CUSTOMEMBED = "@NEDCAPTCHA-CUSTOM-EMBED";

	const IMAGE_MIN_LENGTH = 3;
	const IMAGE_MAX_LENGTH = 8;
	const IMAGE_DEFAULT_LENGTH = 5;

	/** @var int The number of minutes a CAPTCHA is valid for */
	const CAPTCHA_EXPIRATION = 120; 
	const SESSION_DEFAULT_TIMEOUT_MINUTES = 120;
	const SESSION_HARD_MAX_MINUTES = 1440;
	const SESSION_IDLE_MAX_MINUTES = 120;

	private $nedcaptcha_fields = [];
	private $scripts_delayed = [];
	private $scripts_regular = [];
	private $scripts_top = [];
	private $styles = [];
	private $errors = [];
	private $warnings = [];
	private $active = false;
	private $suppress_calculation_emitter = false;
	private $accepted_submission_key = null;
	private $pending_capture = null;

	#region Hooks

	function redcap_every_page_before_render($project_id) {

		// Only operate on a fresh public survey page
		$page = defined("PAGE") ? PAGE : "";
		if ($page != "surveys/index.php") return;
		$psh = $this->framework->getPublicSurveyHash($project_id);
		$sh = $_GET["s"] ?? "";

		// Real passthru endpoints terminate in REDCap's dispatcher; an empty flag does not.
		if ($sh == "") return;
		if (!empty($_GET["__passthru"])) return;

		// Load previous state (if any)
		$client_key = $_POST[self::CLIENT_KEY] ?? null;
		if (!is_string($client_key) || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $client_key)) {
			$client_key = \Crypto::getGuid();
		}
		$store_key = self::STORE_KEY."{$psh}_{$client_key}";
		$cutoff = time() - self::CAPTCHA_EXPIRATION * 60;
		foreach ($_SESSION[self::CHALLENGE_SESSION_KEY] ?? [] as $key => $issued_at) {
			if ($issued_at < $cutoff) unset($_SESSION[self::CHALLENGE_SESSION_KEY][$key]);
		}
		$owned = isset($_SESSION[self::CHALLENGE_SESSION_KEY][$store_key]);
		$stored = ["expected" => null, "passed" => false];
		if (!$owned) {
			$client_key = \Crypto::getGuid();
			$store_key = self::STORE_KEY."{$psh}_{$client_key}";
		}
		if ($owned) {
			$result = $this->framework->queryLogs("SELECT expected, passed, type, capture_field, capture_response WHERE message = ?", $store_key);
			$stored = $result->fetch_assoc() ?? $stored;
		}

		/** @var \Project */
		$Proj = $GLOBALS["Proj"];
		// Obtain survey info
		$context = \Survey::getSurveyContextFromSurveyHash($sh);
		// Check if this is a valid instrument
		if (!array_key_exists($context["form_name"] ?? "__", $Proj->forms)) return;
		// Prepare context
		$instrument = $context["instrument"] = $context["form_name"]; // Rename and augment for use in ActionTagHelper
		$context["record"] = null;
		$context["instance"] = 1;
		list($page_fields, $total_pages) = \Survey::getPageFields(
			$instrument, $Proj->surveys[$context['survey_id']]['question_by_section']
		);
		// Existing responses carry a REDCap-authenticated response hash, not just a page number.
		$response_hash = $_POST['__response_hash__'] ?? null;
		if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['__endpublicsurvey'])) {
			$response_hash = $_GET['__rh'] ?? null;
		}
		$existing_response = false;
		if ($sh == $psh && is_string($response_hash) && $response_hash !== '') {
			$participant_id = \Survey::getParticipantIdFromHash($sh);
			$response_id = $participant_id ? \Survey::decryptResponseHash($response_hash, $participant_id) : null;
			$existing_response = is_numeric($response_id) && $response_id > 0;
		}
		// Only the dedicated Save & Return flow may defer code validation to REDCap.
		$save_and_return = !empty($Proj->surveys[$context['survey_id']]['save_and_return']);
		$returning = $save_and_return && !isset($_POST['submit-action']) && !isset($_POST['__prefill'])
			&& (isset($_GET['__return']) || (isset($_POST['__code']) && is_string($_POST['__code'])));
		
		// Check for action tags
		$tagged = ActionTagHelper::getActionTags($project_id, [
			self::AT_SETUP, 
			self::AT_INSTRUCTIONS, 
			self::AT_FAILMESSAGE,
			self::AT_DISPLAY,
			self::AT_CUSTOMCHALLENGE,
			self::AT_CUSTOMEMBED,
		], $page_fields[1], null, $context) ?? [];
		$this->nedcaptcha_fields = array_filter([
			array_keys($tagged[self::AT_SETUP] ?? [""])[0], 
			array_keys($tagged[self::AT_INSTRUCTIONS] ?? [""])[0], 
			array_keys($tagged[self::AT_FAILMESSAGE] ?? [""])[0],
			...array_keys($tagged[self::AT_DISPLAY] ?? [""]),
			array_keys($tagged[self::AT_CUSTOMCHALLENGE] ?? [""])[0],
			// AT_CUSTOMEMBED will be considered later
		], function($v) { return !empty($v); });
		$session_passed = $psh == $sh && isset($tagged[self::AT_SETUP]) && $this->validateSolvedSession($project_id, $psh);

		if ($returning || $existing_response || $stored["passed"] || $session_passed || $psh != $sh || !isset($tagged[self::AT_SETUP])) {
			if ($owned && $stored['passed'] && !$existing_response && isset($_POST['submit-action'])) {
				$this->accepted_submission_key = $store_key;
				$capture_field = $stored['capture_field'] ?? null;
				if ($capture_field !== null && isset($tagged[self::AT_SETUP][$capture_field], $stored['capture_response'])) {
					// Keep the verified answer server-side, independently of the survey's page layout.
					$this->pending_capture = [clone $Proj, $capture_field, $stored['capture_response']];
				}
			}
			// We are outside any context where the CAPTCHA should be shown
			// Remove the fields
			$this->nedcaptcha_fields = array_unique(array_merge($this->nedcaptcha_fields, array_keys($tagged[self::AT_CUSTOMEMBED] ?? [])));
			foreach ($this->nedcaptcha_fields as $field) {
				unset($Proj->metadata[$field]);
				unset($Proj->forms[$instrument]['fields'][$field]);
			}
			// We are done
			return;
		}

		// Validate parameters
		$captcha_field = array_keys($tagged[self::AT_SETUP])[0];
		$params = $this->validate_params($tagged[self::AT_SETUP][$captcha_field][0], true);
		// Some debug logging
		if ($params["debug"]) {
			$this->scripts_top[] = "console.log('nedCAPTCHA 2: Parameters:',".json_encode(array_merge($params, ["custom" => "[REDCATED]"])).");";
		}
		if ($params["type"] == "none") {
			$this->scripts_top[] = "console.log('nedCAPTCHA 2: No CAPTCHA required.');";
			$this->nedcaptcha_fields = array_unique(array_merge($this->nedcaptcha_fields, array_keys($tagged[self::AT_CUSTOMEMBED] ?? [])));
			foreach ($this->nedcaptcha_fields as $field) {
				unset($Proj->metadata[$field]);
				unset($Proj->forms[$instrument]['fields'][$field]);
			}
			return;
		}
		$this->active = true;

		// Validate and/or generate the CAPTCHA
		// Get response
		$response = $_POST[$captcha_field] ?? '';
		$response = is_string($response) ? trim($response) : '';
		$expected = $stored["expected"];
		$response_type = $stored['type'] ?? $params['type'];
		if ($response_type == "custom") {
			if ($params["caseInsensitive"]) {
				$response = mb_strtolower($response);
				if ($expected !== null) $expected = mb_strtolower((string)$expected);
			}
		}
		else {
			$response = strtolower($response);
		}
		// Check response
		$matches = $expected !== null && (string)$expected !== '' && ($response_type === "math"
			? $expected == $response
			: (string)$expected === $response);
		$passed = $stored["passed"] || $matches;
		// An unsolved CAPTCHA POST must never reach REDCap's record-saving paths.
		$_POST = [];
		$_SERVER['REQUEST_METHOD'] = 'GET';
		foreach (['__add_participant', '__participant_identifier', '__return', '__endpublicsurvey', '__rh', '__endsurvey', '__page__', '__prevpage', '__reqmsg', '__passthru', 'id'] as $flag) {
			unset($_GET[$flag]);
		}
		$_SESSION[self::CHALLENGE_SESSION_KEY][$store_key] = $_SESSION[self::CHALLENGE_SESSION_KEY][$store_key] ?? time();

		// Reset expected?
		$expected = ($passed || $params["reuse"]) ? $stored["expected"] : null;

		$captcha = new CaptchaGenerator($params, $expected);
		$params['type'] = $captcha->type;
		if ($captcha->error !== null) {
			$this->warnings[] = 'The configured CAPTCHA could not be rendered. Using a text math puzzle instead.';
		}
		$stored = [
			"expected" => $captcha->expected,
			"passed" => $passed,
			"type" => $captcha->type,
			"capture_field" => $passed && $params['capture'] ? $captcha_field : null,
			"capture_response" => $passed && $params['capture'] ? $response : null,
		];
		$this->framework->removeLogs("message = ?", $store_key);
		$this->framework->log($store_key, $stored);

		if ($passed) {
			// CAPTCHA was solved
			$this->createSolvedSession($project_id, $psh);
			$this->nedcaptcha_fields = array_unique(array_merge($this->nedcaptcha_fields, array_keys($tagged[self::AT_CUSTOMEMBED] ?? [])));
			foreach ($this->nedcaptcha_fields as $field) {
				unset($Proj->metadata[$field]);
				unset($Proj->forms[$instrument]['fields'][$field]);
			}
			// Trick REDCap into showing the survey instructions
			$_SERVER["REQUEST_METHOD"] = "GET";
			// Clear all warnings
			$this->warnings = [];
			// Carry the browser-bound proof into the first real survey submission.
			$this->scripts_regular[] = "$('<input>', {type: 'hidden', name: " . self::encodeJavascriptValue(self::CLIENT_KEY) . "}).val(" . self::encodeJavascriptValue($client_key) . ").appendTo('form#form');";
			if ($params["debug"]) {
				$this->scripts_top[] = "console.log('nedCAPTCHA 2: CAPTCHA passed.');";
				if ($params["capture"]) {
					$this->scripts_top[] = "console.log('nedCAPTCHA 2: Response captured:', " . self::encodeJavascriptValue($response) . ");";
				}
			}
			return;
		}

		// Add client key to form
		$this->scripts_delayed[] = "$('<input>', {type: 'hidden', name: " . self::encodeJavascriptValue(self::CLIENT_KEY) . "}).val(" . self::encodeJavascriptValue($client_key) . ").insertAfter($('input[name=\"redcap_csrf_token\"]'));";

		// Setup the CAPTCHA for display
		if ($params["type"] == "math") {
			$this->scripts_delayed[] = "$('input[name=\"{$captcha_field}\"]')".
				".attr('type', 'number')" .
				".attr('step', '1')" .
				".attr('inputmode', 'numeric')" .
				".attr('pattern', '\d*');";
		}
		else if ($params["type"] == "image") {
			$this->scripts_delayed[] = "$('input[name=\"{$captcha_field}\"]')
				.attr('pattern', '[".CaptchaGenerator::IMAGE_CHARS."]*');";
		}
		else if ($params["type"] == "custom") {
			// Apply AT_CUSTOMCHALLENGE action tag
			foreach ($tagged[self::AT_CUSTOMCHALLENGE] ?? [] as $this_field => $_) {
				if (in_array($this_field, $this->nedcaptcha_fields, true)) {
					$this->scripts_delayed[] = "$('input[name=\"{$this_field}\"]').val(".json_encode($captcha->challenge)."); doBranching('$this_field');";
				}
			}
			// Check for AT_CUSTOMEMBED
			$embed = [];
			foreach ($tagged[self::AT_CUSTOMEMBED] ?? [] as $this_field => $these_params) {
				// Skip fields that are already tagged with other action tags
				if (in_array($this_field, $this->nedcaptcha_fields, true)) continue;
				// Check for matching challenge
				foreach ($these_params as $this_param) {
					if (isset($this_param[0]) && $this_param[0] === '"') {
						$this_param = json_decode($this_param, true);
						if (!is_string($this_param)) continue;
					}
					if ($this_param === $captcha->challenge) {
						$embed[] = $this_field;
						$this->nedcaptcha_fields[] = $this_field;
					}
				}
			}
		}
		// Add the CAPTCHA to the label and then move it in front of the input
		$challenge_html = $params["type"] === "custom" ? $this->framework->escape($captcha->challenge) : $captcha->challenge;
		$Proj->metadata[$captcha_field]["element_label"] .= 
			"<div class=\"nedcaptcha2-challenge\" data-target=\"$captcha_field\">{$challenge_html}</div>";
		$this->scripts_regular[] = "$('input[name=\"{$captcha_field}\"]').before($('div.nedcaptcha2-challenge[data-target=\"{$captcha_field}\"]'));";

		// Move any fields to be embedded for custom challanges
		if ($params["type"] == "custom" && count($embed) > 0) {
			$this->scripts_regular[] = "$('div.nedcaptcha2-challenge[data-target=\"{$captcha_field}\"]').html('');";
			foreach ($embed as $this_field) {
				$this->scripts_regular[] = "$('div.nedcaptcha2-challenge[data-target=\"{$captcha_field}\"]').append($('#$this_field-tr td.labelrc').hide().has('[data-kind=\"field-label\"]').children());";
			}
		}
		$jsmo = $this->framework->getJavascriptModuleObjectName();

		$highlight_input = "$('i.nedcaptcha2-highlight').remove(); $('input[name=\"{$captcha_field}\"]').css({ 'outline': '2px solid red'}).after($('<i class=\"fa-solid fa-exclamation-circle fa-lg ms-2 text-danger nedcaptcha2-highlight\"></i>')).get(0).focus();";
	
		// Substitute survey instructions?
		if (isset($tagged[self::AT_INSTRUCTIONS])) {
			$instructions_field = array_keys($tagged[self::AT_INSTRUCTIONS])[0];
			$this->styles[] = "form#form [sq_id=\"{$captcha_field}\"] input[name=\"{$captcha_field}\"] { max-width: 150px; }";
			$this->styles[] = "[sq_id=\"{$instructions_field}\"] { display: none !important; }";
			$this->styles[] = "#surveyinstructions { display: none !important; }";
			$this->styles[] = "#surveyinstructions-reveal { display: none !important; }";
			$this->styles[] = "#nedcaptcha2-instructions { padding: 0 10px 15px; }";
			$this->styles[] = ".nedcaptcha2-challenge { margin: 0 5px 10px 5px;}";
			$this->scripts_regular[] = "$('#surveyinstructions').after($('<div id=\"nedcaptcha2-instructions\"></div>'));";
			$this->scripts_top[] = 
				<<<END
					$jsmo.afterRender(function() {
						$('#nedcaptcha2-instructions').html('').append($('[sq_id="{$instructions_field}"]').find('[data-kind="field-label"]').clone());
					});
				END;
		}
		$this->scripts_regular[] = 
				<<<END
					$('button[name=submit-btn-savereturnlater]').remove();
					$('input[type="hidden"]').each(function() {
						if ($(this).attr('name') != 'redcap_csrf_token') {
							$(this).remove();
						}
					});
					window['nc2__dataEntrySubmit'] = window['dataEntrySubmit'];
					window['dataEntrySubmit'] = function() {
						if ($('input[name="{$captcha_field}"]').val() == '') {
							$highlight_input
							$('button[name=submit-btn-saverecord]').button('enable');
							return false;
						}
						else {
							return window['nc2__dataEntrySubmit']();
						}
					};
				END;
		// Remove Save & Continue Later button
		$this->styles[] = "button[name=submit-btn-savereturnlater] { display: none; }";
		$this->scripts_regular[] = "$('button[name=submit-btn-savereturnlater]').remove();";

		// Fail message? Hide it
		$fail_action = "hide";
		if ($response != "" && !$passed) $fail_action = "show";
		if (isset($tagged[self::AT_FAILMESSAGE])) {
			$failmessage_field = array_keys($tagged[self::AT_FAILMESSAGE])[0];
			// Original row
			$this->scripts_regular[] = "$('[sq_id=\"{$failmessage_field}\"]').{$fail_action}();";
			// In case it's embedded
			$this->scripts_regular[] = "$('.rc-field-embed[var=\"{$failmessage_field}\"]').{$fail_action}();";
		}
		if ($fail_action == "show") {
			$this->scripts_regular[] = $highlight_input;
			if ($params["debug"]) {
				$this->scripts_top[] = "console.log('nedCAPTCHA 2: CAPTCHA failed. Retrying...');";
			}
		}
		else if ($params["debug"]) {
			$this->scripts_top[] = "console.log('nedCAPTCHA 2: Presenting CAPTCHA ...');";
		}

		$keep_fields = [ ...$this->nedcaptcha_fields ];
		$keep_fields[] = $Proj->table_pk;
		if ($total_pages == 1) {
			$keep_fields[] = "{$instrument}_complete";
		}
		$Proj->metadata = array_intersect_key($Proj->metadata, array_flip($keep_fields));
		$Proj->forms[$instrument]['fields'] = array_intersect_key($Proj->forms[$instrument]['fields'], array_flip($keep_fields));
		$this->suppress_calculation_emitter = true;
	}

	function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance) {
		if ($this->accepted_submission_key === null || $survey_hash !== $this->framework->getPublicSurveyHash($project_id)) return;
		if ($this->pending_capture !== null) {
			list($project, $field, $response) = $this->pending_capture;
			$data = [$record => [$event_id => [$field => $response]]];
			if ($project->isRepeatingFormOrEvent($event_id, $instrument)) {
				$repeat_form = $project->isRepeatingForm($event_id, $instrument) ? $instrument : '';
				$data = [$record => ['repeat_instances' => [$event_id => [$repeat_form => [$repeat_instance => [$field => $response]]]]]];
			}
			// The data API also uses Project's cache. Give it the original metadata only
			// during this save, then restore the survey layout before REDCap continues.
			$survey_project = $GLOBALS['Proj'];
			$cached_project = \Project::$project_cache[$project_id] ?? null;
			try {
				$GLOBALS['Proj'] = \Project::$project_cache[$project_id] = $project;
				$result = \REDCap::saveData([
					'project_id' => $project_id, 'dataFormat' => 'array', 'data' => $data,
					'performAutoCalc' => false,
				]);
				if (!is_array($result) || !empty($result['errors'])) throw new \RuntimeException('CAPTCHA capture failed');
			}
			catch (\Throwable $error) {
				// Capture failure must not interrupt an otherwise saved survey.
				$this->framework->log('CAPTCHA response capture failed', ['record' => $record, 'field' => $field]);
			}
			finally {
				$GLOBALS['Proj'] = $survey_project;
				if ($cached_project === null) unset(\Project::$project_cache[$project_id]);
				else \Project::$project_cache[$project_id] = $cached_project;
				$this->pending_capture = null;
			}
		}
		// A completed first submission cannot be replayed to create another response.
		$this->framework->removeLogs('message = ?', $this->accepted_submission_key);
		unset($_SESSION[self::CHALLENGE_SESSION_KEY][$this->accepted_submission_key]);
		$this->accepted_submission_key = null;
	}

	function redcap_survey_page_top($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance = 1)  {

		// Check if this is a fresh (i.e., not associated with any record) public survey
		$is_fresh_public = $record == null && $survey_hash == $this->getPublicSurveyHash($project_id);
		// Skip the CAPTCHA if a record is already defined or it is set to inactive.
		if (!$is_fresh_public) return;


		if (!empty($this->warnings)) {
			foreach ($this->warnings as $warning) {
				print \RCView::script("console.warn('nedCAPTCHA 2: ' + ".json_encode($warning).");");
			}
		}
		if (!empty($this->errors)) {
			foreach ($this->errors as $error) {
				print \RCView::script("console.error('nedCAPTCHA 2: ' + ".json_encode($error).");");
			}
			return;
		}

		if (!$this->active) return;

		if ($this->suppress_calculation_emitter) {
			// REDCap creates and populates this emitter after redcap_every_page_before_render().
			// Reset it here, after buildFormData() but before REDCap exports its JavaScript.
			// Using REDCap's native class keeps the empty client-side contract version-compatible.
			$GLOBALS["cp"] = new \Calculate();
		}

		$this->initializeJavascriptModuleObject();

		// Inject CSS and JavaScript - this is done here after jQuery has been loaded
		print "<!-- nedCAPTCHA 2 -->\n";
		print \RCView::style(join("\n", $this->styles));
		print \RCView::script(join("\n", $this->scripts_top));
	}

	function redcap_survey_page($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance = 1)  {

		// Check if this is a fresh (i.e., not associated with any record) public survey
		$is_fresh_public = $record == null && $survey_hash == $this->getPublicSurveyHash($project_id);
		// Skip the CAPTCHA if a record is already defined or it is set to inactive.
		if (!$is_fresh_public || !$this->active) return;

		// Inject CSS and JavaScript - this is done here after jQuery has been loaded
		print "<!-- nedCAPTCHA 2 -->\n";
		print \RCView::script(join("\n", $this->scripts_regular));
		print \RCView::script(join("\n", $this->scripts_delayed), true);
	}

	function redcap_every_page_top($project_id) {
		$page = defined("PAGE") ? PAGE : "";
		
		if ($page == "Design/online_designer.php" && isset($_GET["page"])) {
			$instrument = $_GET["page"];
			$this->inject_online_designer($project_id, $instrument);
		}
	}

	function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance, $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id) {
		if ($action === 'get-params' || $action === 'set-params') {
			if (!$project_id) return ["errors" => ["A project is required."], "warnings" => []];
			$edit_project = new \Project($project_id, true);
			$edit_error = $this->getConfigurationEditError($project_id, $edit_project);
			if ($edit_error !== null) return ["errors" => [$edit_error], "warnings" => []];
		}

		#region get-params
		if ($action == "get-params") {
			$field = $payload["field"];

			$tagged = ActionTagHelper::getActionTags($project_id, [self::AT_SETUP], $field, null, null, true)[self::AT_SETUP] ?? [];

			if (count($tagged) == 0) return "ERROR";

			$params = $this->validate_params($tagged[$field][0], false);
			unset($params["debug"]);
			$defaults = $this->validate_params($tagged[$field][0], true);
			unset($defaults["debug"]);
			$valid_keys = array_keys($defaults);
			// Convert custom to string
			$custom_pairs = "";
			foreach ($defaults["custom"] ?? [] as $pair) {
				$custom_pairs .= $pair["challenge"] . "=" . $pair["response"] . "\n";
			}
			$params["custom"] = $defaults["custom"] = $custom_pairs;



			// Generate HTML by capturing the output from including setup-ui.php
			ob_start();
			require "setup-ui.php";
			$html = ob_get_contents();
			ob_end_clean();

			return [
				"params" => $params,
				"keys" => $valid_keys,
				"html" => $html,
				"warnings" => $this->warnings,
				"errors" => $this->errors,
			];
		}
		#endregion
		#region set-params
		if ($action == "set-params") {
			$field = $payload["field"];
			// Validate
			$tagged = ActionTagHelper::getActionTags($project_id, [self::AT_SETUP], $field, null, null, true)[self::AT_SETUP] ?? [];
			if (count($tagged) == 0) return [
				"errors" => ["Invalid request."],
				"warnings" => [],
			];
			// Get defaults and reset warnings
			$defaults = $this->validate_params(["type" => "math"], true);
			$this->warnings = [];
			// Convert custom challenge-response pairs to array
			if (isset($payload["params"]["custom"])) {
				$custom_array = [];
				$custom_string = trim("{$payload["params"]["custom"]}");
				if ($custom_string != "") {
					$pairs = explode("\n", $custom_string);
					$line = 0;
					foreach ($pairs as $pair) {
						$line++;
						if ($pair == "") continue; // skip empty lines
						$parts = explode("=", $pair, 2);
						$custom_challenge = trim($parts[0]);
						$custom_response = trim($parts[1] ?? "");
						if ($custom_challenge == "" || $custom_response == "") {
							$this->warnings[] = "Invalid custom challenge-response pair in line $line.";
						}
						else {
							$custom_array[] = [
								"challenge" => trim($parts[0]),
								"response" => trim($parts[1]),
							];
						}
					}
					$payload["params"]["custom"] = $custom_array;
				}
				else {
					unset($payload["params"]["custom"]);
				}
			}
			$params = $this->validate_params($payload["params"], false);
			unset($params["debug"]);
			// Minimize data
			foreach ($params as $key => $value) {
				if ($key == "type") continue;
				if ($key == "custom") {
					if (count($value) == 0) unset($params[$key]);
				}
				else if ((string)$value == (string)$defaults[$key]) {
					unset($params[$key]);
				}
			}
			if (count($this->errors) || count($this->warnings)) {
				return [
					"warnings" => $this->warnings,
					"errors" => $this->errors,
				];
			}
			// Get current annotations
			$Proj = new \Project($project_id);
			$misc_current = $Proj->isDraftMode() 
				? ($Proj->metadata_temp[$field]["misc"] ?? "")
				: ($Proj->metadata[$field]["misc"] ?? "");
			// Replace the first parsed setup tag, including a tag without parameters.
			$setup_tags = ActionTagHelper::parseActionTags($misc_current, self::AT_SETUP);
			if (empty($setup_tags)) {
				return [
					"errors" => ["The @NEDCAPTCHA action tag could not be found. Please reload the page and try again."],
					"warnings" => [],
				];
			}
			$setup_tag = $setup_tags[0];
			$replacement = self::AT_SETUP."=".json_encode($params, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
			$misc_new = substr_replace($misc_current, $replacement, $setup_tag["offset"], strlen($setup_tag["match"]));
			// Store
			$table_name = $Proj->isDraftMode() ? "redcap_metadata_temp" : "redcap_metadata";
			$sql = "UPDATE $table_name SET misc = ? WHERE field_name = ? AND project_id = ?";
			$q = db_query($sql, [$misc_new, $field, $project_id]);
			if ($q) {
				if ($misc_new !== $misc_current) {
					$changes = "field_name = $field\nmetadata_table = $table_name\n"
						. "before = " . self::configurationAuditValue($tagged[$field][0]) . "\n"
						. "after = " . self::configurationAuditValue($params);
					\REDCap::logEvent('Update nedCAPTCHA configuration', $changes, null, null, null, $project_id);
				}
				return [ "errors" => [], "warnings" => [] ];
			}
			else {
				return [
					"errors" => ["Failed to update metadata."],
					"warnings" => [],
				];
			}
		}
		#endregion

		return [
			"errors" => ["Invalid action."],
			"warnings" => [],
		];
	}

	#endregion

	#region Crons

	function nedcaptcha2_cron_clean_log($cron_attributes) {
		// Clear all expired log entries
		$timestamp = date("Y-m-d H:i:s", time() - (self::CAPTCHA_EXPIRATION * 60));
		$sql = "project_id > 0 and message LIKE '".self::STORE_KEY."%' and timestamp < ?";
		$this->framework->removeLogs($sql, [$timestamp]);

		// Solved-session rows are refreshed whenever a valid session is used, so
		// stale rows older than the idle limit can be removed by log timestamp.
		$session_timestamp = date("Y-m-d H:i:s", time() - (self::SESSION_IDLE_MAX_MINUTES * 60));
		$session_sql = "project_id > 0 and message LIKE '".self::SESSION_STORE_KEY."%' and timestamp < ?";
		$this->framework->removeLogs($session_sql, [$session_timestamp]);
	}

	#endregion

	#region Private Methods

	private static function encodeJavascriptValue($value) {
		return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
	}

	private static function configurationAuditValue($params) {
		if ($params === '') $params = ['type' => 'math'];
		if (!is_array($params)) $params = json_decode($params, true);
		if (!is_array($params)) return '[invalid parameters]';
		// Custom responses can be access keys. Never expose them to project-log readers.
		if (isset($params['custom'])) {
			if (is_array($params['custom'])) {
				foreach ($params['custom'] as &$pair) {
					$pair = is_array($pair) ? array_merge($pair, ['response' => '[REDACTED]']) : '[invalid pair]';
				}
				unset($pair);
			}
			else $params['custom'] = '[invalid pairs]';
		}
		return json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	private function getConfigurationEditError($project_id, $project) {
		if (!$this->framework->isAuthenticated() || !$this->framework->getUser()->hasDesignRights($project_id)) {
			return "Design rights are required to edit CAPTCHA settings.";
		}
		if (!$project->isDevelopment() && !($project->isProduction() && $project->isDraftMode())) {
			return "Enter editable draft mode before changing CAPTCHA settings in a production project.";
		}
		return null;
	}

	/**
	 * Validate parameters
	 * @param mixed $params 
	 * @param bool $set_defaults 
	 * @return array 
	 */
	public function validate_params($params, $set_defaults = false) {
		// A bare setup tag uses the default math CAPTCHA on the survey and in the editor.
		if ($params === "") $params = ["type" => "math"];
		if (!is_array($params)) {
			if ($params == "") $params = "{}";
			$params = json_decode($params, true);
		}
		if (!is_array($params)) {
			$this->errors[] = "Error parsing NEDCaptcha 2 parameters! CAPTCHA is disabled.";
			$params = [ "type" => "none" ];
		}
		$full = [
			"debug" => $this->getProjectSetting("debug") == true,
		];
		// angleVariation (none/slight/medium/strong, default medium)
		$full["angleVariation"] = $params["angleVariation"] ?? "medium";
		if (!in_array($full["angleVariation"], ["none", "slight", "medium", "strong"], true)) {
			$this->warnings[] = "Invalid 'angleVariation' parameter, defaulting to 'medium'.";
			$full["angleVariation"] = "medium";
		}
		// bgColor
		$full["bgColor"] = Color::Parse($params["bgColor"] ?? "#f3f3f3");
		// capture (boolean, default false)
		$full["capture"] = isset($params["capture"]) ? $params["capture"] === true : false;
		// caseInsensitive (boolean, default false)
		$full["caseInsensitive"] = isset($params["caseInsensitive"]) ? $params["caseInsensitive"] === true : false;
		// complexity (simple/complex, default simple)
		$full["complexity"] = $params["complexity"] ?? "simple";
		if (!isset($params["complexity"]) || !in_array($params["complexity"], ["simple", "complex"])) {
			if (isset($params["complexity"])) {
				$this->warnings[] = "Invalid 'complexity' parameter, defaulting to 'simple'.";
			}
			$full["complexity"] = "simple";
		}
		// custom (array)
		if (isset($params["custom"]) && !is_array($params["custom"])) {
			$this->warnings[] = "Invalid entry 'custom' parameter, defaulting to empty array.";
			$params["custom"] = [];
		}
		$custom = isset($params["custom"]) ? $params["custom"] : [];
		$full["custom"] = [];
		$pos = 1;
		foreach ($custom as $pair) {
			if (!isset($pair["challenge"]) || !isset($pair["response"])) {
				$this->warnings[] = "Invalid entry in 'custom' parameter (position {$pos})";
				$pos++;
			}
			else {
				$full["custom"][] = [
					"challenge" => $pair["challenge"],
					"response" => $pair["response"]
				];
			}
		}
		// length (3-10, default 6)
		$full["length"] = min(self::IMAGE_MAX_LENGTH, max(intval($params["length"] ?? self::IMAGE_DEFAULT_LENGTH), self::IMAGE_MIN_LENGTH));
		// maxValue (positive int, default 10)
		$full["maxValue"] = max(intval($params["maxValue"] ?? 10), 1);
		// minValue (positive int, default 1)
		$full["minValue"] = max(1, intval($params["minValue"] ?? 1));
		if ($full["minValue"] > $full["maxValue"]) {
			$this->warnings[] = "Minimum operand value must not exceed the maximum. Defaulting to 1 through 10.";
			$full["minValue"] = 1;
			$full["maxValue"] = 10;
		}
		// noiseColor
		$full["noiseColor"] = Color::Parse($params["noiseColor"] ?? "#333333");
		// noiseDensity (off/low/medium/high, default medium)
		$full["noiseDensity"] = $params["noiseDensity"] ?? "medium";
		if (!in_array($full["noiseDensity"], ["off", "low", "medium", "high"], true)) {
			$this->warnings[] = "Invalid 'noiseDensity' parameter, defaulting to 'medium'.";
			$full["noiseDensity"] = "medium";
		}
		// reuse (boolean, default false)
		$full["reuse"] = isset($params["reuse"]) ? $params["reuse"] === true : false;
		// sizeVariation (none/slight/medium/strong, default medium)
		$full["sizeVariation"] = $params["sizeVariation"] ?? "medium";
		if (!in_array($full["sizeVariation"], ["none", "slight", "medium", "strong"], true)) {
			$this->warnings[] = "Invalid 'sizeVariation' parameter, defaulting to 'medium'.";
			$full["sizeVariation"] = "medium";
		}
		// textColor
		$full["textColor"] = Color::Parse($params["textColor"] ?? "#800000");
		// type (none/math/image/custom, default math)
		if (!isset($params["type"]) || !in_array($params["type"], ["none", "math", "image", "custom"])) {
			$params["type"] = "math";
			$this->warnings[] = "Missing or invalid type parameter. Defaulting to \"math\".";
		}
		$full["type"] = $params["type"];
		if ($full["type"] == "custom" && empty($full["custom"])) {
			// Default to "math" when there are no custom challenges/responses defined
			$params["type"] = "math";
			$full["type"] = "math";
			$this->warnings[] = "No custom challenge-response pairs defined. Defaulting to \"math\" CAPTCHA.";
		}
		// showAsText (boolean, default false)
		$full["showAsText"] = isset($params["showAsText"]) ? $params["showAsText"] === true : false;
		// Return validated / default parameters
		if ($set_defaults) {
			return $full;
		}
		else {
			foreach ($full as $key => $value) {
				if ($key == "debug" || isset($params[$key])) {
					$validated[$key] = $value;
				}
			}
			return $validated;
		}
	}

	private function areSolvedSessionsAllowed() {
		return $this->getProjectSetting("allow-sessions") == true;
	}

	private function getSolvedSessionTimeoutMinutes() {
		$value = trim("{$this->getProjectSetting("session-timeout-minutes")}");
		if ($value === "") return self::SESSION_DEFAULT_TIMEOUT_MINUTES;
		if (!preg_match('/^\d+$/', $value)) return self::SESSION_DEFAULT_TIMEOUT_MINUTES;
		$minutes = intval($value);
		if ($minutes === 0) return 0;
		return min($minutes, self::SESSION_HARD_MAX_MINUTES);
	}

	private function validateSolvedSession($project_id, $public_survey_hash) {
		if (!$this->areSolvedSessionsAllowed()) return false;

		$cookie_name = $this->getSolvedSessionCookieName($project_id);
		$raw_token = $_COOKIE[$cookie_name] ?? "";
		$token = $this->sanitizeSolvedSessionToken($raw_token);
		if ($token === null) {
			if ($raw_token !== "") $this->clearSolvedSessionCookie($project_id);
			return false;
		}

		$store_key = $this->getSolvedSessionStoreKey($project_id, $token);
		$result = $this->queryLogs("SELECT project_id, publicSurveyHash, createdAt, lastSeen, expiresAt WHERE message = ?", $store_key);
		$session = $result->fetch_assoc();
		if ($session == null) {
			$this->clearSolvedSessionCookie($project_id);
			return false;
		}

		$now = time();
		$created_at = intval($session["createdAt"] ?? 0);
		$last_seen = intval($session["lastSeen"] ?? 0);
		$expires_at = intval($session["expiresAt"] ?? 0);
		$is_valid =
			intval($session["project_id"] ?? 0) === intval($project_id) &&
			($session["publicSurveyHash"] ?? "") === $public_survey_hash &&
			$created_at > 0 &&
			$last_seen > 0 &&
			$expires_at > 0 &&
			$now <= $expires_at &&
			($now - $last_seen) <= self::SESSION_IDLE_MAX_MINUTES * 60 &&
			($now - $created_at) <= self::SESSION_HARD_MAX_MINUTES * 60;

		if (!$is_valid) {
			$this->framework->removeLogs("message = ?", $store_key);
			$this->clearSolvedSessionCookie($project_id);
			return false;
		}

		$session["lastSeen"] = $now;
		$this->framework->removeLogs("message = ?", $store_key);
		$this->framework->log($store_key, $session);
		return true;
	}

	private function createSolvedSession($project_id, $public_survey_hash) {
		if (!$this->areSolvedSessionsAllowed()) return;

		$token = bin2hex(random_bytes(32));
		$now = time();
		$timeout = $this->getSolvedSessionTimeoutMinutes();
		$expires_at = $now + (($timeout > 0 ? $timeout : self::SESSION_HARD_MAX_MINUTES) * 60);
		$expires_at = min($expires_at, $now + self::SESSION_HARD_MAX_MINUTES * 60);

		$store_key = $this->getSolvedSessionStoreKey($project_id, $token);
		$session = [
			"project_id" => intval($project_id),
			"publicSurveyHash" => $public_survey_hash,
			"createdAt" => $now,
			"lastSeen" => $now,
			"expiresAt" => $expires_at,
		];
		$this->framework->removeLogs("message = ?", $store_key);
		$this->framework->log($store_key, $session);
		$this->setSolvedSessionCookie($project_id, $token, $expires_at);
	}

	private function getSolvedSessionCookieName($project_id) {
		return self::SESSION_COOKIE_PREFIX . intval($project_id);
	}

	private function getSolvedSessionStoreKey($project_id, $token) {
		return self::SESSION_STORE_KEY . intval($project_id) . "_" . $token;
	}

	private function sanitizeSolvedSessionToken($token) {
		if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) return null;
		return $token;
	}

	private function setSolvedSessionCookie($project_id, $token, $expires_at) {
		if (headers_sent()) return;
		$_COOKIE[$this->getSolvedSessionCookieName($project_id)] = $token;
		$this->setCookieCompat($this->getSolvedSessionCookieName($project_id), $token, $expires_at);
	}

	private function clearSolvedSessionCookie($project_id) {
		if (headers_sent()) return;
		unset($_COOKIE[$this->getSolvedSessionCookieName($project_id)]);
		$this->setCookieCompat($this->getSolvedSessionCookieName($project_id), "", time() - 3600);
	}

	private function setCookieCompat($name, $value, $expires_at) {
		$options = [
			"expires" => $expires_at,
			"path" => $this->getSolvedSessionCookiePath(),
			"secure" => $this->isHttpsRequest(),
			"httponly" => true,
			"samesite" => "Lax",
		];
		if (PHP_VERSION_ID >= 70300) {
			setcookie($name, $value, $options);
		}
		else {
			setcookie($name, $value, $options["expires"], $options["path"] . "; SameSite=Lax", "", $options["secure"], $options["httponly"]);
		}
	}

	private function getSolvedSessionCookiePath() {
		$path = defined("APP_PATH_SURVEY") ? parse_url(APP_PATH_SURVEY, PHP_URL_PATH) : "/";
		if (!is_string($path) || $path === "") return "/";
		if ($path[0] !== "/") $path = "/$path";
		return rtrim($path, "/") . "/";
	}

	private function isHttpsRequest() {
		return (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ||
			(($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "") === "https");
	}

	private function inject_online_designer($project_id, $instrument) {
		if ($this->getConfigurationEditError($project_id, new \Project($project_id)) !== null) return;
		// Check for action tags
		$tagged = ActionTagHelper::getActionTags($project_id, [self::AT_SETUP], null, [$instrument], null, true)[self::AT_SETUP] ?? [];

		if (count($tagged) == 0) return;

		$ih = InjectionHelper::init($this);
		$ih->css("css/nedCAPTCHA2.css");
		$ih->js("js/nedCAPTCHA2.js");
		$this->framework->initializeJavascriptModuleObject();
		$jsmo = $this->framework->getJavascriptModuleObjectName();
		$config = [
			"debug" => $this->getProjectSetting("debug") == true,
			"version" => $this->VERSION,
			"tagged" => array_keys($tagged),
			"at" => self::AT_SETUP,
			"linkTitle" => "Edit CAPTCHA settings",
			"updateLabel" => "Update"
		];
		print \RCView::script("DE_RUB_SEG_nedCAPTCHA2.init(".
			json_encode($config).", $jsmo);");
	}

	#endregion
}

spl_autoload_register(function ($class) {
	$prefix = __NAMESPACE__ . "\\";
	$prefix_length = strlen($prefix);

	if (strncmp($prefix, $class, $prefix_length) !== 0) {
		return;
	}

	$relative_class = substr($class, $prefix_length);
	$file = __DIR__ . "/classes/" . str_replace("\\", DIRECTORY_SEPARATOR, $relative_class) . ".php";

	if (is_file($file)) {
		require_once $file;
	}
});
