=== Jobbly ===
Contributors: william-dor
Tags: careers, jobs, recruitment, hiring, applications
Requires at least: 6.0
Tested up to: 6.8
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The complete hiring platform for WordPress — listings, applications, CV uploads, screening questions, and built-in email.

== Description ==

Jobbly is the complete hiring platform for WordPress. Publish job openings, collect applications with CV uploads and custom screening questions, and manage every applicant from a dedicated admin dashboard — without relying on third-party job boards or external mail plugins.

**For site owners**

* Create and manage job listings with location, type, salary, and application deadlines (date and time).
* Organize positions with the Departments taxonomy.
* Review applications in one place: filter by job or status, download CVs, and update applicant status.
* Send automatic HTML email notifications to admins and applicants, including status updates (pending, reviewing, interview, hired, rejected).
* Configure your own SMTP settings inside the plugin — no WP Mail SMTP or similar plugin required.

**For applicants**

* Browse open positions on a careers page (auto-created on activation).
* View job details and apply with a responsive application form.
* Upload a CV (PDF, DOC, or DOCX), optional or required portfolio link, cover letter, and job-specific screening questions.
* Receive a confirmation email when an application is submitted.

**Shortcodes**

* `[career_listings]` — displays all open job listings (optional `department="slug"` filter).
* `[career_apply]` — displays the application form on a job page or with `job_id="123"`.

**Built-in safeguards**

* Server-side validation for required fields, CV uploads, screening answers, and portfolio URLs.
* Application deadlines enforced on listings, job pages, and submissions.
* Duplicate applications blocked (same email + job within 24 hours).
* Secure CV storage outside the media library with protected upload directory.

== Installation ==

1. Upload the `career-portal` folder to `/wp-content/plugins/`, or install through the WordPress Plugins screen.
2. Activate **Jobbly** through the **Plugins** menu.
3. Go to **Settings → Permalinks** and click **Save Changes** once (registers job URLs).
4. Open **Jobbly → Settings** and:
   * Set the admin notification email.
   * Enable SMTP and enter your mail server details.
   * Save, then use **Send test email** to confirm delivery.
5. Visit **Jobbly → Job Listings** (or **Add New Job**) to publish your first position.
6. A **Careers** page with `[career_listings]` is created automatically on activation. Add `[career_apply]` to job content or rely on the plugin’s single-job template.

== Frequently Asked Questions ==

= Does this plugin require WP Mail SMTP or another mail plugin? =

No. Jobbly includes its own SMTP configuration under **Jobbly → Settings**. All application-related emails are sent through the plugin’s mailer. Other WordPress emails are unaffected.

= Where are uploaded CVs stored? =

Files are saved in `wp-content/career-portal-uploads/`. Direct web access is blocked via `.htaccess` and `index.php`. Admins download CVs through the dashboard only.

= Can I use my theme’s design for the careers page? =

Yes. The plugin works with any theme. Use the shortcodes on any page, or add `single-cp_job.php` in your theme to override the default job detail layout. If your theme does not provide that template, the plugin supplies one automatically.

= What happens when an application deadline passes? =

The job is removed from `[career_listings]`, the apply form is replaced with a closed message, and new submissions are rejected on the server.

= Can applicants apply to the same job more than once? =

Not within 24 hours using the same email address for the same job. After that window, another application is allowed.

= What application statuses are available? =

Pending, Reviewing, Interview, Hired, and Rejected. Changing status in the admin dashboard can email the applicant (with a confirmation prompt).

= Is data removed when I uninstall the plugin? =

Uninstalling deletes custom database tables, job posts, department terms, and plugin options. Uploaded CV files in `wp-content/career-portal-uploads/` are **not** deleted automatically so you can retain records if needed; remove that folder manually for a full purge.

== Screenshots ==

1. Job listings on the frontend careers page.
2. Single job page with application form.
3. Jobbly applications dashboard with filters and status controls.
4. Job editor with details, deadline, and screening questions.
5. Settings page with built-in SMTP configuration.

== Changelog ==

= 1.0.0 =
* Initial release of Jobbly.
* Job listings custom post type and Departments taxonomy.
* Application form with CV upload, portfolio link, cover letter, and screening questions.
* Admin dashboard for applications with status management and CV download.
* Built-in SMTP mailer with test email.
* HTML email notifications for new applications, confirmations, and status changes.
* Shortcodes `[career_listings]` and `[career_apply]`.
* Application deadlines with date and time (site timezone).
* Automatic Careers page creation and setup guidance on activation.
* Default single-job template when the theme does not provide one.

== Upgrade Notice ==

= 1.0.0 =
Initial release of Jobbly — the complete hiring platform for WordPress.
