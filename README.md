# SnappyMail Nextcloud File Sharing

<img width="1912" height="945" alt="image" src="https://github.com/user-attachments/assets/04a71cdd-65b4-4829-bccd-56b3612a7d24" />

## What this is

A SnappyMail plugin that attaches a file or folder from Nextcloud to an email as a share link, without downloading it first and without attaching anything to the message.

You get a button in the compose toolbar, a file picker, and a block at the bottom of your draft carrying the name, size, a download button, the share password and the expiry date. The share itself is created through Nextcloud's OCS API, password protected, read only, and expiring on a schedule you pick.

Authentication is Nextcloud Login Flow v2, the same handshake the desktop and mobile clients use, so your account password is never entered into SnappyMail. What gets stored is a dedicated app password, revocable under Settings then Security.

## Requirements

* SnappyMail 2.36 or newer. Built and tested against 2.38.2.
* Nextcloud reachable from wherever SnappyMail runs. Tested against Nextcloud 34.
* PHP with curl and SimpleXML.

## Installing

Drop the `nextcloud-files` folder into `_data_/_default_/plugins/`, then enable it under Extensions in the admin panel.

Keep the folder name. SnappyMail derives the class name from it, so `nextcloud-files` becomes `NextcloudFilesPlugin`, and it rejects anything outside `^[a-z0-9-]+$`.

<img width="860" height="532" alt="image" src="https://github.com/user-attachments/assets/c1b3c79e-d630-413e-979a-807cd291a65e" />

Then hard refresh. SnappyMail builds the plugin asset URL from `Class@VERSION`, not from file contents, so if you edit the plugin without bumping `VERSION` browsers will serve the old JavaScript indefinitely. This catches people out more than anything else in the plugin.

## Connecting

New message, Nextcloud button, then the cog. Enter the base address of your Nextcloud, `https://cloud.example.com` with nothing after it, and click Connect. Nextcloud opens its own window to grant access.

The start folder box sets where the picker opens. Leave it blank to start at the top.

## Using it

<img width="1602" height="931" alt="image" src="https://github.com/user-attachments/assets/e096033e-c9bf-40e8-acd7-8709485a6bbc" />

Click once to select, click again to act. A selected folder opens, a selected file shares. The arrow at the top goes up a level.

<img width="1586" height="928" alt="image" src="https://github.com/user-attachments/assets/bb899215-99b8-4c56-851d-226cfd42994e" />
<img width="1022" height="915" alt="image" src="https://github.com/user-attachments/assets/8e38066a-56d0-4e24-9ba9-4943c6c52f17" />

The two step click is what makes folder sharing possible. If one click navigated, a folder could never be the selected target.

The footer carries the expiry dropdown and a Share button, which stays disabled until something is selected and then names whether it will share a file or a folder.

Folders share the same way files do. A shared folder is browsable, including anything you add to it later.

Once shared, the block is ordinary HTML in your message. Move it, delete it, or type under it.

<img width="1912" height="945" alt="image" src="https://github.com/user-attachments/assets/04a71cdd-65b4-4829-bccd-56b3612a7d24" />

## Settings

Two, both in the admin panel under Extensions.

**Name shown on the card.** Leave it blank. The plugin reads the instance name from your Nextcloud's Theming settings, via `theming.name` in the capabilities endpoint, and caches it per user. Fill this in only to override that.

Note that `theming.name` is the field that carries branding. `theming.productName` and `status.php` both keep saying `Nextcloud` on a themed instance, which is why neither is used as the primary source.

**Allow a Nextcloud on a private network.** Off by default, which refuses localhost, link local and private ranges. Turn it on if your Nextcloud genuinely sits on a LAN address. See the security notes for what this is protecting against and what it is not.

Per user settings, including the credential and the cached instance name, live in SnappyMail's own user storage.

## Security notes

**The app password is encrypted at rest**, sealed with SnappyMail's `CryptKey()` and guarded by an HMAC so a key change is detected rather than silently decrypting to rubbish. Changing your mail password invalidates it, and the UI says to reconnect instead of failing obscurely.

**The address field is validated and curl is restricted** to HTTP and HTTPS. Both matter: without the protocol restriction curl will happily speak `file://` and `gopher://`, which turns a text field into a read primitive. Private and loopback addresses are refused unless an admin opts in.

**Upstream response bodies are never returned to the browser.** Errors carry a length and the parser's reason. An earlier version echoed the first 300 characters of the response, which combined with a user supplied address is a disclosure primitive.

**Redirects are never followed**, so a redirect cannot replay a request or its credentials elsewhere.

**Dot segments are stripped** before the WebDAV URL is built. `rawurlencode` leaves them intact and curl resolves them before sending, so without this a crafted path climbs above the user's own files.

Not solved: a public hostname resolving to a private address, and DNS rebinding, since curl resolves the name after the check. Closing that means inspecting the socket actually opened. With authenticated users only and no response bodies coming back, what remains is a blind port scan rather than a read.

## About the shares

Public links, with three things applied.

**A password** per share, printed on the block, shaped like `S5jr-akDr-3ZCY`. Fourteen characters, mixed case, at least one digit, with I, l, 1, O and 0 excluded so it survives being read off a screen.

**Read only**, so the link cannot be used to upload.

**An expiry** from the dropdown: 3 days, 7 days, 2 weeks, 1 month, 2 months, 3 months, 6 months, 1 year, or none. Defaults to a year, because Nextcloud keeps shares forever otherwise.

The date is calculated at the moment you share. The card shows the date read back from Nextcloud's response rather than the one requested, so a server that caps expiry by policy shows its real value.

<img width="686" height="806" alt="image" src="https://github.com/user-attachments/assets/e3adfa70-c32a-4361-9c08-461e997677bd" />

The password travels in the same email as the link, so anyone holding the message holds both halves. It defends against the URL leaking alone, in a log, a referrer header, or pasted somewhere careless. It is not access control. If you need the file restricted to named recipients, that is Nextcloud's email shares, which this plugin does not use because each recipient gets a distinct URL and one button in one message cannot serve several.

## Not implemented

* Every share is new. Sharing the same file twice creates two links rather than reusing the first.
* No revocation from inside SnappyMail. Use Nextcloud.
* No paging. Large folders arrive in one response.
* No multi select.
* `DEFAULT_EXPIRY_DAYS` in `index.php` sets the dropdown default.

## When it breaks

**An error in the picker** is Nextcloud's own message passed through. A rejected credential means the app password was revoked; reconnect through the cog.

**Nothing happens on selection.** Almost always the asset cache described in Installing. Bump `VERSION` and hard refresh.

**The block never reaches the message.** Insertion targets `.squire-wysiwyg` in HTML mode and `.squire-plain` in plain text. Both exist in the DOM at all times with only one visible, which is the trap: writing to the hidden one succeeds and shows nothing. If SnappyMail renames either, this breaks first.

**Share creation rejects the password.** Your Nextcloud enforces a stricter policy than the generated shape. `makeSharePassword()` in `index.php`.

## Licence

MIT. Copyright 2026 Alexander L. Penny. Full text in [LICENSE](LICENSE).

SnappyMail is AGPL 3.0 and some of its bundled plugins are MIT. Nextcloud imposes nothing here, since only its HTTP API is used.
