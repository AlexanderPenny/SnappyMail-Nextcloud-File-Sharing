/**
 * ============================================================================
 *  Nextcloud Files for SnappyMail - client side
 * ============================================================================
 *
 *  Injects a button into the compose toolbar, shows a modal file browser, and
 *  inserts an HTML card linking to a Nextcloud public share.
 *
 *  ---------------------------------------------------------------------------
 *  WHY THE BUTTON IS ADDED FROM JAVASCRIPT
 *  ---------------------------------------------------------------------------
 *  SnappyMail's AbstractPlugin has NO addTemplateHook(). The example plugin
 *  mentions one in a commented block, but the method does not exist on the
 *  class. Bundled plugins that add UI (compact-composer, for one) register only
 *  CSS and JS and then manipulate the DOM. We do the same, via a
 *  MutationObserver that waits for a compose window to appear.
 *
 *  ---------------------------------------------------------------------------
 *  TALKING TO THE SERVER
 *  ---------------------------------------------------------------------------
 *  rl.pluginRemoteRequest(callback, action, params) posts to the plugin's own
 *  JSON endpoint, i.e. the actions registered with addJsonHook() in index.php:
 *      NextcloudGetSettings  NextcloudSetSettings  NextcloudList  NextcloudShare
 *  The Nextcloud credential never leaves the server.
 */
(function () {
	'use strict';

	/** Current browse path, so the modal can navigate up a level. */
	var STATE = { path: '' };

	/** Name printed on the card. Replaced by the brand_name setting. */
	var BRAND = 'Nextcloud';

	/**
	 * Wrapper around rl.pluginRemoteRequest.
	 * SnappyMail wraps the plugin's payload in { Result: ... }, so unwrap it
	 * here and hand the caller (error, result).
	 */
	function call(action, params, done) {
		if (!window.rl || !rl.pluginRemoteRequest) {
			done('rl.pluginRemoteRequest unavailable', null);
			return;
		}

		var settled = false;

		function finish(err, res, detail) {
			if (settled) { return; }        // watchdog and callback can race
			settled = true;
			if (err) {
				console.error('[nextcloud-files] ' + action + ' failed:', err, detail);
			}
			done(err, res);
		}

		// WATCHDOG. SnappyMail's fetchJSON re-rejects transport failures - a 500,
		// a dropped connection, a PHP fatal - and pluginRemoteRequest never
		// attaches a .catch(), so the rejection becomes an unhandled promise and
		// OUR CALLBACK IS NEVER CALLED. Without this the modal sits on
		// "Loading..." for ever with nothing to report. 35s clears SnappyMail's
		// own 30s request timeout.
		var timer = setTimeout(function () {
			finish('no response (request failed or timed out)', null, null);
		}, 35000);

		try {
			rl.pluginRemoteRequest(function (iError, oData) {
				clearTimeout(timer);
				// Note `undefined !==` rather than a truthiness test: an endpoint
				// may legitimately answer with `true`, 0 or '' as its Result.
				var res = (oData && undefined !== oData.Result) ? oData.Result : null;
				finish(iError ? ('server error code ' + iError) : 0, res, oData);
			}, action, params || {});
		} catch (e) {
			clearTimeout(timer);
			finish('exception: ' + e.message, null, null);
		}
	}

	/** Small DOM helper - textContent is used throughout, never innerHTML. */
	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) { e.className = cls; }
		if (undefined !== text) { e.textContent = text; }
		return e;
	}

	/** 1536 -> "1.5 KB". Returns '' for 0 so folders show nothing. */
	function humanSize(n) {
		if (!n) { return ''; }
		var units = ['B', 'KB', 'MB', 'GB'], i = 0;
		while (n >= 1024 && i < units.length - 1) {
			n /= 1024;
			i++;
		}
		return (n < 10 && i > 0 ? n.toFixed(1) : Math.round(n)) + ' ' + units[i];
	}

	/**
	 * Escape text for insertion into the HTML card.
	 * The file name comes from the user's own Nextcloud, but it is still
	 * untrusted input as far as the composed message is concerned.
	 */
	function safeHref(u) {
		// Defence in depth: the URL comes from Nextcloud, but a javascript:
		// value would survive HTML escaping and still be a live link.
		return /^https?:\/\//i.test(String(u || '')) ? String(u) : '';
	}

	function escapeHtml(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	/**
	 * Build the card that gets inserted into the message.
	 *
	 * CONSTRAINTS - this is EMAIL html, not web html:
	 *   - Receiving clients strip <style> blocks, so every rule is inline.
	 *   - No flexbox or grid; Outlook in particular ignores them. Hence <table>.
	 *   - role="presentation" keeps screen readers from announcing it as data.
	 *   - Colours are literal, not CSS variables, for the same reason.
	 *
	 * The template literal is collapsed to a single line before returning, so
	 * the source stays readable without shipping indentation into the message.
	 */
	function buildCard(name, url, size, password, expires) {
		var meta = size ? humanSize(size) : '';
		var h = '';
		h += '<table role="presentation" cellpadding="0" cellspacing="0" border="0"'
		  +  ' style="border-collapse:collapse;margin:16px 0 0 0;max-width:460px;width:100%;'
		  +  'border:1px solid #0082c9;border-radius:8px;background:#ffffff;">';
		h += '<tr><td style="padding:14px 16px;font-family:Arial,Helvetica,sans-serif;">';
		h += '<div style="font-size:12px;font-weight:bold;color:#0082c9;text-transform:uppercase;'
		  +  'letter-spacing:0.5px;margin:0 0 6px 0;">' + escapeHtml(BRAND) + '</div>';
		h += '<div style="font-size:14px;font-weight:bold;color:#202124;margin:0 0 4px 0;'
		  +  'word-break:break-all;">' + escapeHtml(name) + '</div>';
		h += '<div style="font-size:12px;color:#5f6368;margin:0 0 12px 0;">'
		  +  (meta ? escapeHtml(meta) : '') + '</div>';
		h += '<a href="' + escapeHtml(safeHref(url)) + '"'
		  +  ' style="display:inline-block;padding:9px 18px;background:#0082c9;color:#ffffff;'
		  +  'font-size:13px;font-weight:bold;text-decoration:none;border-radius:4px;'
		  +  'font-family:Arial,Helvetica,sans-serif;">Download</a>';
		if (password) {
			h += '<div style="margin:12px 0 0 0;padding:10px 12px;background:#f1f7fb;'
			  +  'border:1px solid #cfe3f0;border-radius:4px;">'
			  +  '<span style="font-size:12px;color:#5f6368;">Password: </span>'
			  +  '<span style="font-size:14px;font-weight:bold;color:#0082c9;'
			  +  'font-family:Consolas,Menlo,monospace;letter-spacing:0.5px;">'
			  +  escapeHtml(password) + '</span></div>';
		}
		if (expires) {
			h += '<div style="margin:10px 0 0 0;font-size:11px;color:#5f6368;">'
			  +  'Link expires ' + escapeHtml(expires) + '</div>';
		}
		h += '</td></tr></table>';
		return h;
	}

	/**
	 * Insert the card at the caret in the compose editor.
	 *
	 * `.textAreaParent` is the div SnappyMail mounts the editor into - it is the
	 * `initDom: editorArea` binding in PopupsCompose.html, so this selector is
	 * read from the shipped template rather than guessed. Depending on version
	 * and settings the editor inside it is either a Squire contenteditable, that
	 * same contenteditable inside an iframe, or - in plain-text mode - a plain
	 * textarea. All three are handled.
	 *
	 * execCommand('insertHTML') is deprecated but remains the only thing that
	 * reliably inserts at the caret across browsers, and Squire itself still
	 * relies on it.
	 *
	 * @param  {string} html      the card, for the two rich-text cases
	 * @param  {string} plainText the bare URL, for the plain-text case
	 * @return {boolean} false if no editor was found, so the caller can fall
	 *                   back to showing the raw link.
	 */
	function insertIntoEditor(html, plainText) {
		function notify(node, doc) {
			// Squire/SnappyMail fold DOM changes into the draft model only when
			// they see an input event.
			try {
				var view = (doc && doc.defaultView) || window;
				node.dispatchEvent(new view.Event('input', { bubbles: true }));
			} catch (e) { /* the DOM change still stands */ }
		}

		// display:none leaves offsetParent null - that is how SnappyMail hides
		// whichever of the two editors is not in use.
		function shown(el) {
			return !!(el && (el.offsetParent !== null || el.getClientRects().length));
		}

		function pick(sel) {
			var list = document.querySelectorAll(sel);
			var first = null;
			for (var i = 0; i < list.length; i++) {
				if (!first) { first = list[i]; }
				if (shown(list[i])) { return list[i]; }
			}
			return first;   // nothing visible - fall back to the first
		}

		// Class names come from SnappyMail's own SquireUI, not guesswork.
		var wysiwyg = pick('.squire-wysiwyg');
		var plain   = pick('.squire-plain');

		// HTML mode: insert the card into Squire's contenteditable div.
		if (wysiwyg && shown(wysiwyg)) {
			wysiwyg.insertAdjacentHTML('beforeend', html);
			notify(wysiwyg, document);
			return { ok: true, why: 'squire-wysiwyg' };
		}

		// Plain mode: append the bare link (a table would be meaningless).
		if (plain && shown(plain)) {
			var sep = (plain.value && !/\n\s*$/.test(plain.value)) ? '\n\n' : '';
			plain.value = plain.value + sep + plainText + '\n';
			notify(plain, document);
			return { ok: true, why: 'squire-plain' };
		}

		// Neither visible: still prefer the rich editor if it exists at all.
		if (wysiwyg) {
			wysiwyg.insertAdjacentHTML('beforeend', html);
			notify(wysiwyg, document);
			return { ok: true, why: 'squire-wysiwyg (hidden)' };
		}
		if (plain) {
			plain.value = plain.value + '\n\n' + plainText + '\n';
			notify(plain, document);
			return { ok: true, why: 'squire-plain (hidden)' };
		}

		return {
			ok: false,
			why: 'no squire editor found'
				+ ' wysiwyg=' + document.querySelectorAll('.squire-wysiwyg').length
				+ ' plain=' + document.querySelectorAll('.squire-plain').length
				+ ' parents=' + document.querySelectorAll('.textAreaParent').length
		};
	}

	// ======================================================================
	//  Modal file browser
	// ======================================================================

	function closeModal() {
		var m = document.getElementById('nc-files-modal');
		if (!m) { return; }
		// close() first: removing a dialog while it is still open leaves the
		// browser's top layer in a bad state and the page can stay inert.
		if (m.open) { m.close(); }
		m.remove();
	}

	/** Paint one directory listing into the modal. */
	function render(path, items) {
		var body = document.getElementById('nc-files-body');
		if (!body) { return; }

		body.innerHTML = '';
		STATE.path = path || '';

		body.appendChild(el('div', 'nc-crumb', '/' + (path || '')));

		// ".." row, only when we are below the root
		if (path) {
			var up = el('div', 'nc-row nc-dir', '↑  ..');
			up.onclick = function () {
				load(path.split('/').slice(0, -1).join('/'));
			};
			body.appendChild(up);
		}

		if (!items.length) {
			body.appendChild(el('div', 'nc-empty', 'Empty folder'));
			return;
		}

		items.forEach(function (item) {
			var row = el('div', 'nc-row ' + (item.isDir ? 'nc-dir' : 'nc-file'));
			row.appendChild(el('span', 'nc-name',
				(item.isDir ? '📁  ' : '📄  ') + item.name));

			if (!item.isDir) {
				row.appendChild(el('span', 'nc-size', humanSize(item.size)));
			}

			// folders navigate, files get shared
			row.onclick = function () {
				if (item.isDir) { load(item.path); } else { share(item); }
			};
			body.appendChild(row);
		});
	}

	/** Fetch one directory level from the server. */
	function load(path) {
		var body = document.getElementById('nc-files-body');
		if (body) { body.textContent = ''; body.appendChild(el('div', 'nc-empty', 'Loading...')); }

		call('NextcloudList', { path: path || '' }, function (err, res) {
			if (err || !res || res.error) {
				showError(body, (res && res.error) ? res.error : ('Could not reach Nextcloud - ' + err));
				return;
			}
			render(res.path, res.items || []);
		});
	}

	/** Create the share link, insert the card, close the modal. */
	function share(item) {
		var body = document.getElementById('nc-files-body');
		if (body) { body.textContent = ''; body.appendChild(el('div', 'nc-empty', 'Creating share link...')); }

		call('NextcloudShare', { path: item.path }, function (err, res) {
			if (err || !res || res.error) {
				showError(body, (res && res.error) ? res.error : ('Could not create the share link - ' + err));
				return;
			}

			var r = insertIntoEditor(
				buildCard(res.name, res.url, item.size, res.password, res.expires),
				res.url + (res.password ? ('\n\nPassword: ' + res.password) : ''));

			// Close ONLY on success. Closing regardless made a failed insert look
			// identical to a dialog that simply dismissed itself, and the old
			// window.prompt() fallback is silently suppressed by iOS Safari.
			if (r && r.ok) {
				closeModal();
				return;
			}

			body = document.getElementById('nc-files-body');
			if (body) {
				body.textContent = '';
				body.appendChild(el('div', 'nc-error',
					'The share link was created, but the card could not be added to the message.'));
				body.appendChild(el('div', 'nc-hint', 'Editor lookup: ' + (r ? r.why : 'no result')));
				var box = el('textarea', 'nc-linkbox');
				box.value = res.url;
				box.readOnly = true;
				box.rows = 3;
				box.style.width = '100%';
				body.appendChild(box);
				box.focus();
				box.select();
			}
		});
	}

	function showError(body, message) {
		if (!body) { return; }
		body.textContent = '';
		body.appendChild(el('div', 'nc-error', message));
	}

	function openModal() {
		closeModal();

		// A native <dialog> opened with showModal(), NOT a positioned div.
		//
		// SnappyMail's own popups (compose included) are native dialogs opened the
		// same way, which puts them in the browser's TOP LAYER. Nothing in the
		// normal DOM can paint above that at any z-index - a plain overlay div
		// renders behind the compose window no matter what. Dialogs in the top
		// layer stack in the order they were opened, so opening ours second puts
		// it above compose.
		var dlg = document.createElement('dialog');
		dlg.id = 'nc-files-modal';
		// 'animate' is SnappyMail's own class; without it the shipped rule
		// `dialog:not(.animate)` forces opacity 0 and would hide us completely.
		dlg.className = 'nc-modal animate';

		var card = el('div', 'nc-card');
		var head = el('div', 'nc-head');
		head.appendChild(el('span', 'nc-title', 'Insert a file from Nextcloud'));

		// Gear - reopens the connection form so settings can be changed later.
		var gear = el('button', 'nc-close nc-gear', '⚙');
		gear.title = 'Nextcloud connection settings';
		gear.onclick = function () { openSettings(); };
		head.appendChild(gear);

		var close = el('button', 'nc-close', '✕');
		close.onclick = closeModal;
		head.appendChild(close);

		var body = el('div', 'nc-body');
		body.id = 'nc-files-body';

		card.appendChild(head);
		card.appendChild(body);
		dlg.appendChild(card);

		// Clicking the backdrop dismisses. Decide by TARGET IDENTITY, never by
		// geometry: row.onclick calls load(), which re-renders the body and
		// RESIZES the dialog synchronously, before this bubbled listener runs.
		// Measuring getBoundingClientRect() here compared the original tap
		// against the NEW, shorter box, so opening any folder whose listing was
		// shorter than the current one closed the picker.
		// Only a real backdrop click reports the <dialog> itself as target.
		// closest('.nc-card') would NOT do - load() has already detached the
		// clicked row by now, and closest() on a detached node returns null.
		dlg.addEventListener('click', function (e) {
			if (e.target === dlg) { closeModal(); }
		});

		// Esc closes a native dialog for free; tidy up the element when it does.
		dlg.addEventListener('close', function () { dlg.remove(); });

		document.body.appendChild(dlg);
		dlg.showModal();
		boot();
	}

	// ======================================================================
	//  Connecting to Nextcloud
	// ======================================================================
	//
	// The plugin API exposes no way to add a page to SnappyMail's own settings
	// screen without a template hook, so this lives inside the modal. It opens
	// automatically when nothing is connected, and the gear brings it back.
	//
	// No credential is ever typed in here. The address is all the user gives
	// us; Nextcloud's Login Flow v2 does the rest in its own window.

	/** Build one labelled input, append it to `form`, and return it. */
	function field(form, label, type, placeholder, value, hint) {
		form.appendChild(el('label', 'nc-label', label));

		var input = document.createElement('input');
		input.className = 'nc-input';
		input.type = type;
		input.placeholder = placeholder || '';
		input.value = value || '';
		input.autocomplete = 'off';
		form.appendChild(input);

		if (hint) { form.appendChild(el('div', 'nc-hint', hint)); }
		return input;
	}

	function defaultNextcloudUrl() {
		var host = (window.location && window.location.hostname) ? window.location.hostname : '';
		host = String(host || '').replace(/^https?:\/\//i, '').replace(/\/.*$/, '').trim();
		host = host.replace(/^www\./i, '').replace(/\.$/, '');

		if (!host || host === 'localhost' || host === '127.0.0.1') {
			return 'https://cloud.example.com';
		}

		var parts = host.split('.');
		if (parts.length <= 1) {
			return 'https://cloud.example.com';
		}

		var domain = (parts.length > 2) ? parts.slice(1).join('.') : host;
		return 'https://cloud.' + domain;
	}

	/** The "not connected yet" view: an address and a Connect button. */
	function renderConnect(current, message) {
		var body = document.getElementById('nc-files-body');
		if (!body) { return; }

		body.textContent = '';
		var form = el('div', 'nc-form');

		if (message) { form.appendChild(el('div', 'nc-note', message)); }

		var defaultUrl = current.url || defaultNextcloudUrl();
		var url = field(form, 'Nextcloud address', 'url', defaultUrl,
			defaultUrl, 'The base URL only - no /index.php or /apps/files.');

		var btn = el('button', 'nc-save', 'Connect to Nextcloud');
		btn.onclick = function () { startLogin(url.value, btn); };
		form.appendChild(btn);

		form.appendChild(el('div', 'nc-hint nc-foot',
			'A Nextcloud window will open so you can grant access. Nextcloud '
			+ 'creates a dedicated app password for SnappyMail, which you can '
			+ 'revoke at any time under Settings > Security. Your Nextcloud '
			+ 'password is never entered here or stored.'));

		body.appendChild(form);
	}

	/** The "already connected" view: who we are, start folder, disconnect. */
	function renderConnected(current) {
		var body = document.getElementById('nc-files-body');
		if (!body) { return; }

		body.textContent = '';
		var form = el('div', 'nc-form');

		form.appendChild(el('div', 'nc-note',
			'Connected to ' + current.url + ' as ' + current.user + '.'));

		var root = field(form, 'Start folder (optional)', 'text', 'e.g. Documents',
			current.root, 'Leave blank to browse from the top of your files.');

		var row = el('div', 'nc-row-btns');

		var save = el('button', 'nc-save', 'Save');
		save.onclick = function () {
			save.disabled = true;
			save.textContent = 'Saving...';
			call('NextcloudSetSettings', { root: root.value }, function (err, res) {
				if (err || !res) {
					save.disabled = false;
					save.textContent = 'Save';
					showError(body, 'Could not save the settings - ' + err);
					return;
				}
				load(root.value);
			});
		};
		row.appendChild(save);

		var out = el('button', 'nc-danger', 'Disconnect');
		out.title = 'Forget the stored credential on this server';
		out.onclick = function () {
			out.disabled = true;
			call('NextcloudDisconnect', {}, function () { openSettings(); });
		};
		row.appendChild(out);

		form.appendChild(row);
		body.appendChild(form);
	}

	/**
	 * Run Login Flow v2.
	 *
	 * The popup is opened SYNCHRONOUSLY here, inside the click handler, and
	 * pointed at about:blank. Opening it later, in the NextcloudLoginStart
	 * callback, would be an asynchronous window.open() and every browser would
	 * block it as a pop-up. We navigate the already-open window instead once
	 * the server hands back the login URL.
	 */
	function startLogin(sUrl, btn) {
		var body = document.getElementById('nc-files-body');

		if (!sUrl) {
			showError(body, 'Enter your Nextcloud address first');
			return;
		}

		var popup = window.open('about:blank', 'nc-login',
			'width=820,height=760,menubar=no,toolbar=no');

		if (!popup) {
			showError(body, 'Your browser blocked the Nextcloud login window. '
				+ 'Allow pop-ups for this site and try again.');
			return;
		}

		btn.disabled = true;
		btn.textContent = 'Opening Nextcloud...';

		call('NextcloudLoginStart', { url: sUrl }, function (err, res) {
			if (err || !res || res.error || !res.login) {
				popup.close();
				showError(body, (res && res.error) ? res.error : ('Could not start the login - ' + err));
				return;
			}

			popup.location = res.login;
			waitForGrant(popup, Date.now() + 300000);   // give up after 5 minutes
		});
	}

	/** Show the waiting state and poll the server until access is granted. */
	function waitForGrant(popup, deadline) {
		var body = document.getElementById('nc-files-body');
		if (!body) { return; }

		body.textContent = '';
		var stopped = false;
		var pollTimer = null;
		var wrap = el('div', 'nc-form');
		wrap.appendChild(el('div', 'nc-note',
			'Waiting for you to grant access in the Nextcloud window...'));
		wrap.appendChild(el('div', 'nc-hint',
			'If you cannot see it, check behind this window or for a blocked pop-up.'));

		var cancel = el('button', 'nc-danger', 'Cancel');
		cancel.onclick = function () {
			stopped = true;
			if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
			if (popup && !popup.closed) { popup.close(); }
			openSettings();
		};
		wrap.appendChild(cancel);
		body.appendChild(wrap);

		function scheduleNext(delay) {
			if (stopped) { return; }
			if (pollTimer) { clearTimeout(pollTimer); }
			pollTimer = setTimeout(tick, delay);
		}

		function tick() {
			if (stopped) { return; }

			if (Date.now() > deadline) {
				if (popup && !popup.closed) { popup.close(); }
				openSettings('That took too long - the login request expired. Please try again.');
				return;
			}

			call('NextcloudLoginPoll', {}, function (err, res) {
				if (stopped) { return; }

				// A failed poll is not fatal on its own - the network may just
				// have hiccuped - so keep trying until the deadline.
				if (err || !res) {
					scheduleNext(30000);
					return;
				}

				if ('ok' === res.status) {
					if (popup && !popup.closed) { popup.close(); }
					load('');
					return;
				}

				if ('pending' === res.status) {
					var retryDelay = 60000;
					if (res.retryAfter && Number(res.retryAfter) > 0) {
						retryDelay = Math.max(60000, Number(res.retryAfter) * 1000);
					}
					scheduleNext(retryDelay);
					return;
				}

				if ('error' === res.status && res.retryAfter && Number(res.retryAfter) > 0) {
					var retryDelay = Math.max(60000, Number(res.retryAfter) * 1000);
					setTimeout(function () {
						if (!stopped) {
							openSettings(res.error || 'Nextcloud is rate-limiting the login polling. Please retry in a minute.');
						}
					}, retryDelay);
					return;
				}

				// none / expired / error
				if (popup && !popup.closed) { popup.close(); }
				openSettings(res.error
					? res.error
					: 'The login request expired. Please try again.');
			});
		}

		scheduleNext(30000);
	}

	/** Fetch current state, then show whichever settings view fits. */
	function openSettings(message) {
		var body = document.getElementById('nc-files-body');
		if (body) { body.textContent = ''; body.appendChild(el('div', 'nc-empty', 'Loading...')); }

		call('NextcloudGetSettings', {}, function (err, res) {
			if (res && res.brand) { BRAND = res.brand; }
			if (err || !res) {
				renderConnect({}, message || null);
				return;
			}
			if (res.connected) {
				renderConnected(res);
				return;
			}
			// `stale` means a credential is stored but could not be decrypted,
			// which happens when the mail password changes - CryptKey() is
			// sealed with it. Say so, rather than looking like a random failure.
			renderConnect(res, message || (res.stale
				? 'Your mail password changed, so the saved Nextcloud credential '
					+ 'could no longer be unlocked. Please connect again.'
				: null));
		});
	}

	/**
	 * Decide what the modal shows when it opens: the file list when connected,
	 * otherwise the connect view.
	 */
	function boot() {
		var body = document.getElementById('nc-files-body');
		if (body) { body.textContent = ''; body.appendChild(el('div', 'nc-empty', 'Loading...')); }

		call('NextcloudGetSettings', {}, function (err, res) {
			if (res && res.brand) { BRAND = res.brand; }
			if (err || !res) {
				showError(body, 'Could not read the plugin settings - ' + err);
				return;
			}
			if (!res.connected) {
				renderConnect(res, res.stale
					? 'Your mail password changed, so the saved Nextcloud credential '
						+ 'could no longer be unlocked. Please connect again.'
					: 'Connect your Nextcloud account to get started.');
				return;
			}
			load(res.root || '');
		});
	}

	// ======================================================================
	//  Compose button injection
	// ======================================================================

	/**
	 * The Nextcloud logo, as inline SVG.
	 *
	 * Inline rather than a file so the button needs no extra HTTP request and
	 * cannot be broken by asset-path or cache issues.
	 *
	 * The mark is three rings - one large, two small - all in Nextcloud blue,
	 * which is how the real logo is drawn. Stroke widths are deliberately thin
	 * relative to the radii: at toolbar size a thicker stroke closes up the
	 * holes in the two small rings and they read as solid dots.
	 */
	var NC_LOGO =
		'<svg viewBox="0 0 128 84" width="26" height="17" aria-hidden="true" '
			+ 'focusable="false" shape-rendering="geometricPrecision" '
			+ 'style="vertical-align:middle">'
			+ '<g fill="none" stroke="#0082c9">'
				+ '<circle cx="64" cy="42" r="24" stroke-width="9"/>'
				+ '<circle cx="25" cy="42" r="13" stroke-width="6.5"/>'
				+ '<circle cx="103" cy="42" r="13" stroke-width="6.5"/>'
			+ '</g>'
		+ '</svg>';

	/**
	 * Put the button in the compose toolbar, next to the paperclip.
	 *
	 * Anchored on #composeUploadButton - the attach-files control in
	 * PopupsCompose.html. That id is unique, exists only while a compose window
	 * is open, and is a stable part of the shipped template, which makes it a far
	 * safer hook than matching on layout classes.
	 *
	 * The new button is wrapped in its own `.btn-group` and inserted BEFORE the
	 * attachment group, so the paperclip keeps its usual place at the right edge
	 * and ours sits immediately to its left. The `btn` class and the inline
	 * padding are copied from the attachment button so the two match exactly.
	 */
	function addButton() {
		var upload = document.getElementById('composeUploadButton');
		if (!upload) { return; }                                 // compose not open

		var group = upload.closest('.btn-group');
		if (!group || !group.parentNode) { return; }
		if (group.parentNode.querySelector('.nc-files-btn')) { return; }   // already added

		var btn = el('a', 'btn fontastic nc-files-btn');
		btn.title = 'Insert a file from Nextcloud';
		btn.style.paddingLeft = '10px';
		btn.style.paddingRight = '10px';
		btn.innerHTML = NC_LOGO;
		btn.onclick = function (e) {
			e.preventDefault();
			openModal();
		};

		var wrap = el('div', 'btn-group nc-files-group');
		wrap.appendChild(btn);
		group.parentNode.insertBefore(wrap, group);
	}

	// The compose window is built on demand and torn down on close, so watch the
	// DOM rather than trying to hook a lifecycle event the plugin API does not
	// expose. addButton() is cheap and self-guarding, so running it on every
	// mutation batch is fine.
	new MutationObserver(addButton).observe(document.documentElement, {
		childList: true,
		subtree: true
	});

	document.addEventListener('DOMContentLoaded', addButton);
})();
