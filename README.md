# PaperPlane Mail Test

## The problem

When a WordPress site stops sending emails, nobody notices — until a client complains that their contact form hasn't been working for weeks, or a lead goes unanswered because the notification never arrived.

The cause can be anything: a misconfigured SMTP plugin, a hosting provider blocking port 25, a PHP update that broke a mail library. Whatever the reason, the failure is silent. By the time someone realises, business has already been lost — and someone has to take responsibility for it.

Plugins like WP Mail SMTP can signal a delivery problem on the dashboard, but they can't send an email alert when email is broken. That's the catch.

## The solution

PaperPlane Mail Test monitors the mail function on your client sites from a central installation. It periodically sends a test email through each site and checks whether `wp_mail()` succeeds. If it fails, you receive an alert immediately — before your client even notices.

---

WordPress plugin that periodically checks whether the mail function works on each monitored client site. Part of the PaperPlane mail monitoring system — install this on the central assistance site.

Each client site must have the **PaperPlane Mail Test Child** plugin installed and configured.

---

## Requirements

- WordPress 5.9+
- PHP 8.0+
- **PaperPlane Mail Test Child** installed on each site to monitor
- WP-Cron enabled, or a system cron calling `wp-cron.php`

---

## Installation

### 1. Upload the plugin

Upload the `paperplane-mail-test` folder to `/wp-content/plugins/` and activate it from the WordPress dashboard.

### 2. Configure notification recipients

Go to **Mail Monitor → Settings** and configure:

- **Alert recipient** — one or more addresses (comma-separated) that receive KO alerts, the weekly report, and manual test results
- **Silent test address** — a single address used for automatic cron test emails; use a dedicated mailbox with a delete-all rule to avoid inbox noise

### 3. Add monitored sites

Go to **Mail Monitor → Monitored Sites** and fill in the **Add site** form for each client site:

- **Name / label** — a friendly name (e.g. "Client Site")
- **Site URL** — must start with `https://`
- **Secret key** — copy it from **Tools → PaperPlane Mail Test** on the client site
- **Check frequency** — hourly or daily

---

## How it works

1. WP-Cron triggers a check at the configured frequency for each site
2. The plugin sends an authenticated `POST` request to `/wp-json/pp-mail-test/v1/check` on the client site
3. The child plugin runs `wp_mail()` and returns `true` or `false`
4. If the result is KO **and the previous check was OK** (state change only), an alert email is sent to the configured recipients

Authentication uses the secret key stored in `wp-config.php` on the client site. Keys are never stored in the database on the client side.

---

## Features

### KO alert email

Sent immediately when a site's mail function fails after a previously successful check. Includes site name, URL, error message, and timestamp.

### Silent test address

Automatic cron checks send the `wp_mail()` test to a dedicated address that silently discards incoming mail. This keeps `wp_mail()` fully exercised on every monitored site without generating inbox noise. Only KO alerts are forwarded to the alert recipient.

Manual checks triggered via "Check now" send the test to the alert recipient instead, so you can verify delivery end-to-end.

### Weekly summary report

An optional weekly email listing all monitored sites with their current status, last check time, and frequency. Configure day of the week and time of sending under **Mail Monitor → Settings → Weekly report**.

### Export / Import

Transfer the list of monitored sites between installations:

- **Export** — downloads a `.json` file containing all sites and their secret keys. Keep this file safe, as it contains credentials.
- **Import** — upload a previously exported file. Choose between replacing all existing sites or merging with them. The secret keys in the file must already be configured on the respective client sites.

---

## Updates

The plugin updates automatically from the WordPress dashboard via releases published on this repository.

---

## Related

- [PaperPlane Mail Test Child](https://github.com/paperplanefactory/paperplane-mail-test-child) — install on each monitored site

---

## Author

[Paper Plane Factory](https://paperplanefactory.com)
