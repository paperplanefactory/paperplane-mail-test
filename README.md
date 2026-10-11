# PaperPlane Mail Test

## The problem

When a WordPress site stops sending emails, nobody notices — until a client complains that their contact form hasn't been working for weeks, or a lead goes unanswered because the notification never arrived.

The cause can be anything: a misconfigured SMTP plugin, a hosting provider blocking port 25, a PHP update that broke a mail library. Whatever the reason, the failure is silent. By the time someone realises, business has already been lost — and someone has to take responsibility for it.

Plugins like WP Mail SMTP can signal a delivery problem on the dashboard, but they can't send an email alert when email is broken. That's the catch.

## The solution

PaperPlane Mail Test monitors the mail function on your client sites from a central installation. It periodically sends a test email through each site and checks whether `wp_mail()` succeeds. If it fails, you receive an alert immediately — before your client even notices.

## What it does not check

PaperPlane Mail Test verifies only that `wp_mail()` on the client site returns `true` — that is, WordPress and its mailer (SMTP plugin or PHP `mail()`) accepted the message and handed it to the sending server. An OK result does **not** mean the email reached the recipient's inbox.

The plugin does **not** check email deliverability, including:

- **Actual delivery** — whether the message reached the inbox, bounced, or landed in spam
- **Domain authentication** — SPF, DKIM and DMARC records of the sender domain
- **DNS configuration** — MX records, PTR / reverse DNS of the sending server
- **Reputation** — IP or domain blacklists, sender reputation
- **Message content** — headers, encoding, spam score

These checks are not part of the plugin. Use dedicated tools such as [mail-tester.com](https://www.mail-tester.com), [MXToolbox](https://mxtoolbox.com), [Google Postmaster Tools](https://postmaster.google.com) or DMARC aggregate reports.

---

WordPress plugin that periodically checks whether the mail function works on each monitored client site. Part of the PaperPlane mail monitoring system — install this on the central assistance site.

Each client site must have the **PaperPlane Mail Test Child** plugin installed and configured.

---

## Requirements

- WordPress 5.9+
- PHP 8.0+
- PHP OpenSSL extension (required for secret key encryption)
- `AUTH_KEY` defined in `wp-config.php` (present on all standard WordPress installations)
- **PaperPlane Mail Test Child** installed on each site to monitor
- WP-Cron enabled, or a system cron calling `wp-cron.php`

---

## Installation

### 1. Download the installable zip

Use **`paperplane-mail-test.zip`** — never GitHub's "Source code" archives.

**Direct download (always the latest version):**
[paperplane-mail-test.zip](https://github.com/paperplanefactory/paperplane-mail-test/releases/latest/download/paperplane-mail-test.zip)

```
https://github.com/paperplanefactory/paperplane-mail-test/releases/latest/download/paperplane-mail-test.zip
```

**Or from the releases page:**

1. Open the [latest release](https://github.com/paperplanefactory/paperplane-mail-test/releases/latest)
2. Scroll down and expand **Assets**
3. Download **`paperplane-mail-test.zip`**
4. Do **not** download "Source code (zip)" or "Source code (tar.gz)"

**How to recognise the right file:** it is named exactly `paperplane-mail-test.zip` (no version number) and contains a single folder named `paperplane-mail-test/`.

> **Why it matters.** The "Source code" archive contains a folder named after the version (e.g. `paperplane-mail-test-1.1.6`). WordPress identifies a plugin by its folder name, so each installation would end up in a differently named folder, and tools like MainWP would list it as a separate plugin. Updates keep the original folder name, so the problem never fixes itself.
>
> **Already installed from "Source code"?** Rename the folder via FTP to `paperplane-mail-test` (e.g. `paperplane-mail-test-1.1.6` → `paperplane-mail-test`), then reactivate the plugin from **Plugins**. Settings are stored in the database and are kept. Do not delete the old copy from the WordPress dashboard: deleting runs the uninstall routine, which removes the plugin's data.

### 2. Install and activate

- **Via WordPress dashboard** — go to **Plugins → Add New → Upload Plugin**, select `paperplane-mail-test.zip`, click **Install Now**, then **Activate**.
- **Via FTP** — extract the zip and upload the `paperplane-mail-test` folder to `/wp-content/plugins/`, then activate the plugin from **Plugins**.

### 3. Configure notification recipients

Go to **Mail Monitor → Settings** and configure:

- **Alert recipient** — one or more addresses (comma-separated) that receive KO alerts, the weekly report, and manual test results
- **Silent test address** *(optional)* — a single address used for automatic cron test emails. If left empty, each monitored site sends the test to its own admin email (**Settings → General** on the client site). A dedicated mailbox with a delete-all rule is recommended to avoid inbox noise

### 4. Add monitored sites

Go to **Mail Monitor → Monitored Sites** and fill in the **Add site** form for each client site:

- **Name / label** — a friendly name (e.g. "Client Site")
- **Site URL** — must start with `https://`
- **Secret key** — copy it from **Tools → PaperPlane Mail Test** on the client site
- **Check frequency** — hourly or daily

Click **Add site** to save it: the first automatic check runs at the next hourly cron run. Click **Add and verify** to save it and run a check immediately — the test email goes to the alert recipient, so you can confirm that URL and key are correct right away.

### 5. Edit a monitored site

Click **Edit** on a site to change its name, URL, frequency or secret key. The current secret key is never shown: leave **New secret key** empty to keep it, or paste a new one (for example after regenerating it on the client site). A new URL goes through the same validation used when adding a site.

When the URL or the key changes, the site's status is reset. **Save and verify** saves the changes and runs a check immediately, so you can confirm the new configuration works.

---

## How it works

1. WP-Cron triggers a check at the configured frequency for each site
2. The plugin sends an authenticated `POST` request to `/wp-json/pp-mail-test/v1/check` on the client site
3. The child plugin runs `wp_mail()` and returns `true` or `false`
4. If the result is KO **and the previous check was OK** (state change only), an alert email is sent to the configured recipients
5. When a site that was KO works again, a recovery email is sent

The state of each site is saved right after its check, and only one check run can be active at a time.

Authentication uses a secret key configured on each client site. The key is generated automatically by the child plugin and stored encrypted (AES-256-CBC) in the site's `wp_options` table.

---

## Features

### KO alert and recovery emails

- **KO alert** — sent when a site's mail function fails after a previously successful check. Includes site name, URL, cause, technical details and timestamp. No further alerts are sent while the site stays KO.
- **Recovery** — sent when a site that was KO works again, with the time it went KO and how long it lasted.

### When a site is down or returns an error

- **Not reachable** (timeout, connection refused, SSL error) and temporary errors (HTTP 502, 503, 504) are retried once after 5 seconds before the site is marked KO, so a short network glitch does not trigger an alert.
- Each request has a 15-second timeout and does not follow redirects.
- In **Mail Monitor → Monitored Sites**, every KO site shows the **cause**, technical details (server header, short excerpt of the response) and **since when** it has been KO. Typical causes:

| Cause shown | Meaning |
|---|---|
| Site not reachable | Timeout, connection refused or SSL error |
| The domain does not resolve (DNS) | Expired domain or DNS problem |
| HTTP 401 — Authentication refused | Wrong secret key, or too many calls in a short time |
| HTTP 401/403 — Access denied | A firewall or security plugin (e.g. Cloudflare, Wordfence) blocks the REST API |
| HTTP 404 — Endpoint not found | The child plugin is not active |
| HTTP 404 — REST API not reachable | REST API disabled or blocked |
| HTTP 3xx — The site redirects to … | The site URL changed: update it with **Edit** |
| HTTP 503 — Site unavailable | Maintenance mode or overloaded server |
| HTTP 5xx — Server error | For example a PHP error on the client site |
| Invalid response | HTTP 200 but not the expected data (cache plugin, maintenance page) |
| wp_mail() failed | The client site could not send the email: check its mail/SMTP configuration |
| Secret key can no longer be decrypted | The WordPress security keys (salts) changed: generate a new key on the client and/or re-enter it here |

### Secret key rotation

1. On the client site: **Tools → PaperPlane Mail Test → Generate new key**. The old key stops working immediately.
2. On this site: **Edit** the monitored site, paste the new key into **New secret key** and click **Save and verify**.

Between the two steps checks fail. Saving a new key resets the site status, so a check in that window does not leave the site stuck in KO.

**WordPress security keys (salts).** Secret keys are stored encrypted with a key derived from `AUTH_KEY`. If the salts are regenerated (for example after a security incident), the stored keys can no longer be decrypted:

- on a **client site**, the plugin shows an error on its page and in the dashboard — generate a new key and update it here;
- on **this site**, every monitored site shows "The secret key saved here can no longer be decrypted" — re-enter each key with **Edit**.

### Silent test address

Optional, at the discretion of whoever runs the monitor. When set, automatic cron checks send the `wp_mail()` test to this address — for example a dedicated mailbox that silently discards incoming mail. This keeps `wp_mail()` fully exercised on every monitored site without generating inbox noise. Only KO alerts are sent to the alert recipient.

When left empty, each monitored site sends the cron test to its own admin email (**Settings → General** on the client site). With hourly checks this means up to 24 test emails a day to that address, so consider who reads it.

Manual checks triggered via "Check now" send the test to the alert recipient instead, so you can check that the message arrives. If no alert recipient is configured, the test goes to the monitored site's admin email.

### Weekly summary report

An optional weekly email listing all monitored sites with their current status, last check time, and frequency. Configure day of the week and time of sending under **Mail Monitor → Settings → Weekly report**.

### Export / Import

Transfer the list of monitored sites between installations:

- **Export** — downloads a `.json` file containing all sites and their secret keys. Keep this file safe, as it contains credentials.
- **Import** — upload a previously exported file. Choose between replacing all existing sites or merging with them. The secret keys in the file must already be configured on the respective client sites.

---

## Multisite

The **PaperPlane Mail Test Child** plugin (installed on client sites) is fully compatible with WordPress Multisite. Each subsite in a network gets its own secret key and can be monitored independently — add each subsite as a separate entry in the monitor, using its own URL and key.

This plugin (installed on the central assistance site) does not need to run on a multisite — a single-site installation is sufficient.

---

## Updates

The plugin updates automatically from the WordPress dashboard via releases published on this repository.

---

## Related

- [PaperPlane Mail Test Child](https://github.com/paperplanefactory/paperplane-mail-test-child) — install on each monitored site

---

## Author

[Paper Plane Factory](https://paperplanefactory.com)
