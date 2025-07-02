<?php namespace DE\ELISABETHGRUPPE\NEDCaptcha2ExternalModule;

require_once "classes/InjectionHelper.php";
$ih = InjectionHelper::init($module);
$ih->css("css/nedCAPTCHA2.css");

$m = $module;
?>
<div class="nedCAPTCHA-setup-container">
	<div class="nedCAPTCHA-setup-main">
		Type, Common
	</div>
	<div class="nedCAPTCHA-setup-sidebar">
		<div class="nedCAPTCHA-setup-custom">
			Custom
		</div>
		<div class="nedCAPTCHA-setup-math">
			Math
		</div>
		<div class="nedCAPTCHA-setup-image">
			Image
		</div>
	</div>

</div>