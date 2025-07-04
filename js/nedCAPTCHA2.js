// nedCAPTCHA 2 EM
// Dr. Günther Rezniczek, Marien Hospital Herne, Klinikum der Ruhr-Universität Bochum
// @ts-check
;(function() {

//#region Init global object and define local variables

const EM_NAME = 'nedCAPTCHA2';
const NS_PREFIX = 'DE_ELISABETHGRUPPE_';

// @ts-ignore
const EM = window[NS_PREFIX + EM_NAME] ?? {
	init: initialize,
	edit: edit
};
// @ts-ignore
window[NS_PREFIX + EM_NAME] = EM;

/** Configuration data supplied from the server */
let config = {};

let editing = false;

const hooks = {};

//#endregion

/**
 * Implements the public init method.
 * @param {object} config_data 
 * @param {object} jsmo
 */
function initialize(config_data, jsmo = null) {
	config = config_data;
	config.JSMO = jsmo;
	log('Initialzing ...', config);
	
	$(function() {
		initOnlineDesigner();
	});
}

/**
 * Wraps the action tag on the Online Designer fields view in a link
 * that will open the settings editor 
 */
function initOnlineDesigner() {
	// Setup links
	for (const field of config.tagged) {
		addLink(field);
	}
	// Create dialog container
	const $dialog = $('<div id="nedCAPTCHA-OD-editor" style="display: none;"></div>');
	$dialog.appendTo('body');
	// Hook into the Online Designer
	hooks.setSurveyQuestionNumbers = window['setSurveyQuestionNumbers'];
	window['setSurveyQuestionNumbers'] = function(fieldName) {
		hooks.setSurveyQuestionNumbers(fieldName);
		addLink(fieldName);
	}
}

function addLink(fieldName) {
	if (!fieldName) return;
	const $ats = $('tr#' + fieldName + '-tr .actiontags code');
	if ($ats.find('a.nedCAPTCHA-OD-link').length) return;
	log('Adding/Updating link for', fieldName);
	$ats.each(function() {
		const orig = $(this).html();
		$(this).html(orig.replace(config.at, '<a class="nedCAPTCHA-OD-link" data-bs-toggle="tooltip" title="'+config.linkTitle+'" href="javascript:' + NS_PREFIX + EM_NAME + '.edit(\'' + fieldName + '\');">' + config.at.replace('@', '<span class="nedCAPTCHA-OD-accent">@</span>') + '</a>'));
		$(this).find('a.nedCAPTCHA-OD-link').each(function() {
			new bootstrap.Tooltip(this, { trigger: 'hover' });
		});
	});
}

function edit(field) {
	if (editing) return;
	$('#nedCAPTCHA-OD-editor').html('');
	editing = true;
	log('Editing ' + field);
	config.JSMO.ajax('get-params', { 'field': field })
	.then(function(data) {
		log('Data received:', data);
		$('#nedCAPTCHA-OD-editor')
			.html(data.html)
			// Outputs
			.on('input', 'input.text-output', function() {
				$(this).next('output').text(this.value);
			})
			// Update preview
			.on('input', 'input.form-control-color', function() {
				const name = $(this).attr('name');
				const varname = '--' + name;
				$('svg.nedCAPTCHA-preview').css(varname, $(this).val());
			});
		$('#nedCAPTCHA-OD-editor input.form-control-color').trigger('input');
		$('#nedCAPTCHA-OD-editor input.text-output').trigger('input');
		let dirty = false;
		const close = function() {
			editing = false;
			$('#nedCAPTCHA-OD-editor').dialog('close');
		};
		// Show dialog
		$('#nedCAPTCHA-OD-editor').dialog({
			title: '<code>@NEDCAPTCHA</code> Editor <span style="font-weight: normal;"> &ndash; ' + field + '</span>',
			modal: true,
			resizable: false,
			width: 800,
			closeOnEscape: false,
			buttons: [
				{
					text: window['lang'].global_53,
					click: close
				},
				{
					text: config.updateLabel,
					click: function() {
						if (dirty) {
							config.JSMO.ajax('set-params', { 'field': field, 'params': data.params })
							.then(function(response) {
								log('Update result:', response);
								if (response.errors) {
									response.errors.push('Please try again after reloading the page.');
									// @ts-ignore base.js
									showToast("nedCAPTCHA ERROR", response.errors.join('<br>'), "error");
									return;
								}
								if (response.warnings) {
									// @ts-ignore base.js
									showToast("nedCAPTCHA WARNING", response.warnings.join('<br>'), "warn");
									return;
								}
								else {
									// @ts-ignore base.js
									showToast("SUCCESS", "nedCAPTCHA configuration has been updated", "success", 1000);
								}
								close();
							});
						}
						else {
							close();
						}
					}
				}
			],
			open: function() {
				$('#nedCAPTCHA-OD-editor').on('input', function(e) {
					const $el = $(e.target);
					const name = $el.attr('name') ?? '?';
					const val = $el.is(':checkbox') ? $el.is(':checked') : $el.val();
					if (Object.keys(data.defaults).includes(name)) {
						data.params[name] = val;
					} 
					dirty = true;
					log('Updated', name, val);
				});
			},
			close: function() {
				editing = false;
				$('#nedCAPTCHA-OD-editor').html('');
				$('#nedCAPTCHA-OD-editor').dialog('destroy');
			}
		
		});
	})
	.catch(function(err) {
		log('Error', err);
		editing = false;
	});
}



//#region Debug Logging

/**
 * Logs a message to the console when in debug mode
 */
function log() {
	if (!config.debug) return;
	var ln = '??';
	try {
		var line = ((new Error).stack ?? '').split('\n')[2];
		var parts = line.split(':');
		ln = parts[parts.length - 2];
	}
	catch(err) { }
	log_print(ln, 'log', arguments);
}
/**
 * Logs a warning to the console when in debug mode
 */
function warn() {
	if (!config.debug) return;
	var ln = '??';
	try {
		var line = ((new Error).stack ?? '').split('\n')[2];
		var parts = line.split(':');
		ln = parts[parts.length - 2];
	}
	catch(err) { }
	log_print(ln, 'warn', arguments);
}

/**
 * Logs an error to the console when in debug mode
 */
function error() {
	var ln = '??';
	try {
		var line = ((new Error).stack ?? '').split('\n')[2];
		var parts = line.split(':');
		ln = parts[parts.length - 2];
	}
	catch(err) { }
	log_print(ln, 'error', arguments);;
}

/**
 * Prints to the console
 * @param {string} ln Line number where log was called from
 * @param {'log'|'warn'|'error'} mode
 * @param {IArguments} args
 */
function log_print(ln, mode, args) {
	var prompt = EM_NAME + ' ' + config.version + ' [' + ln + ']';
	switch(args.length) {
		case 1:
			console[mode](prompt, args[0]);
			break;
		case 2:
			console[mode](prompt, args[0], args[1]);
			break;
		case 3:
			console[mode](prompt, args[0], args[1], args[2]);
			break;
		case 4:
			console[mode](prompt, args[0], args[1], args[2], args[3]);
			break;
		case 5:
			console[mode](prompt, args[0], args[1], args[2], args[3], args[4]);
			break;
		case 6:
			console[mode](prompt, args[0], args[1], args[2], args[3], args[4], args[5]);
			break;
		default:
			console[mode](prompt, args);
			break;
	}
}

//#endregion

})();