# Surge Evaluation Popup

A WordPress plugin that shows a **$99 Electrical Safety Evaluation** lead popup to visitors in the Seattle metro area, based on their IP address. Each submission is:

1. saved to a local submissions log,
2. created in **Housecall Pro** as a customer and a lead, and
3. emailed to your team through **Mailgun**.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- A Mailgun account and sending domain
- A Housecall Pro API key. Creating leads requires the **MAX plan** and **API Leads** turned on in Job Inbox settings.

## Installation

1. Copy this folder to `wp-content/plugins/surge-evaluation-popup`.
2. Activate **Surge Evaluation Popup** under Plugins. Activation creates the `{prefix}surge_eval_leads` table.
3. Open **Evaluation Popup** in the admin menu and fill in the Mailgun and Housecall Pro tabs.
4. Add the shortcode to a page, or to a site-wide widget or footer:

```
[surge_evaluation_popup]
```

## Shortcode

Attributes are optional. Each one overrides the matching setting on that page only.

| Attribute | Values | Purpose |
|---|---|---|
| `trigger` | `immediate`, `delay`, `scroll`, `exit`, `delay_or_exit`, `manual` | When the popup opens |
| `delay` | seconds | Delay for `delay` / `delay_or_exit` |
| `scroll` | 1–100 | Scroll depth (%) for `scroll` |
| `geo` | `yes` / `no` | `no` skips location targeting on this page |
| `badge`, `headline`, `button` | text | Override the popup copy |

Any link or button with the class `surge-eval-open` (or `href="#surge-eval"`) opens the popup when clicked, whatever the visitor's location.

## How location targeting works

Pages are cached by WP Rocket/LiteSpeed, so the check can't happen while the page is built. Instead, the shortcode outputs hidden markup, and the script asks an uncached REST endpoint (`GET /wp-json/surge-eval/v1/geo`) whether to open it.

By default a visitor qualifies when both of these are true:

- their IP resolves to within **50 miles of downtown Seattle** (47.6062, -122.3321), and
- the location is in **Washington State**. This stops a large radius from reaching into British Columbia.

A list of always-allowed cities can override the radius. The center, the radius, the state rule and what happens when a lookup fails can all be changed on the Location Targeting tab.

At 50 miles, Everett, Tacoma, Puyallup, Marysville, North Bend, Bremerton and Olympia (~47 mi) are all inside. Lower the radius to about 45 miles to exclude Olympia.

**Lookup providers:** ipinfo.io (default; a token is recommended), ipapi.co, ip-api.com, or Cloudflare visitor location headers. Each IP is looked up once and then cached (168 hours by default).

**Proxies:** if the site sits behind Cloudflare or a load balancer, turn on *Trust proxy headers* so the plugin reads the real visitor IP.

## Configuration

Everything is on **Evaluation Popup** in the admin menu, split into these tabs:

| Tab | Settings |
|---|---|
| Display & Triggers | Enable, trigger, delay, scroll depth, days to stay hidden after close/submit, mobile auto-open |
| Content | All popup copy, call-us phone number, rating, trust line, success message |
| Location Targeting | Provider, token, center, radius, state rule, allowed cities, failure policy, proxy, cache |
| Mailgun | API key, domain, US/EU region, from address, recipients, subject (`{name}`, `{phone}`, `{zip}`, `{city}`) |
| Housecall Pro | API key, reuse existing customer by phone, create lead, lead source, tags |
| Testing | Test mode, debug logging, testing tools |
| Submissions | Every lead with its HCP/Mailgun status, plus retry and delete |

API keys can also be defined in `wp-config.php`. A key saved in the settings takes precedence.

```php
define( 'HCP_API_KEY', '...' );
define( 'SURGE_EVAL_MAILGUN_API_KEY', '...' );
```

## Testing

The **Testing** tab has these tools:

1. **Preview links.** They open a page that uses the shortcode:
   - `?surge_eval_preview=1` forces the popup open for admins.
   - `?surge_eval_test_ip=1.2.3.4` evaluates a simulated visitor IP.
   - `?surge_eval_reset=1` clears the browser's "closed/submitted" memory.

   When you're logged in as an admin, the geo result also appears in the browser console.
2. **IP lookup:** runs a live provider lookup, then applies the service-area rules.
3. **Service-area rules:** checks sample cities or custom coordinates without any API call.
4. **Integration checks:** sends a Mailgun test email, checks the Housecall Pro connection (read-only), and clears the geo cache.
5. **Full test submission:** runs the complete pipeline. The email subject starts with `[TEST]`. Creating real HCP records is opt-in, and those records are tagged `Test`.

**Test mode** can make the popup always show for logged-in admins, or for everyone (staging only).

A local dev site sees `127.0.0.1`, which can't be geolocated. Use a simulated IP there.

## Submission flow

The form posts to `POST /wp-json/surge-eval/v1/submit`, and the server then:

1. validates the name, a 10-digit US phone number and a 5-digit ZIP;
2. applies spam checks: a honeypot field, a minimum fill time, and a limit of 5 submissions per IP per hour (admins exempt);
3. saves the lead to `{prefix}surge_eval_leads`;
4. in **Housecall Pro**, finds a customer by phone or creates one, then creates a lead for that customer;
5. sends the **Mailgun** email, which links to the HCP customer.

If an integration fails, the lead stays in the log with the error message and can be retried from the Submissions tab. A retry only re-runs the steps that failed.

## Hooks

```php
// Adjust the Housecall Pro payloads.
add_filter( 'surge_eval_popup_hcp_customer_payload', function ( array $payload, array $lead ) { return $payload; }, 10, 2 );
add_filter( 'surge_eval_popup_hcp_lead_payload', function ( array $payload, array $lead ) { return $payload; }, 10, 2 );

// Runs after a lead is saved and delivered.
add_action( 'surge_eval_popup_lead_submitted', function ( array $lead ) {} );
```

The popup also pushes `surge_eval_popup_open` and `surge_eval_popup_submit` events to `window.dataLayer` for Google Tag Manager.

## Structure

```
surge-evaluation-popup.php       Bootstrap and autoloader
includes/
  Plugin.php                     Wires services together
  Settings.php                   Settings schema, defaults, sanitizing
  Admin/SettingsPage.php         Settings tabs, testing tools, submissions log
  Frontend/Shortcode.php         Popup markup
  Rest/Controller.php            /geo and /submit endpoints
  Geo/IpResolver.php             Visitor IP detection
  Geo/Locator.php                Provider lookups, caching, radius/state rules
  Leads/SubmissionHandler.php    Validate, store, deliver, retry
  Integrations/HousecallPro.php  Customer and lead API client
  Integrations/Mailgun.php       Messages API client
  Database/                      Table installer and repository
assets/
  css/popup.css, js/popup.js     Front end
  css/admin.css                  Admin screen
```
