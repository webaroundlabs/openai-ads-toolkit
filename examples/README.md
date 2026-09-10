# Examples

Small, complete illustrations of the two things this toolkit exists to get right:
firing at a **confirmed** conversion boundary, and using **one event id on both
sides** so a conversion measured twice is counted once.

| Example | Shows |
|---|---|
| [`php/send-lead.php`](php/send-lead.php) | The core with no framework. Any PSR-18 client. Uses validation mode, so running it cannot invent a conversion. |
| [`vanilla-js/index.html`](vanilla-js/index.html) | The browser half, taking its event id **from the server response** rather than minting one. |
| [`laravel/LeadController.php`](laravel/LeadController.php) | Recording after the lead is persisted, and handing the id back to the browser. |
| [`wordpress/measure-a-custom-form.php`](wordpress/measure-a-custom-form.php) | Measuring a form plugin the toolkit does not ship support for. |

These are illustrations rather than installable projects: they are linted in CI
but not executed, so they cannot quietly rot into something that no longer
parses, and they do not pin dependency versions that would go stale.
