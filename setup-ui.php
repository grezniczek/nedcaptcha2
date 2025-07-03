<?php

namespace DE\ELISABETHGRUPPE\NEDCaptcha2ExternalModule;

require_once "classes/InjectionHelper.php";
$ih = InjectionHelper::init($module);
$ih->css("css/nedCAPTCHA2.css");

$m = $module;
?>
<div class="nedCAPTCHA-setup-container">
	<div class="nedCAPTCHA-setup-main">
		<div class="nedCAPTCHA-setup-title">CAPTCHA Type</div>
		<div class="nedCAPTCHA-setup-group">
			<div class="form-check">
				<input class="form-check-input" type="radio" name="type" id="type2" value="math">
				<label class="form-check-label" for="type2">
					Math Problem
				</label>
			</div>
			<div class="form-check">
				<input class="form-check-input" type="radio" name="type" id="type3" value="image">
				<label class="form-check-label" for="type3">
					Distorted Image
				</label>
			</div>
			<div class="form-check">
				<input class="form-check-input" type="radio" name="type" id="type4" value="custom">
				<label class="form-check-label" for="type4">
					Custom Challenge/Response
				</label>
			</div>
			<div class="form-check mt-2">
				<input class="form-check-input" type="radio" name="type" id="type1" value="none">
				<label class="form-check-label" for="type1">
					Disabled
				</label>
			</div>
		</div>

		Type, Common
	</div>
	<div class="nedCAPTCHA-setup-sidebar">
		<!-- Math Problem -->
		<div class="nedCAPTCHA-setup-math">
			<div class="nedCAPTCHA-setup-title">Math Problem Options</div>
			<ul class="nedCAPTCHA-setup-section">
				<li class="nedCAPTCHA-setup-setting">
					<div id="nedCAPTCHA-complexity">
						Complexity
						<div class="nedCAPTCHA-setup-description">Simple problems will be limited to additions, while complex problems include two of addition, subtraction, and multiplication (however, there will never be two multiplications, as results could get quite large.</div>
					</div>
					<div class="d-flex gap-2">
						<div class="form-check">
							<input class="form-check-input" type="radio" name="complexity" id="complexity1" value="math">
							<label class="form-check-label" for="complexity1">
								Simple
							</label>
						</div>
						<div class="form-check">
							<input class="form-check-input" type="radio" name="complexity" id="complexity2" value="image">
							<label class="form-check-label" for="complexity2">
								Complex
							</label>
						</div>
					</div>

				</li>
				<li class="nedCAPTCHA-setup-setting">
					<div id="nedCAPTCHA-sizeVariation">
						Size Variation
						<div class="nedCAPTCHA-setup-description">The amount of random scaling applied to individual characters.</div>
					</div>
					<select name="sizeVariation" class="form-select form-select-sm" aria-labelledby="nedCAPTCHA-sizeVariation">
						<option value="0">None</option>
						<option value="1">Slight, up to ±10%</option>
						<option value="2">Medium, up to ±20%</option>
						<option value="3">Strong, up to ±30%</option>
					</select>
				</li>
				<li class="nedCAPTCHA-setup-setting">
					<div id="nedCAPTCHA-bgColor-math">
						Background Color
					</div>
					<div class="nedCAPTCHA-setup-range">
						<input aria-labelledby="nedCAPTCHA-bgColor-math" type="color" name="bgColor" class="form-control form-control-sm form-control-color text-output" value="#000000">
						<output aria-hidden="true"></output>
					</div>
				</li>
				<li class="nedCAPTCHA-setup-setting">
					<div id="nedCAPTCHA-textColor-math">
						Text Color
					</div>
					<div class="nedCAPTCHA-setup-range">
						<input aria-labelledby="nedCAPTCHA-textColor-math" type="color" name="textColor" class="form-control form-control-sm form-control-color text-output" value="#000000">
						<output aria-hidden="true"></output>
					</div>
				</li>
				<li class="nedCAPTCHA-setup-setting">
					Preview<br>
					<svg id="nedCAPTCHA-preview-math" class="nedCAPTCHA-preview" width="150" height="50" xmlns="http://www.w3.org/2000/svg">
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
								font-size: 25px;
								font-family: sans-serif;
								dominant-baseline: middle;
								text-anchor: middle;
							}
						</style>
						<!-- Background rectangle -->
						<rect x="0" y="0" width="150" height="50" />
						<!-- Centered Text -->
						<text class="math" x="75" y="27">(8 + 2) * 3</text>
					</svg>
				</li>
			</ul>
			<div class="form-check mt-2">
				<input class="form-check-input" type="checkbox" id="nedCAPTCHA-caseInsensitive">
				<label class="form-check-label mb-1" for="nedCAPTCHA-caseInsensitive">
					Show as text (instead of rendering an image)
				</label>
				<div class="nedCAPTCHA-setup-description">
					By default, math problems are rendered as images to make it harder for bots to beat the CAPTCHA. With this option, the math problem will be rendered as text. This may be useful in order to support persons relying on screen readers to be able to complete the CAPTCHA.
				</div>
			</div>

		</div>
		<!-- Custom -->
		<div class="nedCAPTCHA-setup-custom" style="display: none;">
			<div class="nedCAPTCHA-setup-title">Custom Options</div>
			<div class="nedCAPTCHA-setup-description">
				Enter pairs of challenges (displayed to the survey respondent) and responses (the expected answers), separated by the equal signs (=), one pair per line. For the CAPTCHA, a random pair will be chosen.
			</div>
			<div class="mt-2">
				<textarea id="nedCAPTCHA-custom" class="form-control form-control-sm" placeholder="Challenge = Response" rows="15"></textarea>
			</div>
			<div class="form-check mt-2">
				<input class="form-check-input" type="checkbox" id="nedCAPTCHA-caseInsensitive">
				<label class="form-check-label" for="nedCAPTCHA-caseInsensitive">
					Responses are case-insensitve
				</label>
			</div>
		</div>
		<!-- Distorted Image -->
		<div class="nedCAPTCHA-setup-image" style="display: none;">
			<div class="nedCAPTCHA-setup-title">Distorted Image Options</div>
			<ul class="nedCAPTCHA-setup-section">
				<li class="nedCAPTCHA-setup-setting">
					<div id="nedCAPTCHA-length">
						Length
						<div class="nedCAPTCHA-setup-description">The number of characters shown (3 to 10).</div>
					</div>
					<div class="nedCAPTCHA-setup-range">
						<input aria-labelledby="nedCAPTCHA-length" type="range" class="form-range text-output" min="3" max="10">
						<output aria-hidden="true"></output>
					</div>
				</li>
				<li class="nedCAPTCHA-setup-setting">
					<div id="nedCAPTCHA-angleVariation">
						Angle Variation
						<div class="nedCAPTCHA-setup-description">The amount of random rotation applied to individual characters.</div>
					</div>
					<select name="angleVariation" class="form-select form-select-sm" aria-labelledby="nedCAPTCHA-angleVariation">
						<option value="0">None</option>
						<option value="1">Slight, up to ±7°</option>
						<option value="2">Medium, up to ±11°</option>
						<option value="3">Strong, up to ±15°</option>
					</select>
				</li>
				<li class="nedCAPTCHA-setup-setting">
					<div id="nedCAPTCHA-sizeVariation">
						Size Variation
						<div class="nedCAPTCHA-setup-description">The amount of random scaling applied to individual characters.</div>
					</div>
					<select name="sizeVariation" class="form-select form-select-sm" aria-labelledby="nedCAPTCHA-sizeVariation">
						<option value="0">None</option>
						<option value="1">Slight, up to ±10%</option>
						<option value="2">Medium, up to ±20%</option>
						<option value="3">Strong, up to ±30%</option>
					</select>
				</li>
				<li class="nedCAPTCHA-setup-setting">
					<div id="nedCAPTCHA-noiseDensity">
						Noise Density
						<div class="nedCAPTCHA-setup-description">The amount of noise that is added to the image.</div>
					</div>
					<select name="noiseDensity" class="form-select form-select-sm" aria-labelledby="nedCAPTCHA-noiseDensity">
						<option value="0">Off (no noise)</option>
						<option value="1">Low (60% of the default amount)</option>
						<option value="2">Medium (the default amount)</option>
						<option value="3">High (150% of the default amount)</option>
					</select>
				</li>
				<li class="nedCAPTCHA-setup-setting">
					<div id="nedCAPTCHA-bgColor-image">
						Background Color
					</div>
					<div class="nedCAPTCHA-setup-range">
						<input aria-labelledby="nedCAPTCHA-bgColor-image" type="color" name="bgColor" class="form-control form-control-sm form-control-color text-output" value="#000000">
						<output aria-hidden="true"></output>
					</div>
				</li>
				<li class="nedCAPTCHA-setup-setting">
					<div id="nedCAPTCHA-noiseColor-image">
						Noise Color
					</div>
					<div class="nedCAPTCHA-setup-range">
						<input aria-labelledby="nedCAPTCHA-noiseColor-image" type="color" name="noiseColor" class="form-control form-control-sm form-control-color text-output" value="#660000">
						<output aria-hidden="true"></output>
					</div>
				</li>
				<li class="nedCAPTCHA-setup-setting">
					<div id="nedCAPTCHA-textColor-image">
						Text Color
					</div>
					<div class="nedCAPTCHA-setup-range">
						<input aria-labelledby="nedCAPTCHA-textColor-image" type="color" name="textColor" class="form-control form-control-sm form-control-color text-output" value="#000000">
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
	<script type="text/javascript">
		// Outputs
		$('.nedCAPTCHA-setup-container input.text-output')
			.on('input', function() {
				$(this).next('output').text(this.value);
			}).trigger('input');
		// Update preview
		const $previewImage = $('svg.nedCAPTCHA-preview');
		$('.nedCAPTCHA-setup-container input.form-control-color').on('input change', function() {
			const name = $(this).attr('name');
			const varname = '--' + name;
			$previewImage.css(varname, $(this).val());
		}).trigger('change');
		
	</script>
</div>