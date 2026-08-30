<?php
/**
 * ============================================================================
 *  Nextcloud Files for SnappyMail
 * ============================================================================
 *
 *  Adds a button to the compose window that lets you browse a Nextcloud
 *  instance, pick a file, create a public share link for it, and drop a tidy
 *  HTML card into the message body.
 *
 *  ---------------------------------------------------------------------------
 *  WHY THE FOLDER NAME MATTERS
 *  ---------------------------------------------------------------------------
 *  SnappyMail does not read a manifest to find the plugin class. It derives the
 *  class name from the directory name, in Plugins/Manager.php:
 *
 *      convertPluginFolderNameToClassName('nextcloud-files')
 *          -> ucfirst each word split on non-alphanumerics
 *          -> 'NextcloudFilesPlugin'
 *
 *  and it refuses outright any folder not matching ^[a-z0-9-]+$ .
 *  So: keep this directory named `nextcloud-files`, and keep the class below
 *  named NextcloudFilesPlugin. Rename one without the other and the plugin
 *  silently never loads - no error, it simply will not appear.
 *
 *  ---------------------------------------------------------------------------
 *  WHERE THIS RUNS
 *  ---------------------------------------------------------------------------
 *  APP_PLUGINS_PATH = APP_PRIVATE_DATA . 'plugins/'
 *  i.e. the SnappyMail *data* directory, not the application directory. In a
 *  container deployment that is a mounted volume, so the plugin survives image
 *  updates without needing to be re-installed.
 *
 *  ---------------------------------------------------------------------------
 *  DESIGN NOTE: WHY EVERY NETWORK CALL IS SERVER-SIDE
 *  ---------------------------------------------------------------------------
 *  The browser never sees the Nextcloud credential. The JS calls back into this
 *  plugin (rl.pluginRemoteRequest -> addJsonHook), and PHP does the talking to
 *  Nextcloud. If the picker fetched WebDAV directly from the browser we would
 *  have to ship the app password to the client, and deal with CORS.
 */

class NextcloudFilesPlugin extends \RainLoop\Plugins\AbstractPlugin
{
	/**
	 * Constants read by SnappyMail's plugin manager to populate the admin list.
	 * BUMP VERSION WHENEVER js/ OR css/ CHANGES.
	 * SnappyMail serves plugin assets from one bundle at
	 *     ?/Plugins/0/User/<hash>/
	 * and Manager::Hash() builds that hash from APP_VERSION, each plugin's
	 * "Class@VERSION", and the asset FILE PATHS - never the file contents.
	 * So editing picker.js leaves the URL identical and every browser keeps
	 * serving the old copy. Bumping VERSION changes the hash, which changes
	 * the URL, which is the only thing that actually busts the cache.
	 *
	 * REQUIRED is the minimum SnappyMail version; the plugin API used here
	 * (addJsonHook / getUserSettings / saveUserSettings) predates 2.36.
	 */
	const DEFAULT_EXPIRY_DAYS = 365;

	const
		NAME = 'Nextcloud Files',
		AUTHOR      = 'Alexander Penny',
		URL         = 'https://github.com/SnappyMail-Nextcloud-File-Sharing',
		VERSION     = '0.5.1',
		RELEASE     = '2026-08-29',
		REQUIRED    = '2.36.0',
		CATEGORY    = 'Integrations',
		LICENSE     = 'MIT',
		DESCRIPTION = 'Insert Nextcloud share links into messages.';

	/**
	 * Called once when the plugin is loaded. Everything the plugin adds to the
	 * application must be registered here - assets and endpoints alike.
	 *
	 * NOTE: AbstractPlugin has no addTemplateHook(). The example plugin mentions
	 * one in a commented block, but it does not exist in the class. That is why
	 * the compose button is injected from JavaScript rather than a template.
	 *
	 * Each addJsonHook('X', 'method') exposes an endpoint the front end reaches
	 * with rl.pluginRemoteRequest(cb, 'X', params).
	 */

	protected function configMapping() : array
	{
		return array(
			\RainLoop\Plugins\Property::NewInstance('brand_name')
				->SetLabel('Name shown on the card')
				->SetDefaultValue('Nextcloud')
				->SetDescription('Heading printed on the block inserted into the message, for example your own service name.'),
			\RainLoop\Plugins\Property::NewInstance('allow_private_hosts')
				->SetLabel('Allow a Nextcloud on a private network')
				->SetType(\RainLoop\Enumerations\PluginPropertyType::BOOL)
				->SetDefaultValue(false)
				->SetDescription('Off by default, which stops a user pointing the plugin at localhost or a LAN address and using the server to probe your internal network. Turn it on only if your Nextcloud really is on a private address.')
		);
	}

	/**
	 * Split a browse path into segments, dropping empties and refusing dot
	 * segments. rawurlencode() leaves . and .. alone and curl resolves dot
	 * segments before sending, so without this a crafted path could climb
	 * above /remote.php/dav/files/<user>/.
	 */
	private function safePathSegments(string $sPath) : array
	{
		$aOut = array();
		foreach (\explode('/', $sPath) as $sPart) {
			if ('' === $sPart || '.' === $sPart || '..' === $sPart) {
				continue;
			}
			$aOut[] = $sPart;
		}
		return $aOut;
	}

	public function Init() : void
	{
		$this->addCss('css/picker.css');
		$this->addJs('js/picker.js');

		$this->addJsonHook('NextcloudGetSettings', 'DoGetSettings');
		$this->addJsonHook('NextcloudSetSettings', 'DoSetSettings');
		$this->addJsonHook('NextcloudLoginStart',  'DoLoginStart');
		$this->addJsonHook('NextcloudLoginPoll',   'DoLoginPoll');
		$this->addJsonHook('NextcloudDisconnect',  'DoDisconnect');
		$this->addJsonHook('NextcloudList',        'DoList');
		$this->addJsonHook('NextcloudShare',       'DoShare');
	}

	// =======================================================================
	//  Settings and credential storage (per user)
	// =======================================================================

	/**
	 * The key the app password is encrypted with.
	 *
	 * MainAccount::CryptKey() is sealed with the user's IMAP password (unless
	 * the server sets security.insecure_cryptkey, which seals it with the email
	 * address instead and is much weaker). That is the whole point of using it
	 * here: the stored secret is only recoverable while this user is logged in
	 * with their mail password. Someone who walks off with the podman volume,
	 * or a backup of it, gets ciphertext they cannot open.
	 *
	 * @return string|null null when there is no authenticated account, in which
	 *                     case we refuse rather than fall back to plaintext.
	 */
	private function cryptKey() : ?string
	{
		$oActions = $this->Manager() ? $this->Manager()->Actions() : null;
		if (!$oActions) {
			return null;
		}

		// false = do not throw when there is no token; we want null, not a 500.
		$oMain = $oActions->getMainAccountFromToken(false);

		return $oMain ? $oMain->CryptKey() : null;
	}

	/**
	 * Read this user's settings, decrypting the app password.
	 *
	 * Two storage shapes are accepted:
	 *   - the current one, where `password` is a Crypt::EncryptToJSON envelope
	 *     guarded by `passwordHMAC`;
	 *   - the legacy one from before encryption existed, where `password` was
	 *     plain text. Those stay readable so upgrading does not force everyone
	 *     to reconnect, and get re-encrypted the next time anything is saved.
	 *
	 * The HMAC check is copied from SnappyMail's own CardDAV handling: if the
	 * user's mail password changed then CryptKey() changed with it, and
	 * decrypting would yield garbage. Comparing the HMAC first lets us detect
	 * that and discard the secret cleanly instead of firing broken credentials
	 * at Nextcloud.
	 *
	 * @return array{url:string,user:string,password:string,root:string,legacy:bool}
	 */
	private function settings() : array
	{
		$a = $this->getUserSettings();

		$sStored   = (string) ($a['password'] ?? '');
		$sPassword = '';
		$bLegacy   = false;

		if ('' !== $sStored) {
			if (empty($a['passwordHMAC'])) {
				// No HMAC recorded -> written before encryption was added.
				$sPassword = $sStored;
				$bLegacy   = true;
			} else {
				$sKey = $this->cryptKey();
				if ($sKey && \hash_equals((string) $a['passwordHMAC'], \hash_hmac('sha1', $sStored, $sKey))) {
					$mPlain    = \SnappyMail\Crypt::DecryptFromJSON($sStored, $sKey);
					$sPassword = \is_string($mPlain) ? $mPlain : '';
				}
				// HMAC mismatch -> mail password changed. Leave $sPassword
				// empty so the UI asks the user to reconnect.
			}
		}

		$sStoredUser = \trim((string) ($a['user'] ?? ''));
		if ('' !== $sStoredUser && !$this->isLikelyUsername($sStoredUser)) {
			$sStoredUser = '';
		}

		return array(
			// trailing slash stripped so we can concatenate paths predictably;
			// also strip /index.php because Nextcloud's login flow can echo it
			// back in the canonical server value and that breaks WebDAV URLs.
			'url'      => $this->normalizeServerUrl((string) ($a['url'] ?? '')),
			'user'     => $sStoredUser,
			// WebDAV user id, resolved lazily by webdavUser() and cached, so an
			// account configured before this was fixed repairs itself on the next
			// browse instead of needing a reconnect.
			'userId'   => \trim($a['userId'] ?? ''),
			'password' => $sPassword,
			'root'     => \trim($a['root'] ?? ''),
			'legacy'   => $bLegacy
		);
	}

	/**
	 * Persist settings, encrypting the app password.
	 *
	 * Anything not named in $aChanges keeps its current value, so callers can
	 * update one field without having to re-supply the secret.
	 *
	 * @param  array $aChanges url|user|password|root|poll*
	 * @throws \Exception when a password is supplied but there is no key to
	 *                    seal it with. Storing it in the clear instead would
	 *                    defeat the entire design, so this fails loudly.
	 */
	private function normalizeServerUrl(string $sUrl) : string
	{
		$sUrl = \trim($sUrl);
		if ('' === $sUrl) {
			return '';
		}

		$sUrl = \preg_replace('#/index\.php/?$#i', '', $sUrl);
		$sUrl = \rtrim($sUrl, '/');

		// Only http(s). Without this curl accepts file://, gopher:// and
		// dict://, which turns a plain text field into a read primitive.
		if (!\preg_match('#^https?://#i', $sUrl)) {
			throw new \Exception('The Nextcloud address must start with https:// or http://');
		}

		$sHost = (string) \parse_url($sUrl, PHP_URL_HOST);
		if ('' === $sHost) {
			throw new \Exception('That Nextcloud address has no host in it');
		}
		if ($this->isBlockedHost($sHost)) {
			throw new \Exception('That address points at a local or private network, which is not allowed');
		}

		return $sUrl;
	}

	/**
	 * Refuse the obvious internal targets.
	 *
	 * A guard, not a boundary: it cannot stop a public hostname that resolves
	 * to a private address, nor DNS rebinding, because curl resolves the name
	 * later. Since only authenticated mail users reach this, and upstream
	 * bodies are no longer echoed back, what remains is a blind port scan
	 * rather than data disclosure.
	 */
	private function isBlockedHost(string $sHost) : bool
	{
		$sHost = \strtolower(\trim($sHost, '[]'));

		// An administrator can opt in to internal addresses for a LAN Nextcloud.
		if ($this->Config()->Get('plugin', 'allow_private_hosts', false)) {
			return false;
		}

		if (\in_array($sHost, array('localhost', 'localhost.localdomain', '::1'), true)) {
			return true;
		}
		if (\preg_match('#\.(localhost|local|internal|intranet)$#', $sHost)) {
			return true;
		}

		if (\filter_var($sHost, FILTER_VALIDATE_IP)) {
			return !\filter_var(
				$sHost,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			);
		}

		return false;
	}

	private function saveSettings(array $aChanges) : void
	{
		$aNow = $this->settings();
		$aOut = array(
			'url'  => $this->normalizeServerUrl((string) ($aChanges['url'] ?? $aNow['url'])),
			'user' => \trim((string) ($aChanges['user'] ?? $aNow['user'])),
			'userId' => \trim((string) ($aChanges['userId'] ?? $aNow['userId'])),
			'root' => \trim((string) ($aChanges['root'] ?? $aNow['root']))
		);

		// Re-encrypting the current password on every save is what quietly
		// upgrades a legacy plaintext record to an encrypted one.
		$sPassword = \array_key_exists('password', $aChanges)
			? (string) $aChanges['password']
			: $aNow['password'];

		if ('' === $sPassword) {
			$aOut['password']     = '';
			$aOut['passwordHMAC'] = '';
		} else {
			$sKey = $this->cryptKey();
			if (!$sKey) {
				throw new \Exception('No encryption key available - refusing to store the credential');
			}
			$sEnc                 = \SnappyMail\Crypt::EncryptToJSON($sPassword, $sKey);
			$aOut['password']     = $sEnc;
			$aOut['passwordHMAC'] = \hash_hmac('sha1', $sEnc, $sKey);
		}

		// Carry the in-flight login-flow token across saves (see DoLoginStart).
		$aRaw = $this->getUserSettings();
		foreach (array('pollToken', 'pollEndpoint', 'pollStarted') as $sK) {
			if (\array_key_exists($sK, $aChanges)) {
				$aOut[$sK] = $aChanges[$sK];
			} else if (isset($aRaw[$sK])) {
				$aOut[$sK] = $aRaw[$sK];
			}
		}

		$this->saveUserSettings($aOut);
	}

	/**
	 * Endpoint: current state for the UI.
	 * The password is never returned - only whether we hold a working one.
	 */
	public function DoGetSettings()
	{
		$a = $this->settings();

		// Transparently upgrade a credential stored before encryption existed.
		// This is the only place it can happen: sealing needs CryptKey(), which
		// needs the user's live session, so it cannot be done by a migration
		// script. Saving with no changes re-writes the same password through
		// saveSettings(), which encrypts it on the way out.
		if (!empty($a['legacy']) && '' !== $a['password'] && $this->cryptKey()) {
			try {
				$this->saveSettings(array());
				$a = $this->settings();
			} catch (\Exception $e) {
				// Leave the legacy value alone rather than losing the credential.
			}
		}

		return $this->jsonResponse(__FUNCTION__, array(
			'url'       => $a['url'],
			'user'      => $a['user'],
			'root'      => $a['root'],
			'connected' => '' !== $a['url'] && '' !== $a['user'] && '' !== $a['password'],
			'brand'     => $this->Config()->Get('plugin', 'brand_name', 'Nextcloud'),
			// true when a credential is stored but could not be decrypted, i.e.
			// the mail password changed. The UI uses this to explain why a
			// reconnect is needed rather than just failing.
			'stale'     => '' !== $a['url'] && '' !== $a['user'] && '' === $a['password']
		));
	}

	/** Endpoint: save the non-secret preferences (currently the start folder). */
	public function DoSetSettings()
	{
		$this->saveSettings(array('root' => (string) $this->jsonParam('root', '')));

		return $this->jsonResponse(__FUNCTION__, true);
	}

	/**
	 * Endpoint: forget the stored credential.
	 *
	 * This only forgets it locally. The app password itself stays valid until
	 * the user revokes it in Nextcloud under Settings > Security, which is
	 * deliberate: deleting someone's credential on the server as a side effect
	 * of a UI button is more destructive than this button implies.
	 */
	public function DoDisconnect()
	{
		$this->saveSettings(array('user' => '', 'userId' => '', 'password' => ''));

		return $this->jsonResponse(__FUNCTION__, true);
	}

	// =======================================================================
	//  Nextcloud Login Flow v2
	// =======================================================================
	//
	// The same handshake the Nextcloud desktop and mobile clients use, so the
	// user never types a credential into SnappyMail:
	//
	//   1. POST {server}/index.php/login/v2
	//        -> { poll: { token, endpoint }, login: <browser URL> }
	//   2. The browser opens `login`. The user authenticates normally - through
	//      authentik here - and presses Grant access.
	//   3. POST {endpoint} with token=...
	//        -> 404 while still pending, 200 with { server, loginName,
	//           appPassword } once granted. The token is single use.
	//
	// The poll token never reaches the browser: it is held in this user's
	// server-side settings, and the front end only asks "are we there yet".
	//
	// Nextcloud names the generated app password after the User-Agent, so it
	// appears as its own revocable entry under Settings > Security.

	/**
	 * Guess a sensible default Nextcloud base URL from the current mail host.
	 *
	 * If the current host is mail.example.com or example.com we assume the user
	 * likely runs Nextcloud at cloud.example.com; if we cannot infer a domain,
	 * or the user wants a custom host, they can still edit the field manually.
	 */
	private function defaultCloudUrl() : string
	{
		$sHost = \trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
		$sHost = \preg_replace('#^https?://#i', '', $sHost);
		$sHost = \preg_replace('#/.*$#', '', $sHost);
		$sHost = \trim($sHost, '.');

		if ('' === $sHost || 'localhost' === strtolower($sHost) || '127.0.0.1' === $sHost) {
			return 'https://cloud.example.com';
		}

		$aParts = \explode('.', $sHost);
		if (count($aParts) <= 1) {
			return 'https://cloud.example.com';
		}

		$sDomain = count($aParts) > 2 ? \implode('.', \array_slice($aParts, 1)) : $sHost;
		return 'https://cloud.' . $sDomain;
	}

	/**
	 * Best-effort fallback for the canonical Nextcloud username when the login
	 * response is missing or incomplete. Many users' Nextcloud account names are
	 * the local-part of their mail address, e.g. alice@example.com ->
	 * alice.
	 */
	private function fallbackUserName() : string
	{
		$oActions = $this->Manager() ? $this->Manager()->Actions() : null;
		if (!$oActions) {
			return '';
		}

		$oMain = $oActions->getMainAccountFromToken(false);
		if (!$oMain) {
			return '';
		}

		$sEmail = '';
		if (method_exists($oMain, 'Email')) {
			$sEmail = (string) $oMain->Email();
		}
		if ('' === $sEmail && method_exists($oMain, 'GetEmail')) {
			$sEmail = (string) $oMain->GetEmail();
		}
		if ('' === $sEmail) {
			return '';
		}

		$sEmail = trim($sEmail);
		$parts = explode('@', $sEmail, 2);
		return trim((string) ($parts[0] ?? ''));
	}

	private function isLikelyUsername(string $sValue) : bool
	{
		$sValue = trim($sValue);
		if ('' === $sValue) {
			return false;
		}

		// DO NOT reject hash-like values here. On a Nextcloud that authenticates
		// through an identity provider (user_oidc for instance) the user ID really is
		// a 64-character hash - `occ user:list` shows
		//     b2b04eb1...: Alice Example
		// and that hash is exactly what /remote.php/dav/files/<id>/ expects,
		// while the friendly `alice` is only a login name and is NOT a valid
		// WebDAV path segment. Filtering hashes out made auth succeed and then
		// 404 on every browse.
		//
		// Reject only what genuinely cannot sit in a path segment. Everything
		// else is safe because the value is rawurlencode()d into the URL.
		if (false !== \strpos($sValue, '/')) {
			return false;
		}

		return true;
	}

	private function chooseUserName(array $aJson) : string
	{
		$sUser = (string) ($aJson['loginName'] ?? '');
		if (!$this->isLikelyUsername($sUser)) {
			$sUser = '';
		}
		if ('' === $sUser) {
			$sUser = (string) ($aJson['user'] ?? '');
			if (!$this->isLikelyUsername($sUser)) {
				$sUser = '';
			}
		}
		if ('' === $sUser) {
			$sUser = $this->fallbackUserName();
		}

		return $sUser;
	}

	private function resolveUserNameFromServer(string $sServer, string $sPreferredUser, string $sAppPassword) : string
	{
		$sServer = $this->normalizeServerUrl($sServer);
		if ('' === $sServer || '' === $sPreferredUser || '' === $sAppPassword) {
			return $sPreferredUser;
		}

		$sUrl = $sServer . '/ocs/v2.php/cloud/user';
		try {
			$aRes = $this->httpRequest('GET', $sUrl,
				array('OCS-APIRequest: true', 'Accept: application/json'),
				null,
				$sPreferredUser . ':' . $sAppPassword
			);
		} catch (\Exception $e) {
			return $sPreferredUser;
		}

		if (200 !== $aRes['code']) {
			return $sPreferredUser;
		}

		$aJson = \json_decode($aRes['body'], true);
		if (!is_array($aJson)) {
			return $sPreferredUser;
		}

		// `id` is what Nextcloud itself calls the user ID and is what the WebDAV
		// path is built from, so it wins outright when present. The other two are
		// only fallbacks for servers that do not report it.
		$sUser = \trim((string) (($aJson['ocs']['data']['id'] ?? '') ?: ($aJson['ocs']['data']['user'] ?? '') ?: ($aJson['ocs']['data']['loginName'] ?? '')));

		return '' !== $sUser ? $sUser : $sPreferredUser;
	}

	/** Endpoint: begin the flow, return the URL the browser should open. */
	public function DoLoginStart()
	{
		$sUrl = $this->normalizeServerUrl((string) $this->jsonParam('url', ''));
		if ('' === $sUrl) {
			$sUrl = $this->defaultCloudUrl();
		}

		if (!\preg_match('#^https?://[^/\s]+#i', $sUrl)) {
			return $this->jsonResponse(__FUNCTION__, array(
				'error' => 'Enter a valid Nextcloud address, e.g. https://cloud.example.com'
			));
		}

		$aRes = $this->httpRequest('POST', $sUrl . '/index.php/login/v2');

		if (200 !== $aRes['code']) {
			return $this->jsonResponse(__FUNCTION__, array(
				'error' => 'Nextcloud did not start a login (HTTP ' . $aRes['code'] . ')'
			));
		}

		$aJson = \json_decode($aRes['body'], true);
		if (empty($aJson['login']) || empty($aJson['poll']['token']) || empty($aJson['poll']['endpoint'])) {
			return $this->jsonResponse(__FUNCTION__, array('error' => 'Unexpected reply from Nextcloud'));
		}

		$this->saveSettings(array(
			'url'          => $sUrl,
			'pollToken'    => (string) $aJson['poll']['token'],
			'pollEndpoint' => (string) $aJson['poll']['endpoint'],
			'pollStarted'  => \time()
		));

		return $this->jsonResponse(__FUNCTION__, array('login' => (string) $aJson['login']));
	}

	/** Endpoint: has the user granted access yet? */
	public function DoLoginPoll()
	{
		$aRaw = $this->getUserSettings();

		$sToken    = (string) ($aRaw['pollToken'] ?? '');
		$sEndpoint = (string) ($aRaw['pollEndpoint'] ?? '');
		$iStarted  = (int) ($aRaw['pollStarted'] ?? 0);

		if ('' === $sToken || '' === $sEndpoint) {
			return $this->jsonResponse(__FUNCTION__, array('status' => 'none'));
		}

		// Nextcloud expires the token after 20 minutes; stop asking before then.
		if ($iStarted && \time() - $iStarted > 1200) {
			$this->clearPoll();
			return $this->jsonResponse(__FUNCTION__, array('status' => 'expired'));
		}

		$aRes = $this->httpRequest('POST', $sEndpoint, array(), \http_build_query(array('token' => $sToken)));

		// 404 is the documented "not granted yet" answer, not an error.
		if (404 === $aRes['code']) {
			return $this->jsonResponse(__FUNCTION__, array(
				'status'     => 'pending',
				'retryAfter' => isset($aRes['retryAfter']) && '' !== trim((string) $aRes['retryAfter'])
					? (int) trim((string) $aRes['retryAfter'])
					: 60
			));
		}

		// 429 means the poll endpoint is being rate-limited by Nextcloud. Do not
		// keep hammering the token endpoint; clear the in-flight state immediately
		// so the browser must start a fresh login instead of reusing a throttled
		// token.
		if (429 === $aRes['code']) {
			$this->clearPoll();
			return $this->jsonResponse(__FUNCTION__, array(
				'status'     => 'error',
				'error'      => 'Nextcloud rate-limited the login check. Please wait a minute and reconnect cleanly.',
				'retryAfter' => isset($aRes['retryAfter']) && '' !== trim((string) $aRes['retryAfter'])
					? (int) trim((string) $aRes['retryAfter'])
					: 60
			));
		}

		if (200 !== $aRes['code']) {
			$this->clearPoll();
			return $this->jsonResponse(__FUNCTION__, array('status' => 'error', 'error' => 'HTTP ' . $aRes['code']));
		}

		$aJson = \json_decode($aRes['body'], true);
		if (empty($aJson['appPassword'])) {
			$this->clearPoll();
			return $this->jsonResponse(__FUNCTION__, array('status' => 'error', 'error' => 'Nextcloud returned no credential'));
		}

		$sUser = $this->chooseUserName($aJson);
		if ('' === $sUser) {
			$this->clearPoll();
			return $this->jsonResponse(__FUNCTION__, array('status' => 'error', 'error' => 'Nextcloud returned no username'));
		}

		// `server` is what Nextcloud considers its own canonical URL. Prefer it
		// over whatever the user typed, but strip /index.php first so the
		// WebDAV root we later build is always the canonical base URL.
		$sServer = $this->normalizeServerUrl((string) ($aJson['server'] ?? ''));
		$sServer = '' !== $sServer ? $sServer : $this->normalizeServerUrl((string) ($aRaw['url'] ?? ''));

		// The value in `loginName` can be a display label or other human-facing
		// name. The actual WebDAV username is defined by the server, so query the
		// authenticated OCS user endpoint and use the canonical `id` when it is
		// available.
		$sResolvedUser = $this->resolveUserNameFromServer($sServer, $sUser, (string) $aJson['appPassword']);
		if ('' === $sResolvedUser) {
			$this->clearPoll();
			return $this->jsonResponse(__FUNCTION__, array('status' => 'error', 'error' => 'Nextcloud returned no valid username'));
		}

		$this->saveSettings(array(
			'url'          => $sServer,
			'user'         => $sResolvedUser,
			'userId'       => $sResolvedUser,
			'password'     => (string) $aJson['appPassword'],
			'pollToken'    => '',
			'pollEndpoint' => '',
			'pollStarted'  => 0
		));

		return $this->jsonResponse(__FUNCTION__, array('status' => 'ok'));
	}

	/** Drop an in-flight login attempt. */
	private function clearPoll() : void
	{
		$this->saveSettings(array('pollToken' => '', 'pollEndpoint' => '', 'pollStarted' => 0));
	}

	// =======================================================================
	//  HTTP plumbing
	// =======================================================================

	/**
	 * One HTTP call, with no credentials attached.
	 *
	 * Used for the Login Flow endpoints, which authenticate with a one-time
	 * token in the body rather than a password. Sending Basic auth to them
	 * would be pointless and would leak the credential to an endpoint that has
	 * no use for it.
	 *
	 * FOLLOWLOCATION is off on purpose throughout this plugin: a redirect would
	 * replay whatever we sent to wherever it points.
	 *
	 * The User-Agent matters - Nextcloud uses it to name the app password that
	 * Login Flow generates, so it shows up recognisably under Settings >
	 * Security rather than as something anonymous.
	 *
	 * @param  array $aHeaders extra headers, one string per line
	 * @param  mixed $mBody    request body, or null
	 * @return array{code:int,body:string}
	 * @throws \Exception on transport failure
	 */
	private function httpRequest(string $sMethod, string $sUrl, array $aHeaders = array(), $mBody = null, ?string $sAuth = null) : array
	{
		$ch = \curl_init($sUrl);

		$aOpts = array(
			CURLOPT_CUSTOMREQUEST  => $sMethod,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER         => true,
			CURLOPT_FOLLOWLOCATION => false,
			// Without these curl would also speak file://, gopher://, dict:// ...
			CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_TIMEOUT        => 20,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_USERAGENT      => 'SnappyMail (Nextcloud Files)',
			CURLOPT_HTTPHEADER     => $aHeaders
		);

		if (null !== $sAuth) {
			$aOpts[CURLOPT_USERPWD]  = $sAuth;
			$aOpts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
		}

		if (null !== $mBody) {
			$aOpts[CURLOPT_POSTFIELDS] = $mBody;
		}

		\curl_setopt_array($ch, $aOpts);

		$sResponse = \curl_exec($ch);
		$iCode     = (int) \curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$sError    = \curl_error($ch);
		\curl_close($ch);

		if (false === $sResponse) {
			// transport-level failure (DNS, TLS, timeout), distinct from an
			// HTTP error status, which the caller inspects itself
			throw new \Exception('Nextcloud request failed: ' . $sError);
		}

		$retryAfter = null;
		$parts = preg_split('/\r\n\r\n/', (string) $sResponse, 2);
		$sHeaderBlock = $parts[0] ?? '';
		$sBody = $parts[1] ?? '';
		if (preg_match('/^Retry-After:\s*(.+)$/mi', $sHeaderBlock, $m)) {
			$retryAfter = trim($m[1]);
		}

		return array(
			'code'       => $iCode,
			'body'       => (string) $sBody,
			'retryAfter' => $retryAfter
		);
	}

	/**
	 * One authenticated call to Nextcloud, using the stored app password.
	 *
	 * Nextcloud accepts an app password over Basic auth for both WebDAV and the
	 * OCS API, which is why a single helper serves both.
	 *
	 * @return array{code:int,body:string}
	 * @throws \Exception when unconfigured or the transport fails
	 */
	private function request(string $sMethod, string $sUrl, array $aHeaders = array(), $mBody = null) : array
	{
		$a = $this->settings();

		if ('' === $a['url'] || '' === $a['user'] || '' === $a['password']) {
			throw new \Exception('Nextcloud is not connected');
		}

		return $this->httpRequest($sMethod, $sUrl, $aHeaders, $mBody, $a['user'] . ':' . $a['password']);
	}

	/**
	 * The username the WebDAV path is built from.
	 *
	 * Returns the cached id when we have one; otherwise asks the server once
	 * via OCS and caches the answer. This exists so that an account connected
	 * before the id/login-name distinction was handled correctly repairs itself
	 * on the next browse, rather than needing the user to disconnect and log in
	 * again.
	 *
	 * Falls back to the stored login name, which is correct on a Nextcloud
	 * using local password auth, where the two are the same value.
	 */
	private function webdavUser() : string
	{
		$a = $this->settings();

		if ('' !== $a['userId']) {
			return $a['userId'];
		}

		if ('' === $a['url'] || '' === $a['user'] || '' === $a['password']) {
			return $a['user'];
		}

		$sResolved = $this->resolveUserNameFromServer($a['url'], $a['user'], $a['password']);

		if ('' !== $sResolved && $sResolved !== $a['userId']) {
			$this->saveSettings(array('userId' => $sResolved));
			return $sResolved;
		}

		return $a['user'];
	}

	// =======================================================================
	//  Browse
	// =======================================================================

	/**
	 * Endpoint: list one directory level.
	 *
	 * WebDAV, not the OCS API. PROPFIND with `Depth: 1` returns the requested
	 * collection *and* its immediate children, as a 207 Multi-Status XML body.
	 *
	 * Two things catch people out:
	 *   1. The FIRST <d:response> is the directory itself, not a child - it must
	 *      be skipped or every folder appears to contain itself.
	 *   2. Hrefs come back URL-encoded and absolute. We strip the base path and
	 *      rawurldecode to recover a path usable by the share endpoint.
	 */
	public function DoList()
	{
		$a     = $this->settings();
		$sPath = \trim((string) $this->jsonParam('path', ''), '/');

		// Per-user WebDAV root: /remote.php/dav/files/<username>/
		$sBase = $a['url'] . '/remote.php/dav/files/' . \rawurlencode($this->webdavUser()) . '/';

		// Encode each path segment separately so that '/' stays a separator
		$sUrl = $sBase . \implode('/', \array_map('rawurlencode', $this->safePathSegments($sPath)));
		if ('/' !== \substr($sUrl, -1)) {
			$sUrl .= '/';   // collections must be requested with a trailing slash
		}

		// Ask only for the properties we render, rather than allprop
		$sXml = '<?xml version="1.0"?>'
			. '<d:propfind xmlns:d="DAV:"><d:prop>'
			. '<d:displayname/><d:getcontentlength/><d:getcontenttype/>'
			. '<d:resourcetype/><d:getlastmodified/>'
			. '</d:prop></d:propfind>';

		$aRes = $this->request('PROPFIND', $sUrl, array('Depth: 1', 'Content-Type: application/xml'), $sXml);

		if (401 === $aRes['code'] || 403 === $aRes['code']) {
			$this->saveSettings(array(
				'user'         => '',
				'password'     => '',
				'passwordHMAC' => '',
				'root'         => '',
				'pollToken'    => '',
				'pollEndpoint' => '',
				'pollStarted'  => 0
			));
			return $this->jsonResponse(__FUNCTION__, array(
				'error' => 'Nextcloud rejected the stored credentials. Please reconnect.'
			));
		}

		// 207 Multi-Status is success here, not 200
		if (207 !== $aRes['code']) {
			return $this->jsonResponse(__FUNCTION__, array('error' => 'HTTP ' . $aRes['code']));
		}

		// Collect libxml's own complaint rather than just "it failed" - without
		// this, a malformed body is indistinguishable from an empty one.
		$bPrevErrors = \libxml_use_internal_errors(true);
		\libxml_clear_errors();

		$oXml = \simplexml_load_string((string) $aRes['body']);

		if (false === $oXml) {   // NOT !$oXml - a namespaced SimpleXMLElement casts to false
			$aErrors = \libxml_get_errors();
			$sReason = $aErrors ? \trim($aErrors[0]->message) : 'no parser error reported';
			\libxml_clear_errors();
			\libxml_use_internal_errors($bPrevErrors);

			$iLen  = \strlen((string) $aRes['body']);
			$sBody = \substr(\preg_replace('/\s+/', ' ', \trim((string) $aRes['body'])), 0, 300);

			// Everything is folded into `error` because that is the only field the
			// UI renders; url/body stay for anyone reading the raw JSON.
			return $this->jsonResponse(__FUNCTION__, array(
				// The upstream body is deliberately NOT returned. Echoing it turned a
				// user supplied address into a read primitive.
				'error' => 'Nextcloud returned a response that could not be parsed ('
					. $iLen . ' bytes; ' . $sReason . ').'
			));
		}

		\libxml_clear_errors();
		\libxml_use_internal_errors($bPrevErrors);
		$oXml->registerXPathNamespace('d', 'DAV:');

		$aItems  = array();
		$sPrefix = \parse_url($sBase, PHP_URL_PATH);   // e.g. /remote.php/dav/files/alice/
		$bFirst  = true;

		foreach ($oXml->xpath('//d:response') as $oResp) {
			if ($bFirst) {
				$bFirst = false;   // (1) skip the collection itself
				continue;
			}

			// namespaces must be re-registered on each sub-node
			$oResp->registerXPathNamespace('d', 'DAV:');

			$sHref = (string) $oResp->xpath('d:href')[0];
			$sRel  = \rtrim(\rawurldecode(\substr($sHref, \strlen($sPrefix))), '/');   // (2)
			if ('' === $sRel) {
				continue;
			}

			// A <d:collection/> inside resourcetype means "this is a folder"
			$bIsDir = !empty($oResp->xpath('d:propstat/d:prop/d:resourcetype/d:collection'));
			$aLen   = $oResp->xpath('d:propstat/d:prop/d:getcontentlength');
			$aMod   = $oResp->xpath('d:propstat/d:prop/d:getlastmodified');

			$aItems[] = array(
				'name'     => \basename($sRel),
				'path'     => $sRel,          // relative to the user's root
				'isDir'    => $bIsDir,
				'size'     => $bIsDir ? 0 : (int) ($aLen[0] ?? 0),
				'modified' => (string) ($aMod[0] ?? '')
			);
		}

		// Folders first, then alphabetical - the ordering people expect
		\usort($aItems, function ($x, $y) {
			if ($x['isDir'] !== $y['isDir']) {
				return $x['isDir'] ? -1 : 1;
			}
			return \strcasecmp($x['name'], $y['name']);
		});

		return $this->jsonResponse(__FUNCTION__, array('path' => $sPath, 'items' => $aItems));
	}

	// =======================================================================
	//  Share
	// =======================================================================

	/**
	 * Endpoint: create a public share link for one file.
	 *
	 * This is the OCS Share API, not WebDAV.
	 *
	 *   POST /ocs/v2.php/apps/files_sharing/api/v1/shares
	 *     path        - path relative to the user's root, leading slash required
	 *     shareType   - 3 = public link (0=user, 1=group, 4=email)
	 *     permissions - 1 = read only  (bitmask: 1 read, 2 update, 4 create,
	 *                   8 delete, 16 share)
	 *     expireDate  - optional, YYYY-MM-DD
	 *
	 * The `OCS-APIRequest: true` header is MANDATORY. Without it Nextcloud
	 * rejects the call outright - this is the single most common reason a first
	 * attempt at the OCS API fails.
	 *
	 * `Accept: application/json` is what makes it answer in JSON; the API
	 * defaults to XML otherwise.
	 *
	 * Every call creates a NEW share. Nextcloud does not deduplicate, so
	 * sharing the same file twice yields two links. Reusing an existing share
	 * would mean GET .../shares?path=... first - see Known gaps in the README.
	 */
	public function DoShare()
	{
		$a     = $this->settings();
		$sPath = '/' . \ltrim((string) $this->jsonParam('path', ''), '/');
		$sExp  = \trim((string) $this->jsonParam('expireDate', ''));

		// Default the share to one year. Nextcloud keeps a share for ever
		// unless told otherwise, and a link that outlives its purpose is the
		// main way these quietly turn into a liability. An explicit
		// expireDate from the client still wins.
		if ('' === $sExp) {
			$sExp = \gmdate('Y-m-d', \strtotime('+' . self::DEFAULT_EXPIRY_DAYS . ' days'));
		}

		if ('/' === $sPath) {
			return $this->jsonResponse(__FUNCTION__, array('error' => 'No file selected'));
		}

				// A password is always set: a bare public link is usable by anyone who
		// ever sees the URL. It is returned to the client and printed in the card.
		$sPw = $this->makeSharePassword();

		$aFields = array(
			'path'        => $sPath,
			'shareType'   => 3,   // public link
			'permissions' => 1,   // read only
			'password'    => $sPw
		);

		if ('' !== $sExp) {
			$aFields['expireDate'] = $sExp;
		}

		$aRes = $this->request(
			'POST',
			$a['url'] . '/ocs/v2.php/apps/files_sharing/api/v1/shares',
			array('OCS-APIRequest: true', 'Accept: application/json'),
			\http_build_query($aFields)
		);

		$aJson = \json_decode($aRes['body'], true);

		// The link lives at ocs.data.url; ocs.meta.message carries the reason on failure
		$sLink = isset($aJson['ocs']['data']['url']) ? $aJson['ocs']['data']['url'] : '';

		if ('' === $sLink) {
			$sMsg = isset($aJson['ocs']['meta']['message'])
				? $aJson['ocs']['meta']['message']
				: ('HTTP ' . $aRes['code']);
			return $this->jsonResponse(__FUNCTION__, array('error' => $sMsg));
		}

		return $this->jsonResponse(__FUNCTION__, array(
			'url'  => $sLink,
			'password' => $sPw,
			// Prefer what Nextcloud actually stored over what we asked for.
			'expires'  => isset($aJson['ocs']['data']['expiration'])
				? \substr((string) $aJson['ocs']['data']['expiration'], 0, 10)
				: $sExp,
			'name' => \basename($sPath),
			// id is returned so a future version can revoke the share
			'id'   => isset($aJson['ocs']['data']['id']) ? $aJson['ocs']['data']['id'] : null
		));
	}

	/**
	 * Share password: three mixed-case alphanumeric groups joined by hyphens,
	 * e.g. Kp7m-Qz9R-Zt4w. Upper + lower + digit + separator satisfies the usual
	 * Nextcloud password policies; I/l/1/O/0 are omitted so it can be read off a
	 * screen. random_int() because this is a credential, not a nonce.
	 */
	private function makeSharePassword() : string
	{
		$sUpper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
		$sLower = 'abcdefghijkmnpqrstuvwxyz';
		$sDigit = '23456789';
		$sPool  = $sUpper . $sLower . $sDigit;
		$aG = array();
		for ($g = 0; $g < 3; $g++) {
			$sGroup = '';
			for ($i = 0; $i < 4; $i++) {
				$sGroup .= $sPool[\random_int(0, \strlen($sPool) - 1)];
			}
			$aG[] = $sGroup;
		}
		// guarantee one of each class regardless of the draw
		$aG[0][0] = $sUpper[\random_int(0, \strlen($sUpper) - 1)];
		$aG[1][0] = $sLower[\random_int(0, \strlen($sLower) - 1)];
		$aG[2][0] = $sDigit[\random_int(0, \strlen($sDigit) - 1)];
		return \implode('-', $aG);
	}
}
