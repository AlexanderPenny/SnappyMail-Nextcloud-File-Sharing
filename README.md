# SnappyMail Nextcloud File Sharing

## What this is

This is a plugin for [SnappyMail](https://snappymail.eu/) that lets you attach a file from your Nextcloud straight into an email you are writing, without downloading it first and without attaching anything to the message itself.

You click a button in the compose toolbar, browse your Nextcloud files in a small pop up, pick one, and the plugin drops a tidy block at the bottom of your draft. That block carries the file name, the size, a download button, and a password. Behind the scenes it has asked Nextcloud to create a share link for that file, protected by that password, set to read only, and set to expire in a year.

The point is that large files stop clogging up mailboxes. A 400 MB video becomes a link rather than an attachment that bounces, and the person receiving it gets something that looks deliberate rather than a bare URL pasted at the end of a sentence.

Your Nextcloud password is never typed into SnappyMail and never stored by it. The plugin uses Nextcloud's own Login Flow v2, which is the same handshake the desktop and mobile clients use. Nextcloud mints a dedicated app password for SnappyMail, and you can revoke it whenever you like under Settings, then Security, without touching your real password.

## What you need

* SnappyMail 2.36 or newer. Developed and tested against 2.38.2.
* A Nextcloud instance reachable from the machine SnappyMail runs on. Tested against Nextcloud 34.
* PHP with the curl and SimpleXML extensions, which SnappyMail images ship with anyway.

## Installing it

1. Copy the `nextcloud-files` folder into your SnappyMail data directory, under `_data_/_default_/plugins/`. If you run SnappyMail in a container, that path is inside the data volume rather than the image, so it survives updates.
2. Keep the folder name exactly as it is. SnappyMail turns the folder name into a class name, so `nextcloud-files` becomes `NextcloudFilesPlugin`, and it will refuse anything that does not match `^[a-z0-9-]+$`.
3. Open the SnappyMail admin panel, go to Extensions, and turn plugin support on if it is not on already.
4. Enable **Nextcloud Files** in the list.
5. Reload your webmail with a hard refresh.

That last step matters more than you would expect. SnappyMail builds the plugin asset URL from the plugin version rather than from the file contents, so browsers will happily serve yesterday's JavaScript until the version changes. There is no build step and nothing to compile.

## Connecting it to your Nextcloud

Open a new message, click the Nextcloud button in the compose toolbar, then click the cog in the pop up.

Type the address of your Nextcloud. Just the base address, so `https://cloud.example.com` and nothing after it. Click Connect and a Nextcloud window opens asking you to grant access. Approve it and you are done. The pop up starts showing your files straight away.

If you would rather the picker started somewhere other than the top of your files, put a folder name in the start folder box and save.

## Using it day to day

Write your email as normal, then click the Nextcloud button whenever you want to send something.

The picker works on a click once to select, click again to act basis. The first click highlights an entry. Clicking a highlighted folder goes into it, and clicking a highlighted file shares it. There is an arrow at the top to go back up a level.

That two step behaviour exists for a reason. If a single click on a folder went straight into it, there would be no way to ever pick a folder as the thing you want to share, so folders would be browsable and nothing more.

At the bottom of the picker there is a dropdown for how long the link should last and a Share button. The button stays greyed out until you have selected something, then tells you whether it is about to share a file or a folder. You can also just click your selection a second time, which does the same thing.

**You can share folders as well as files.** Worth thinking about before you do: a shared folder gives the recipient a browsable view of everything inside it, including anything you add to it later. That is usually what people want, but it is a wider grant than a single file.

Once shared, the block appears at the bottom of your draft. It is ordinary HTML sitting in your message, so you can move it, delete it, or carry on typing underneath it.

## Settings

There are two settings in the admin panel, under Extensions, in the plugin's own configuration.

**Name shown on the card.** This is the heading printed on the block that goes into your email. It defaults to `Nextcloud`. Set it to whatever you call your own instance. The family server this was written for calls itself Pennycloud, so that is the word its recipients see.

**Allow a Nextcloud on a private network.** Off by default. While it is off, the plugin refuses any address that resolves to localhost, a link local address, or a private range. That stops somebody with a mail account pointing the plugin at your internal network and using the mail server to knock on doors. Turn it on if your Nextcloud genuinely lives on a LAN address, and leave it off otherwise.

Everything else is stored per user, so each person connects their own Nextcloud account and nobody shares a credential.

## Security notes

Worth reading if you are deploying this somewhere that matters.

**The app password is encrypted at rest.** It is sealed with SnappyMail's own `CryptKey()`, which is derived from the user's mail password, and guarded with an HMAC so a key change is detected rather than silently producing rubbish. If you change your mail password the stored credential can no longer be unlocked, and the plugin tells you to reconnect instead of failing strangely.

**The address field is validated.** Only `http://` and `https://` are accepted, and curl is restricted to those two protocols. Without both of those checks a text field becomes a way to read local files, since curl will happily speak `file://` and `gopher://` if you let it. Private and loopback addresses are refused unless an administrator opts in, as described above.

**Upstream responses are never echoed back.** Errors report a length and the parser's own reason, and nothing else. An earlier version printed the first 300 characters of whatever came back, which combined with a user supplied address is a data disclosure waiting to happen.

**Redirects are not followed** anywhere in the plugin, so a redirect cannot replay a request, or its credentials, to somewhere else.

**Path segments are filtered.** Dot segments are dropped before the WebDAV URL is built, because `rawurlencode` leaves them alone and curl resolves them before sending, which would otherwise allow a crafted path to climb above your own files.

What is deliberately not solved: a public hostname that resolves to a private address will still be accepted, and so will DNS rebinding, because the name is resolved by curl after the check. Closing that properly means inspecting the socket that actually gets opened. Given only authenticated users reach this code and no response bodies come back, what remains is a blind port scan rather than a way to read anything.

## About the shares it creates

Every share the plugin makes is a public link with three things applied to it.

**A password**, generated fresh for each share and printed in the block. It looks like `S5jr-akDr-3ZCY`. Fourteen characters, mixed case, always at least one digit, and the ambiguous characters I, l, 1, O and 0 are left out so it can be read off a screen and typed without swearing.

**Read only permission**, so nobody can upload into your Nextcloud through the link.

**An expiry you choose**, from the dropdown in the picker: 3 days, 7 days, 2 weeks, 1 month, 2 months, 3 months, 6 months, 1 year, or no expiry at all. It defaults to a year, because Nextcloud keeps shares for ever unless you tell it otherwise, and a link that outlives the reason it existed is how these things quietly become a problem.

The date is worked out at the moment you share, so a link made today expires a year from today and one made tomorrow expires a year from tomorrow. They do not all pile up on the same date. The card prints when the link stops working, and that date is read back from Nextcloud's own response rather than from what the plugin asked for, so what you see is what the server actually stored.

It is worth being straight about the password though. It travels in the same email as the link, so anybody who receives or forwards that message has both halves. What it protects against is the link leaking on its own, in a server log, in a referrer header, or pasted somewhere it should not have been. That is a real improvement over a bare public link, but it is not the same as restricting the file to named people. If you need that, Nextcloud's email shares are the feature you want, and this plugin deliberately does not use them, because each recipient gets a different URL and one button in one email cannot serve all of them.

## Things it does not do yet

* It creates a fresh share every time. Sharing the same file twice gives you two links rather than reusing the first.
* There is no way to revoke a share from inside SnappyMail. Use the Nextcloud web interface.
* The default expiry, used when you do not touch the dropdown, is a constant near the top of `index.php` called `DEFAULT_EXPIRY_DAYS`.
* Large folders come back in one response. There is no paging.
* Only one item at a time. There is no multi select.

## If something is not working

**The picker opens but shows an error.** The text shown is whatever Nextcloud actually said, so it is usually enough to go on. A rejected credential means the app password was revoked, and reconnecting through the cog fixes it.

**Nothing happens when you pick a file.** Hard refresh first, for the version reason described above. If you have edited the plugin yourself, bump `VERSION` in `index.php` or browsers will keep the old copy.

**The block does not appear in the message.** The plugin writes into SnappyMail's editor, which is Squire, targeting `.squire-wysiwyg` in HTML mode and `.squire-plain` in plain text mode. Both of those elements exist in the page at all times and only one is visible, so if a future SnappyMail renames them, insertion is the first thing that will break.

**Share creation fails complaining about the password.** Your Nextcloud has a password policy stricter than the shape this generates. The generator is `makeSharePassword()` in `index.php` and it is about a dozen lines.

## Licence

MIT, matching the licence declared in the plugin metadata. There is no `LICENSE` file in the repository yet, so add one if you intend to rely on that.

SnappyMail itself is AGPL 3.0 and some of its bundled plugins are MIT. Nextcloud imposes nothing here, since only its HTTP API is used.
