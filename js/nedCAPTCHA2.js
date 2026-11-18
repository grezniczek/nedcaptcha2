// nedCAPTCHA 2 EM
;(function() {

//#region Init global object and define local variables

const EM_NAME = 'nedCAPTCHA2';
const NS_PREFIX = 'DE_RUB_SEG_';

const EM = window[NS_PREFIX + EM_NAME] ?? {
	init: initialize,
	edit: edit
};
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
		const regex = new RegExp(
			config.at.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(?![A-Za-z0-9_-])'
		);
		$(this).html(orig.replace(regex, '<a class="nedCAPTCHA-OD-link" data-bs-toggle="tooltip" title="'+config.linkTitle+'" href="javascript:' + NS_PREFIX + EM_NAME + '.edit(\'' + fieldName + '\');">' + config.at.replace('@', '<span class="nedCAPTCHA-OD-accent">@</span>') + '</a>'));
		$(this).find('a.nedCAPTCHA-OD-link').each(function() {
			new bootstrap.Tooltip(this, { trigger: 'hover' });
		});
	});
}

function edit(field) {
	if (editing) return;
	$('#nedCAPTCHA-OD-editor').off('.nedCAPTCHA').html('');
	editing = true;
	log('Editing ' + field);
	Promise.resolve().then(() => config.JSMO.ajax('get-params', { 'field': field }))
	.then(function(data) {
		log('Data received:', data);
		const errors = Array.isArray(data?.errors) ? data.errors.filter(message => typeof message === 'string') : [];
		if (errors.length) {
			showLoadError(field, errors);
			return;
		}
		// Validate the load response before inserting HTML or enabling Update.
		const stringList = value => Array.isArray(value) && value.every(item => typeof item === 'string');
		if (!data || typeof data !== 'object' || Array.isArray(data)
			|| typeof data.html !== 'string' || !data.html.trim()
			|| !data.params || typeof data.params !== 'object' || Array.isArray(data.params)
			|| !stringList(data.keys) || !stringList(data.errors) || !stringList(data.warnings)) {
			showLoadError(field);
			return;
		}
		$('#nedCAPTCHA-OD-editor')
			.html(data.html)
			// Outputs
			.on('input.nedCAPTCHA', 'input.text-output', function() {
				$(this).next('output').text(this.value);
			})
			// Update preview
			.on('input.nedCAPTCHA', 'input.form-control-color', function() {
				const name = $(this).attr('name');
				const varname = '--' + name;
				$('svg.nedCAPTCHA-preview').css(varname, $(this).val());
			});
		$('#nedCAPTCHA-OD-editor input.form-control-color').trigger('input');
		$('#nedCAPTCHA-OD-editor input.text-output').trigger('input');
		let dirty = false;
		let revision = 0;
		let saving = false;
		let closed = false;
		const close = function() {
			editing = false;
			$('#nedCAPTCHA-OD-editor').dialog('close');
		};
		// Show dialog
		$('#nedCAPTCHA-OD-editor').dialog({
			title: 'nedCAPTCHA Editor <span style="font-weight: normal;"> &ndash; ' + field + '</span>',
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
						if (saving) return;
						if (dirty) {
							const submittedRevision = revision;
							const submittedParams = JSON.parse(JSON.stringify(data.params));
							if (typeof submittedParams.custom === 'string' && $('#nedCAPTCHA-custom').attr('data-format') === 'json') {
								try {
									submittedParams.custom = JSON.parse(submittedParams.custom);
									if (!Array.isArray(submittedParams.custom)) throw new Error();
								} catch {
									showNotice('nedCAPTCHA WARNING', ['Enter a JSON list of challenge and response pairs.'], 'warning');
									return;
								}
							}
							saving = true;
							config.JSMO.ajax('set-params', { 'field': field, 'params': submittedParams })
							.then(function(response) {
								if (closed) return;
								log('Update result:', response);
								if (response.errors.length) {
									showNotice('nedCAPTCHA ERROR', [...response.errors, 'Please try again after reloading the page.'], 'danger');
									return;
								}
								if (response.warnings.length) {
									showNotice('nedCAPTCHA WARNING', [...response.warnings, 'Please fix the issues and try again.'], 'warning');
									return;
								}
								// Success
								if (revision !== submittedRevision) {
									showNotice('Saved', ['The submitted settings were saved. Your later changes are still unsaved; click Update again to save them.'], 'info');
									return;
								}
								dirty = false;
								close();
								showNotice('SUCCESS', ['nedCAPTCHA configuration has been updated'], 'success');
							})
							.catch(function(err) {
								log(err);
								if (closed) return;
								showNotice('nedCAPTCHA ERROR', ['An unknown error occurred. Please try again.'], 'danger');
							})
							.finally(function() {
								saving = false;
							});
						}
						else {
							close();
						}
					}
				}
			],
			open: function() {
				$('#nedCAPTCHA-OD-editor').on('input.nedCAPTCHA', function(e) {
					const $el = $(e.target);
					const name = $el.attr('name') ?? '?';
					const val = $el.is(':checkbox') ? $el.is(':checked') : $el.val();
					if (data.keys.includes(name)) {
						data.params[name] = val;
							revision++;
					} 
					dirty = true;
					log('Updated', name, val);
				});
			},
			close: function() {
				closed = true;
				editing = false;
				$('#nedCAPTCHA-OD-editor').off('.nedCAPTCHA').html('');
				$('#nedCAPTCHA-OD-editor').dialog('destroy');
			}
		
		});
		if (data.warnings.length) {
			showNotice('nedCAPTCHA WARNING', [...new Set(data.warnings)], 'warning');
		}
	})
	.catch(function(err) {
		log('Error', err);
		showLoadError(field);
	});
}

function showLoadError(field, messages = ['CAPTCHA settings could not be loaded.']) {
	const $editor = $('#nedCAPTCHA-OD-editor').off('.nedCAPTCHA').empty();
	$editor.dialog({
		title: 'nedCAPTCHA Editor <span style="font-weight: normal;"> &ndash; ' + field + '</span>',
		modal: true,
		resizable: false,
		width: 800,
		closeOnEscape: false,
		buttons: [{
			text: window['lang'].global_53,
			click: function() { $editor.dialog('close'); }
		}],
		close: function() {
			editing = false;
			$editor.off('.nedCAPTCHA').empty().dialog('destroy');
		}
	});
	showNotice('Unable to load CAPTCHA settings', [...messages, 'Reload the Online Designer before trying again.'], 'danger');
}

/**
 * Display module-owned feedback without Bootstrap's animated toast lifecycle.
 * Messages are inserted as text, including messages received from the server.
 */
function showNotice(title, messages, type) {
	const $notice = $('<div class="nedCAPTCHA-OD-notice alert"></div>')
		.addClass('alert-' + type)
		.attr('role', type === 'success' ? 'status' : 'alert');
	$('<strong></strong>').text(title).appendTo($notice);
	const $messages = $('<ul class="mb-0"></ul>').appendTo($notice);
	messages.forEach(message => $('<li></li>').text(message).appendTo($messages));
	let timer;
	$('<button type="button" class="btn-close" aria-label="Close"></button>')
		.on('click', function() {
			clearTimeout(timer);
			$notice.remove();
		})
		.appendTo($notice);
	if (type === 'success') {
		$('.nedCAPTCHA-OD-notice-success').remove();
		$notice.addClass('nedCAPTCHA-OD-notice-success').appendTo('body');
		timer = setTimeout(() => $notice.remove(), 3000);
	}
	else {
		const $editor = $('#nedCAPTCHA-OD-editor');
		$editor.children('.nedCAPTCHA-OD-notice').remove();
		$notice.prependTo($editor);
		$editor.scrollTop(0);
	}
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
