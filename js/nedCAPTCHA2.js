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

const editing = {};

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
		insertOnlineDesignerLinks();
	});
}

/**
 * Wraps the action tag on the Online Designer fields view in a link
 * that will open the settings editor 
 */
function insertOnlineDesignerLinks() {
	for (const field of config.tagged) {
		const $ats = $('tr#' + field + '-tr .actiontags code');
		$ats.each(function() {
			const orig = $(this).html();
			$(this).html(orig.replace(config.at, '<a class="nedCAPTCHA-OD-link" data-bs-toggle="tooltip" title="'+config.linkTitle+'" href="javascript:' + NS_PREFIX + EM_NAME + '.edit(\'' + field + '\');">' + config.at.replace('@', '<span class="nedCAPTCHA-OD-accent">@</span>') + '</a>'));
		});
		$('.nedCAPTCHA-OD-link').each(function() {
			new bootstrap.Tooltip(this, { trigger: 'hover' });
		});
	}
}

function edit(field) {
	if (editing[field]) return;

	editing[field] = true;
	log('Editing ' + field);
	config.JSMO.ajax('get-params', { 'field': field })
	.then(function(data) {
		log(data);
	})
	.catch(function(err) {
		log('Error', err);
	})
	.finally(function() {
		editing[field] = false;
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