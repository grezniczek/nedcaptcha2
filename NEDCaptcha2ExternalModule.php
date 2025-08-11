<?php namespace DE\ELISABETHGRUPPE\NEDCaptcha2ExternalModule;

use ExternalModules\AbstractExternalModule;

require_once "classes/Color.php";
require_once "classes/CaptchaGenerator.php";

class NEDCaptcha2ExternalModule extends AbstractExternalModule {
	
	const CLIENT_KEY = "__nedcaptcha2__ck__";
	const STORE_KEY = "__nedcaptcha2__";

	const AT_SETUP = "@NEDCAPTCHA";
	const AT_INSTRUCTIONS = "@NEDCAPTCHA-INSTRUCTIONS";
	const AT_FAILMESSAGE = "@NEDCAPTCHA-FAILMESSAGE";
	const AT_DISPLAY = "@NEDCAPTCHA-DISPLAY";
	const AT_CUSTOMCHALLENGE = "@NEDCAPTCHA-CUSTOM-CHALLENGE";

	const IMAGE_MIN_LENGTH = 3;
	const IMAGE_MAX_LENGTH = 8;
	const IMAGE_DEFAULT_LENGTH = 5;

	/** @var int The number of minutes a CAPTCHA is valid for */
	const CAPTCHA_EXPIRATION = 120; 

	private $nedcaptcha_fields = [];
	private $scripts_delayed = [];
	private $scripts_regular = [];
	private $scripts_top = [];
	private $styles = [];
	private $errors = [];
	private $warnings = [];
	private $active = false;

	#region Hooks

	function redcap_every_page_before_render($project_id) {

		// Only operate on a fresh public survey page
		$page = defined("PAGE") ? PAGE : "";
		if ($page != "surveys/index.php") return;
		$psh = $this->framework->getPublicSurveyHash($project_id);
		$sh = $_GET["s"] ?? "";

		// $post = $_POST;
		// $get = $_GET;

		// Perform various checks in order to determine if anything should be done
		// (i.e., act only on the first page of a public survey)
		if (!empty($_POST) && !(isset($_POST['__nedcaptcha2__ck__']) || isset($_POST["__page__"]))) {
			if (isset($_POST["__page__"])) {
				// Verify page hash
				if (\Survey::verifyPageNumHash($_POST['__page_hash__'], $_POST['__page__'])) {
					return;
				}
			}
			else {
				return;
			}
		}
		// Additional checks, just to be safe (some may be redundant to the code above)
		// Stop here if there is no survey hash (includes, e.g., the close window page)
		if ($sh == "") return;
		// Do not interfere with returning or passthru requests
		if ($_SERVER['REQUEST_METHOD'] == 'GET' && $_GET["__return"] == "1") return;
		if (isset($_GET["__passthru"])) return;
		if (isset($_GET["__endpublicsurvey"])) {
			// Validate response hash
			$response_id = \Survey::decryptResponseHash($_GET['__rh'], $GLOBALS['participant_id']);
			if ($response_id != "") return;
		} 
		$returning = $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST["__code"]);
		// Do not interfere when submitting first page of a survey
		if ($sh == $psh && isset($_POST["__start_time__"])) return;

		// Load previous state (if any)
		$client_key = isset($_POST[self::CLIENT_KEY]) 
			? $_POST[self::CLIENT_KEY] : \Crypto::getGuid();
		$store_key = self::STORE_KEY."{$psh}_{$client_key}";
		$result = $this->queryLogs("SELECT expected, passed WHERE message = ?", $store_key);
		$stored = $result->fetch_assoc() ?? [ "expected" => null, "passed" => false ];

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
		list($page_fields, $total_pages) = \Survey::getPageFields($instrument, true);
		
		// Check for action tags
		require_once "classes/ActionTagHelper.php";
		$tagged = ActionTagHelper::getActionTags($project_id, [
			self::AT_SETUP, 
			self::AT_INSTRUCTIONS, 
			self::AT_FAILMESSAGE,
			self::AT_DISPLAY,
			self::AT_CUSTOMCHALLENGE,
		], $page_fields[1], null, $context) ?? [];
		$this->nedcaptcha_fields = array_filter([
			array_keys($tagged[self::AT_SETUP] ?? [""])[0], 
			array_keys($tagged[self::AT_INSTRUCTIONS] ?? [""])[0], 
			array_keys($tagged[self::AT_FAILMESSAGE] ?? [""])[0],
			...array_keys($tagged[self::AT_DISPLAY] ?? [""]),
			array_keys($tagged[self::AT_CUSTOMCHALLENGE] ?? [""])[0],
		], function($v) { return !empty($v); });

		if ($returning || $stored["passed"] || $psh != $sh || !isset($tagged[self::AT_SETUP])) {
			// We are outside any context where the CAPTCHA should be shown
			// Remove the fields
			foreach ($this->nedcaptcha_fields as $field) {
				unset($Proj->metadata[$field]);
				unset($Proj->forms[$instrument]['fields'][$field]);
			}
			// We are done
			return;
		}

		// Validate parameters
		$captcha_field = array_keys($tagged[self::AT_SETUP])[0];
		$params = $this->validate_params($tagged[self::AT_SETUP][$captcha_field]["params"], true);
		// Some debug logging
		if ($params["debug"]) {
			$this->scripts_top[] = "console.log('nedCAPTCHA 2: Parameters:',".json_encode(array_merge($params, ["custom" => "[REDCATED]"])).");";
		}
		if ($params["type"] == "none") {
			$this->scripts_top[] = "console.log('nedCAPTCHA 2: No CAPTCHA required.');";
			foreach ($this->nedcaptcha_fields as $field) {
				unset($Proj->metadata[$field]);
				unset($Proj->forms[$instrument]['fields'][$field]);
			}
			return;
		}
		$this->active = true;

		// Validate and/or generate the CAPTCHA
		// Get response
		$response = isset($_POST[$captcha_field]) ? trim("{$_POST[$captcha_field]}") : "";
		$expected = $stored["expected"];
		if ($params["type"] == "custom") {
			if ($params["caseInsensitive"]) {
				$response = mb_strtolower($response);
				$expected = mb_strtolower($expected);
			}
		}
		else {
			$response = strtolower($response);
		}
		// Check response
		$passed = $stored["passed"] || 
			($expected != null && $expected == $response);

		// Reset expected?
		$expected = ($passed || $params["reuse"]) ? $stored["expected"] : null;

		$captcha = new CaptchaGenerator($params, $expected);
		$stored = [
			"expected" => $captcha->expected,
			"passed" => $passed,
		];
		$this->framework->removeLogs("message = ?", $store_key);
		$this->framework->log($store_key, $stored);

		if ($passed) {
			// CAPTCHA was solved
			foreach ($this->nedcaptcha_fields as $field) {
				unset($Proj->metadata[$field]);
				unset($Proj->forms[$instrument]['fields'][$field]);
			}
			// Trick REDCap into showing the survey instructions
			$_SERVER["REQUEST_METHOD"] = "GET";
			// Clear all warnings
			$this->warnings = [];
			if ($params["capture"]) {
				// Capture the response
				$this->scripts_regular[] = "$('form#form').append($('<input name=\"{$captcha_field}\" value=\"{$response}\" type=\"hidden\">'));";
			}
			if ($params["debug"]) {
				$this->scripts_top[] = "console.log('nedCAPTCHA 2: CAPTCHA passed.');";
				if ($params["capture"]) {
					$this->scripts_top[] = "console.log('nedCAPTCHA 2: Response captured: {$response}');";
				}
			}
			return;
		}

		// Add client key to form
		$this->scripts_delayed[] = "$('input[name=\"redcap_csrf_token\"]').after($('<input type=\"hidden\" name=\"".self::CLIENT_KEY."\" value=\"$client_key\">'));";

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
			// Apply @NEDCAPTCHA-CUSTOM-CHALLENGE action tag
			foreach ($tagged[self::AT_CUSTOMCHALLENGE] as $this_field => $_) {
				if (in_array($this_field, $this->nedcaptcha_fields, true)) {
					$this->scripts_delayed[] = "$('input[name=\"{$this_field}\"]').val(".json_encode($captcha->challenge)."); doBranching('$this_field');";
				}
			}
		}
		// Add the CAPTCHA to the label and then move it in front of the input
		$Proj->metadata[$captcha_field]["element_label"] .= 
			"<div class=\"nedcaptcha2-challenge\" data-target=\"$captcha_field\">{$captcha->challenge}</div>";
		$this->scripts_regular[] = "$('input[name=\"{$captcha_field}\"]').before($('div.nedcaptcha2-challenge[data-target=\"{$captcha_field}\"]'));";

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

		#region get-params
		if ($action == "get-params") {
			$field = $payload["field"];

			require_once "classes/ActionTagHelper.php";
			$tagged = ActionTagHelper::getActionTags($project_id, [self::AT_SETUP], $field, null, null, true)[self::AT_SETUP] ?? [];

			if (count($tagged) == 0) return "ERROR";

			$params = $this->validate_params($tagged[$field]["params"], false);
			unset($params["debug"]);
			$defaults = $this->validate_params($tagged[$field]["params"], true);
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
			require_once "setup-ui.php";
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
			require_once "classes/ActionTagHelper.php";
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
			$misc_current = $Proj->getMetadata()[$field]["misc"];
			// Replace
			$pattern = '/^' . preg_quote(self::AT_SETUP, '/') . '\s*=\s*' . preg_quote($tagged[$field]["params"], '/') . '/m';
			$replacement = self::AT_SETUP."=".json_encode($params, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
			$misc_new = preg_replace($pattern, $replacement, $misc_current);
			// Store
			$table_name = $Proj->isDraftMode() ? "redcap_metadata_draft" : "redcap_metadata";
			$sql = "UPDATE $table_name SET misc = ? WHERE field_name = ? AND project_id = ?";
			$q = db_query($sql, [$misc_new, $field, $project_id]);
			if ($q) {
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
	}

	#endregion

	#region Private Methods

	/**
	 * Validate parameters
	 * @param mixed $params 
	 * @param bool $set_defaults 
	 * @return array 
	 */
	public function validate_params($params, $set_defaults = false) {
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

	private function inject_online_designer($project_id, $instrument) {
		// Check for action tags
		require_once "classes/ActionTagHelper.php";
		$tagged = ActionTagHelper::getActionTags($project_id, [self::AT_SETUP], null, [$instrument], null, true)[self::AT_SETUP] ?? [];

		if (count($tagged) == 0) return;

		require_once "classes/InjectionHelper.php";
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
		print \RCView::script("DE_ELISABETHGRUPPE_nedCAPTCHA2.init(".
			json_encode($config).", $jsmo);");
	}

	#endregion
}
