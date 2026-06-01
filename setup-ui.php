<?php namespace DE\RUB\SEG\NEDCaptcha2ExternalModule;

$is_plugin = !isset($defaults) || !isset($defaults["type"]);
if ($is_plugin) {
	require_once "classes/InjectionHelper.php";
	$ih = InjectionHelper::init($module);
	$ih->css("css/nedCAPTCHA2.css");
	$defaults = $module->validate_params("{}", true);
	$custom_pairs = "";
	foreach ($defaults["custom"] ?? [] as $pair) {
		$custom_pairs .= $pair["challenge"] . "=" . $pair["response"] . "\n";
	}
}


?>
<div class="nedCAPTCHA-setup-container">
	<div class="nedCAPTCHA-setup-main">
		<div class="nedCAPTCHA-setup-title">CAPTCHA Type</div>
		<div class="nedCAPTCHA-setup-group">
			<div class="form-check">
				<input class="form-check-input" type="radio" name="type" id="type-math" value="math" <?=$defaults["type"] == "math" ? "checked" : ""?>>
				<label class="form-check-label" for="type-math">
					Math Problem
				</label>
			</div>
			<div class="form-check">
				<input class="form-check-input" type="radio" name="type" id="type-image" value="image" <?=$defaults["type"] == "image" ? "checked" : ""?>>
				<label class="form-check-label" for="type-image">
					Distorted Image
				</label>
			</div>
			<div class="form-check">
				<input class="form-check-input" type="radio" name="type" id="type-custom" value="custom" <?=$defaults["type"] == "custom" ? "checked" : ""?>>
				<label class="form-check-label" for="type-custom">
					Custom Challenge/Response
				</label>
			</div>
			<div class="form-check mt-2">
				<input class="form-check-input" type="radio" name="type" id="type-none" value="none" <?=$defaults["type"] == "none" ? "checked" : ""?>>
				<label class="form-check-label" for="type-none">
					Disabled
				</label>
			</div>
		</div>
		<div class="nedCAPTCHA-setup-title">Common Options</div>
		<div class="nedCAPTCHA-setup-group">
			<div class="form-check mt-2">
				<input class="form-check-input" type="checkbox" name="capture" id="nedCAPTCHA-capture" <?=$defaults["capture"] ? "checked" : ""?>>
				<label class="form-check-label mb-1" for="nedCAPTCHA-capture">
					Capture response
				</label>
				<div class="nedCAPTCHA-setup-description">
					By default, responses are not captured. With this option, responses are captured into the field with the <code>@NEDCAPTCHA</code> action tag.
				</div>
			</div>
		</div>
	</div>
	<div class="nedCAPTCHA-setup-sidebar">
		<!-- Math Problem -->
		<div class="nedCAPTCHA-setup-math">
		<div class="nedCAPTCHA-setup-title">Math Problem Options</div>
			<ul class="nedCAPTCHA-setup-section">
				<li class="nedCAPTCHA-setup-setting">
					<div id="nedCAPTCHA-complexity">
						Complexity
						<div class="nedCAPTCHA-setup-description">Simple problems will be limited to additions, while complex problems include two of addition, subtraction, and multiplication (however, there will never be two multiplications, as results could get quite large).</div>
					</div>
					<div class="d-flex gap-2">
						<div class="form-check">
							<input class="form-check-input" type="radio" name="complexity" id="complexity1" value="simple" <?=$defaults["complexity"] == "simple" ? "checked" : ""?>>
							<label class="form-check-label" for="complexity1">
								Simple
							</label>
						</div>
						<div class="form-check">
							<input class="form-check-input" type="radio" name="complexity" id="complexity2" value="complex" <?=$defaults["complexity"] == "complex" ? "checked" : ""?>>
							<label class="form-check-label" for="complexity2">
								Complex
							</label>
						</div>
					</div>

				</li>
				<li class="nedCAPTCHA-setup-setting">
					Min/Max Operand Values
					<div class="nedCAPTCHA-setup-description">By default, the range of operand values is 1 to 10. For complex problems, it might be useful to set higher limits for min and max operand values to reduce the likelihood of negative results when subtractions are involved or to eliminate the possibility of a trivial multiplication by 1.</div>
					<div class="d-flex gap-2 align-items-center">
						<label for="nedCAPTCHA-minValue" class="form-text mb-0 mt-0">Min</label>
						<input id="nedCAPTCHA-minValue" type="number" name="minValue" class="form-control form-control-sm" value="<?= $defaults["minValue"] ?? 1 ?>" min="1">
						<label for="nedCAPTCHA-maxValue" class="form-text mb-0 mt-0">Max</label>
						<input id="nedCAPTCHA-maxValue" type="number" name="maxValue" class="form-control form-control-sm" value="<?= $defaults["maxValue"] ?? 10 ?>" min="1">
					</div>
				</li>
				<li class="nedCAPTCHA-setup-setting">
					<div id="nedCAPTCHA-bgColor-math">
						Background Color
					</div>
					<div class="nedCAPTCHA-setup-range">
						<input aria-labelledby="nedCAPTCHA-bgColor-math" type="color" name="bgColor" class="form-control form-control-sm form-control-color text-output" value="<?=$defaults["bgColor"]->getHex()?>">
						<output aria-hidden="true"></output>
					</div>
				</li>
				<li class="nedCAPTCHA-setup-setting">
					<div id="nedCAPTCHA-textColor-math">
						Text Color
					</div>
					<div class="nedCAPTCHA-setup-range">
						<input aria-labelledby="nedCAPTCHA-textColor-math" type="color" name="textColor" class="form-control form-control-sm form-control-color text-output" value="<?=$defaults["textColor"]->getHex()?>">
						<output aria-hidden="true"></output>
					</div>
				</li>
				<li class="nedCAPTCHA-setup-setting">
					Preview<br>
					<svg id="nedCAPTCHA-preview-math" class="nedCAPTCHA-preview" width="100" height="30" xmlns="http://www.w3.org/2000/svg">
						<style>
							:root {
								--bgColor: #92d050;
								--textColor: #ffffff;
							}
							rect {
								fill: var(--bgColor);
							}
							text.math {
								fill: var(--textColor);
								font-size: 16px;
								font-family: sans-serif;
								dominant-baseline: middle;
								text-anchor: middle;
							}
						</style>
						<!-- Background rectangle -->
						<rect x="0" y="0" width="100" height="30" />
						<!-- Centered Text -->
						<text class="math" x="50" y="17">(8 + 2) * 3</text>
					</svg>
				</li>
			</ul>
			<div class="form-check mt-2">
				<input class="form-check-input" type="checkbox" name="showAsText" id="nedCAPTCHA-showAsText" <?=$defaults["showAsText"] ? "checked" : ""?>>
				<label class="form-check-label mb-1" for="nedCAPTCHA-showAsText">
					Show as text (instead of rendering an image)
				</label>
				<div class="nedCAPTCHA-setup-description">
					By default, math problems are rendered as images to make it harder for bots to beat the CAPTCHA. With this option, the math problem will be rendered as text. This may be useful in order to support persons relying on screen readers to be able to complete the CAPTCHA.
				</div>
			</div>
		</div>
		<!-- Custom -->
		<div class="nedCAPTCHA-setup-custom">
			<div class="nedCAPTCHA-setup-title">Custom Challenge/Response Options</div>
			<div class="nedCAPTCHA-setup-group">
				<div class="nedCAPTCHA-setup-description">
					Enter pairs of challenges (displayed to the survey respondent) and responses (the expected answers), separated by the equal signs (=), one pair per line. For the CAPTCHA, a random pair will be chosen.
				</div>
				<div class="mt-2">
					<textarea id="nedCAPTCHA-custom" name="custom" class="form-control form-control-sm" placeholder="Challenge = Response" rows="20"><?=$custom_pairs?></textarea>
				</div>
				<div class="form-check mt-2">
					<input class="form-check-input" type="checkbox" name="caseInsensitive" id="nedCAPTCHA-caseInsensitive" <?=$defaults["caseInsensitive"] ? "checked" : ""?>>
					<label class="form-check-label" for="nedCAPTCHA-caseInsensitive">
						Use case-insensitive comparison for responses
					</label>
				</div>
			</div>
		</div>
		<!-- Distorted Image -->
		<div class="nedCAPTCHA-setup-image">
			<div class="nedCAPTCHA-setup-title">Distorted Image Options</div>
			<ul class="nedCAPTCHA-setup-section">
				<li class="nedCAPTCHA-setup-setting">
					<div id="nedCAPTCHA-length">
						Length
						<div class="nedCAPTCHA-setup-description">The number of characters shown (<?=NEDCaptcha2ExternalModule::IMAGE_MIN_LENGTH?> to <?=NEDCaptcha2ExternalModule::IMAGE_MAX_LENGTH?>).</div>
					</div>
					<div class="nedCAPTCHA-setup-range">
						<input aria-labelledby="nedCAPTCHA-length" name="length" type="range" class="form-range text-output" min="<?=NEDCaptcha2ExternalModule::IMAGE_MIN_LENGTH?>" max="<?=NEDCaptcha2ExternalModule::IMAGE_MAX_LENGTH?>" value="<?=$defaults["length"]?>">
						<output aria-hidden="true"></output>
					</div>
				</li>
				<li class="nedCAPTCHA-setup-setting">
					<div class="form-check">
						<input class="form-check-input" type="checkbox" name="reuse" id="nedCAPTCHA-reuse" <?=$defaults["reuse"] ? "checked" : ""?>>
						<label class="form-check-label mb-0" for="nedCAPTCHA-reuse">Re-use challenge</label>
					</div>
					<div class="nedCAPTCHA-setup-description">By default, a new challenge is created after an unsuccessful try. By enabling this option, the same challenge is reused for the next try.</div>
				</li>
				<li class="nedCAPTCHA-setup-setting">
					<div id="nedCAPTCHA-angleVariation">
						Angle Variation
						<div class="nedCAPTCHA-setup-description">The amount of random rotation applied to individual characters.</div>
					</div>
					<select name="angleVariation" class="form-select form-select-sm" aria-labelledby="nedCAPTCHA-angleVariation">
						<option value="none" <?=$defaults["angleVariation"] == "none" ? "selected" : ""?>>None</option>
						<option value="slight" <?=$defaults["angleVariation"] == "slight" ? "selected" : ""?>>Slight, up to ±7°</option>
						<option value="medium" <?=$defaults["angleVariation"] == "medium" ? "selected" : ""?>>Medium, up to ±11°</option>
						<option value="strong" <?=$defaults["angleVariation"] == "strong" ? "selected" : ""?>>Strong, up to ±15°</option>
					</select>
				</li>
				<li class="nedCAPTCHA-setup-setting">
					<div id="nedCAPTCHA-sizeVariation">
						Size Variation
						<div class="nedCAPTCHA-setup-description">The amount of random scaling applied to individual characters.</div>
					</div>
					<select name="sizeVariation" class="form-select form-select-sm" aria-labelledby="nedCAPTCHA-sizeVariation">
						<option value="none" <?=$defaults["sizeVariation"] == "none" ? "selected" : ""?>>None</option>
						<option value="slight" <?=$defaults["sizeVariation"] == "slight" ? "selected" : ""?>>Slight, up to ±10%</option>
						<option value="medium" <?=$defaults["sizeVariation"] == "medium" ? "selected" : ""?>>Medium, up to ±20%</option>
						<option value="strong" <?=$defaults["sizeVariation"] == "strong" ? "selected" : ""?>>Strong, up to ±30%</option>
					</select>
				</li>
				<li class="nedCAPTCHA-setup-setting">
					<div id="nedCAPTCHA-noiseDensity">
						Noise Density
						<div class="nedCAPTCHA-setup-description">The amount of noise that is added to the image.</div>
					</div>
					<select name="noiseDensity" class="form-select form-select-sm" aria-labelledby="nedCAPTCHA-noiseDensity">
						<option value="off" <?=$defaults["noiseDensity"] == "off" ? "selected" : ""?>>Off (no noise)</option>
						<option value="low" <?=$defaults["noiseDensity"] == "low" ? "selected" : ""?>>Low (60% of the default amount)</option>
						<option value="medium" <?=$defaults["noiseDensity"] == "medium" ? "selected" : ""?>>Medium (the default amount)</option>
						<option value="high" <?=$defaults["noiseDensity"] == "high" ? "selected" : ""?>>High (150% of the default amount)</option>
					</select>
				</li>
				<li class="nedCAPTCHA-setup-setting">
					<div id="nedCAPTCHA-bgColor-image">
						Background Color
					</div>
					<div class="nedCAPTCHA-setup-range">
						<input aria-labelledby="nedCAPTCHA-bgColor-image" type="color" name="bgColor" class="form-control form-control-sm form-control-color text-output" value="<?=$defaults["bgColor"]->getHex()?>">
						<output aria-hidden="true"></output>
					</div>
				</li>
				<li class="nedCAPTCHA-setup-setting">
					<div id="nedCAPTCHA-noiseColor-image">
						Noise Color
					</div>
					<div class="nedCAPTCHA-setup-range">
						<input aria-labelledby="nedCAPTCHA-noiseColor-image" type="color" name="noiseColor" class="form-control form-control-sm form-control-color text-output" value="<?=$defaults["noiseColor"]->getHex()?>">
						<output aria-hidden="true"></output>
					</div>
				</li>
				<li class="nedCAPTCHA-setup-setting">
					<div id="nedCAPTCHA-textColor-image">
						Text Color
					</div>
					<div class="nedCAPTCHA-setup-range">
						<input aria-labelledby="nedCAPTCHA-textColor-image" type="color" name="textColor" class="form-control form-control-sm form-control-color text-output" value="<?=$defaults["textColor"]->getHex()?>">
						<output aria-hidden="true"></output>
					</div>
				</li>
				<li class="nedCAPTCHA-setup-setting">
					Preview<br>
					<svg id="nedCAPTCHA-preview-image" class="nedCAPTCHA-preview" width="150" height="50" xmlns="http://www.w3.org/2000/svg">
						<style>
							:root {
								--bgColor: #92d050;
								--noiseColor: #17365c;
								--textColor: #ffffff;
							}
							rect {
								fill: var(--bgColor);
							}
							line {
								stroke: var(--noiseColor);
								stroke-width: 1;
							}
							text.image {
								fill: var(--textColor);
								font-size: 35px;
								font-family: sans-serif;
								dominant-baseline: middle;
								text-anchor: middle;
							}
						</style>
						<!-- Background rectangle -->
						<rect x="0" y="0" width="150" height="50" />
						<!-- Random lines -->
						<line x1="10" y1="5" x2="140" y2="10" />
						<line x1="20" y1="45" x2="50" y2="5" />
						<line x1="5" y1="25" x2="145" y2="30" />
						<line x1="0" y1="0" x2="150" y2="50" />
						<line x1="75" y1="0" x2="35" y2="50" />
						<line x1="30" y1="0" x2="60" y2="50" />
						<line x1="90" y1="10" x2="120" y2="40" />
						<line x1="15" y1="10" x2="135" y2="45" />
						<line x1="0" y1="25" x2="150" y2="25" />
						<line x1="45" y1="5" x2="60" y2="45" />
						<line x1="10" y1="40" x2="130" y2="5" />
						<line x1="70" y1="5" x2="90" y2="45" />
						<line x1="25" y1="10" x2="95" y2="35" />
						<line x1="35" y1="15" x2="145" y2="20" />
						<line x1="55" y1="0" x2="95" y2="50" />
						<line x1="100" y1="0" x2="130" y2="50" />
						<line x1="0" y1="40" x2="70" y2="10" />
						<line x1="80" y1="5" x2="20" y2="45" />
						<line x1="60" y1="15" x2="140" y2="35" />
						<line x1="5" y1="35" x2="100" y2="20" />
						<!-- Centered Text -->
						<text class="image" x="75" y="25">x 5 c q a</text>
					</svg>
				</li>
			</ul>
		</div>
	</div>
</div>
<?php if (!$is_plugin) return; ?>
<script type="text/javascript">
	// Outputs
	$('.nedCAPTCHA-setup-container input.text-output')
		.on('input', function() {
			$(this).next('output').text(this.value);
		}).trigger('input');
	// Update preview
	$('.nedCAPTCHA-setup-container input.form-control-color').on('input change', function() {
		const name = $(this).attr('name');
		const varname = '--' + name;
		$('svg.nedCAPTCHA-preview').css(varname, $(this).val());
	}).trigger('change');
</script>
<style>
	.nedCAPTCHA-setup-container {
		border: 1px dotted #ccc;
		max-width: 800px;
	}
</style>
