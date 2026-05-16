# Career Portal — Comprehensive QA Test Plan

**Plugin under test:** Career Portal (WordPress)
**Author:** Senior QA Engineer
**Last updated:** 2026-05-16

Test IDs follow the pattern `CP-<AREA>-<NUM>` (e.g., `CP-04B-17`). All severity values assume the test *fails*.

---

## AREA 1 — Plugin Activation & Environment

| ID | Area | Type | Title | Preconditions | Steps | Expected Result | Severity if Fail |
|----|------|------|-------|---------------|-------|-----------------|------------------|
| CP-01-01 | Activation | POSITIVE | Fresh activation creates tables, upload dir, protection files | Clean WP install, plugin uploaded but not yet activated | 1. Plugins → Activate "Career Portal". 2. In phpMyAdmin verify `wp_cp_applications` and `wp_cp_screening_answers` exist with correct schema. 3. Check `wp-content/career-portal-uploads/` exists. 4. Confirm `.htaccess` (with `Deny from all` / `Require all denied`) and `index.php` stub inside the dir. 5. Visit a permalink — should not 404 (rewrite rules flushed). | Both tables created with all columns/indexes; upload dir present with both protection files; pretty permalinks for `cp_job` work without manual flush. | P0-Critical |
| CP-01-02 | Activation | EDGE | Re-activation is idempotent | Plugin currently active with ≥1 job and ≥1 application | 1. Deactivate plugin. 2. Reactivate plugin. 3. Inspect DB schema. 4. Check application data. | Tables not duplicated, no `ALTER TABLE` errors in debug log, all existing rows intact, dbDelta runs cleanly. | P0-Critical |
| CP-01-03 | Activation | POSITIVE | Deactivation preserves data, hides CPT/shortcodes | Plugin active with jobs + applications | 1. Plugins → Deactivate. 2. Confirm "Career Portal" menu removed. 3. Verify `wp_cp_applications` / `wp_cp_screening_answers` still in DB. 4. Load a page containing `[career_listings]` and `[career_apply]`. | Data persists; CPT menu gone; shortcodes return empty string (no fatal, no raw `[shortcode]` text). | P1-High |
| CP-01-04 | Activation | POSITIVE | Uninstall removes plugin data but keeps CV files | Plugin installed with data; CVs in upload dir | 1. Deactivate plugin. 2. Delete plugin via WP UI (triggers `uninstall.php`). 3. Inspect DB and filesystem. | Both custom tables dropped; all `cp_job` posts + postmeta deleted; `cp_admin_email` and any `cp_*` options removed; `career-portal-uploads/` directory **and CV files inside it remain**. | P0-Critical |
| CP-01-05 | Activation | EDGE | Plain permalinks gracefully degrade | Settings → Permalinks set to "Plain" | 1. Set permalinks to Plain. 2. Activate plugin. 3. View a job. | No fatal; admin notice shown explaining permalink requirement OR job URLs use `?p=` form without 404. | P2-Medium |
| CP-01-06 | Environment | ENVIRONMENT | PHP 7.4 activation | Server running PHP 7.4 | 1. Activate plugin. 2. Create + view a job. 3. Submit an application. 4. Tail `debug.log`. | No `Parse error`, no `syntax error`, no warnings about unsupported syntax. | P0-Critical |
| CP-01-07 | Environment | ENVIRONMENT | PHP 8.x — no deprecation warnings | PHP 8.2 with `WP_DEBUG=true`, `WP_DEBUG_LOG=true` | 1. Activate plugin. 2. Exercise smoke flow (create job, apply, change status). 3. Tail `debug.log`. | Zero `Deprecated:` notices originating from plugin files. | P1-High |
| CP-01-08 | Environment | EDGE | Missing `fileinfo` extension | Server without `fileinfo` enabled | 1. Disable `fileinfo` in php.ini. 2. Restart PHP. 3. Submit an application with a valid PDF. | No fatal; MIME validation falls back to extension check or `wp_check_filetype()`; valid CV accepted. | P1-High |
| CP-01-09 | Environment | EDGE | Multisite activation creates per-site tables | WP Multisite enabled, network install | 1. Network-activate (or per-site activate). 2. Switch between sites. 3. Inspect DB. | Each site has its own `wp_<N>_cp_applications` / `wp_<N>_cp_screening_answers`; no globally shared rows leaking between sites. | P1-High |
| CP-01-10 | Activation | NEGATIVE | Read-only uploads dir surfaces error | `wp-content/uploads/` set to `chmod 555` | 1. Make uploads dir non-writable. 2. Activate plugin. | Admin notice displayed describing the write failure; plugin still activates so admin can fix it; subsequent submissions surface the same error rather than white-screening. | P1-High |

---

## AREA 2 — Job Listings (Admin: Add/Edit Job)

| ID | Area | Type | Title | Preconditions | Steps | Expected Result | Severity if Fail |
|----|------|------|-------|---------------|-------|-----------------|------------------|
| CP-02-01 | Jobs Admin | POSITIVE | Create fully-populated job | Logged in as Administrator | 1. Career Portal → Add Job. 2. Fill title, content, location "Accra", type "Full-time", salary "GHS 8,000", deadline +30d, 3 screening questions, portfolio required = on. 3. Publish. 4. View on frontend. | Post saved; all metaboxes show on edit reload; frontend card shows all tags; apply form lists 3 screening questions + requires portfolio. | P0-Critical |
| CP-02-02 | Jobs Admin | EDGE | Minimal job (title only) | — | 1. Create job with only title. 2. Publish. 3. View `[career_listings]` page. | Card renders with title and Apply/View buttons only; no `(empty)` or `0` placeholders; meta row hidden. | P1-High |
| CP-02-03 | Jobs Admin | POSITIVE | Job with zero screening questions | — | 1. Create job, leave screening questions empty. 2. Publish. 3. Visit apply page. | Apply form renders standard fields; no "Screening Questions" heading shown. | P1-High |
| CP-02-04 | Jobs Admin | POSITIVE | Portfolio NOT required | — | 1. Create job with portfolio toggle off. 2. Apply with portfolio field blank. | Submission accepted. | P1-High |
| CP-02-05 | Jobs Admin | POSITIVE | Portfolio required | — | 1. Create job with portfolio toggle on. 2. Apply with portfolio blank. | Server rejects with a portfolio-required error message. | P0-Critical |
| CP-02-06 | Jobs Admin | EDGE | 20 screening questions saved/rendered | — | 1. Add 20 screening questions to a job. 2. Save. 3. Reload edit screen. 4. View apply form. | All 20 saved in order; all 20 render on frontend. | P2-Medium |
| CP-02-07 | Jobs Admin | EDGE | Duplicate question deduplication | — | 1. Add two identical screening questions "Why us?". 2. Save. 3. Reload. | Only one stored OR both stored — **document actual behavior**; expected: dedup. | P2-Medium |
| CP-02-08 | Jobs Admin | SECURITY | Special chars in screening question escaped | — | 1. Create question text `<script>alert(1)</script> & "test" [x]`. 2. Save and view in admin + frontend. | Rendered as escaped text in both contexts; no script executes; characters display literally. | P0-Critical |
| CP-02-09 | Jobs Admin | EDGE | Deadline = today | — | 1. Set deadline to today's date. 2. Attempt to apply. | **Documented behavior**: accepted (inclusive) — verify consistency with admin tooltip / docs. | P2-Medium |
| CP-02-10 | Jobs Admin | NEGATIVE | Deadline = yesterday | — | 1. Set deadline = today − 1. 2. Attempt to apply via shortcode and direct AJAX. | Form shows "Applications closed" / "Deadline passed" on both client + server. | P0-Critical |
| CP-02-11 | Jobs Admin | POSITIVE | Future deadline accepted | — | 1. Set deadline = today + 7. 2. Apply. | Submission accepted. | P1-High |
| CP-02-12 | Jobs Admin | EDGE | Blank deadline | — | 1. Leave deadline empty. 2. View listing card. 3. Apply. | No deadline tag on card; apply form accepts. | P2-Medium |
| CP-02-13 | Jobs Admin | NEGATIVE | Garbage deadline values | — | 1. Set deadline = `0000-00-00`, then `abc`, then `9999-13-40`. 2. Save each. 3. View frontend. | Sanitized to empty or rejected on save; no PHP warning; card and form do not crash. | P1-High |
| CP-02-14 | Jobs Admin | POSITIVE | Re-saving job preserves applications | Job exists with ≥1 application | 1. Edit job title/meta. 2. Save. 3. Inspect application list. | Application rows still link to job; no fields blanked. | P0-Critical |
| CP-02-15 | Jobs Admin | POSITIVE | Trashed job hidden from listings | Job published, then trashed | 1. Trash job. 2. View `[career_listings]`. 3. Visit cached apply page URL. | Job not in listings; apply form returns "No longer accepting applications". | P1-High |
| CP-02-16 | Jobs Admin | EDGE | Permanently deleted job → dashboard shows "Deleted" | Job + ≥1 application, then delete permanently | 1. Empty job from trash. 2. Open admin applications dashboard. | Linked applications still listed; position column shows "Deleted" / "—" rather than blank/fatal. | P1-High |

---

## AREA 3 — [career_listings] Shortcode (Frontend)

| ID | Area | Type | Title | Preconditions | Steps | Expected Result | Severity if Fail |
|----|------|------|-------|---------------|-------|-----------------|------------------|
| CP-03-01 | Listings | POSITIVE | Multiple published jobs render | ≥3 published jobs with meta | 1. Place `[career_listings]` on page. 2. View page. | All 3 cards render; each shows title, meta tags, excerpt, View Details + Apply Now. | P0-Critical |
| CP-03-02 | Listings | EDGE | Zero jobs empty state | No published jobs | 1. View `[career_listings]` page. | Friendly empty state, not a blank page or "Array" output. | P1-High |
| CP-03-03 | Listings | POSITIVE | Department filter (matching) | 2 jobs tagged Engineering, 1 Design | 1. Use `[career_listings department="engineering"]`. | Only the 2 Engineering jobs shown. | P1-High |
| CP-03-04 | Listings | EDGE | Department filter (no matches) | — | 1. `[career_listings department="nonexistent"]`. | Empty state, no PHP warning. | P2-Medium |
| CP-03-05 | Listings | POSITIVE | Empty department attribute = all jobs | — | 1. `[career_listings department=""]`. | All published jobs render. | P2-Medium |
| CP-03-06 | Listings | EDGE | Two `[career_listings]` on same page | — | 1. Page contains two instances. 2. Open DevTools console. | Both render; no duplicate-ID JS errors. | P2-Medium |
| CP-03-07 | Listings | UI/UX | Very long title (200+ chars) | Job titled with 250 chars | 1. View card. | Title wraps or truncates with ellipsis; card width unchanged; no horizontal scroll on page. | P2-Medium |
| CP-03-08 | Listings | UI/UX | Card without excerpt/content | Job with empty content | 1. View card. | No empty gap; spacing collapses cleanly. | P3-Low |
| CP-03-09 | Listings | UI/UX | Card with all meta fields | Job has location, type, salary, deadline | 1. View card. | All four meta tags render and are visually distinct (color/icon). | P2-Medium |
| CP-03-10 | Listings | UI/UX | Card with no meta fields | Job has no location/type/salary/deadline | 1. View card. | Meta row hidden entirely (not a blank flex row). | P2-Medium |
| CP-03-11 | Listings | UI/UX | Apply Now anchors `#cp-apply` | Job page contains both listing and apply | 1. Click "Apply Now". | Page smooth-scrolls to `#cp-apply` form. | P2-Medium |
| CP-03-12 | Performance | PERFORMANCE | Assets load only on shortcode pages | — | 1. View homepage (no shortcode) with Network tab open. 2. View shortcode page. | `career-portal.css/.js` absent on homepage; present on shortcode page. | P1-High |

---

## AREA 4 — [career_apply] Shortcode & Form

### 4A — Happy Path (POSITIVE)

| ID | Area | Type | Title | Preconditions | Steps | Expected Result | Severity if Fail |
|----|------|------|-------|---------------|-------|-----------------|------------------|
| CP-04A-01 | Apply Form | POSITIVE | Full valid submission | Active job with 3 screening Qs, portfolio required; MailHog running | 1. Fill all fields with valid data. 2. Attach 1MB valid PDF CV. 3. Submit. | Success message; row in `wp_cp_applications`; 3 rows in `wp_cp_screening_answers`; admin email + applicant email captured by MailHog. | P0-Critical |
| CP-04A-02 | Apply Form | POSITIVE | Portfolio blank when not required | Job with portfolio toggle off | 1. Submit valid app with portfolio blank. | Accepted; DB row has empty/NULL portfolio. | P1-High |
| CP-04A-03 | Apply Form | POSITIVE | Phone blank accepted | — | 1. Submit with phone empty. | Accepted; `phone` empty/NULL in DB. | P2-Medium |
| CP-04A-04 | Apply Form | POSITIVE | Cover letter blank accepted | — | 1. Submit with cover letter empty. | Accepted; section omitted from emails. | P2-Medium |
| CP-04A-05 | Apply Form | POSITIVE | .doc CV accepted | — | 1. Attach valid `.doc` file. 2. Submit. | Accepted; file stored; DB filename ends `.doc`; download works. | P1-High |
| CP-04A-06 | Apply Form | POSITIVE | .docx CV accepted | — | 1. Attach valid `.docx`. 2. Submit. | Accepted, same as above. | P1-High |
| CP-04A-07 | Apply Form | EDGE | 5MB PDF exactly | File size = 5,242,880 bytes | 1. Attach exact-5MB PDF. 2. Submit. | Accepted (boundary inclusive). | P1-High |
| CP-04A-08 | Apply Form | EDGE | Unicode applicant names | DB `utf8mb4` | 1. Submit as `José Müller`, `田中`, `Oluwatobi`. | All stored byte-perfect; admin email + dashboard display correctly. | P1-High |
| CP-04A-09 | Apply Form | EDGE | Single-word name salutation | — | 1. Submit as `Madonna`. 2. Read confirmation email. | Salutation reads `Hi Madonna,` (not `Hi ,` or `Hi !`). | P1-High |
| CP-04A-10 | Apply Form | EDGE | 5,000-char cover letter | — | 1. Paste 5,000 chars of Lorem. 2. Submit. | Stored complete (verify char count in DB); displays full in admin detail view. | P1-High |
| CP-04A-11 | Apply Form | EDGE | Screening answer with newlines + unicode | — | 1. Answer with multi-line text containing `é` and emoji. 2. Submit. | Stored verbatim; displayed with preserved line breaks (`nl2br` or `<pre>`); unicode intact. | P2-Medium |

### 4B — Required Field Validation (NEGATIVE)

| ID | Area | Type | Title | Preconditions | Steps | Expected Result | Severity if Fail |
|----|------|------|-------|---------------|-------|-----------------|------------------|
| CP-04B-12 | Apply Form | NEGATIVE | Blank full name | — | 1. Submit with name empty. | Client `required` blocks; if bypassed via DevTools, server returns JSON error. | P0-Critical |
| CP-04B-13 | Apply Form | NEGATIVE | Name = whitespace only | — | 1. POST `full_name="   "`. | Server rejects with "Name required". | P1-High |
| CP-04B-14 | Apply Form | NEGATIVE | Email blank | — | 1. Submit with email empty. | Rejected client + server. | P0-Critical |
| CP-04B-15 | Apply Form | NEGATIVE | Email = `not-an-email` | — | 1. Submit. | Rejected with "valid email" message. | P0-Critical |
| CP-04B-16 | Apply Form | NEGATIVE | Email = `a@` | — | 1. Submit. | Rejected. | P1-High |
| CP-04B-17 | Apply Form | NEGATIVE | Email = `@b.com` | — | 1. Submit. | Rejected. | P1-High |
| CP-04B-18 | Apply Form | NEGATIVE | No CV file selected (server) | — | 1. Remove `required` via DevTools, submit without CV. | Server rejects with "CV required" — not a silent save. | P0-Critical |
| CP-04B-19 | Apply Form | NEGATIVE | 0-byte CV | — | 1. Upload empty.pdf (0 bytes). | Rejected with file-size or file-empty error. | P1-High |
| CP-04B-20 | Apply Form | NEGATIVE | Blank portfolio on required job | Job requires portfolio | 1. Submit blank portfolio. | Rejected with clear "Portfolio URL required" message. | P0-Critical |
| CP-04B-21 | Apply Form | SECURITY | Portfolio = `javascript:alert(1)` | — | 1. Submit. | Rejected by URL validation; never stored. | P0-Critical |
| CP-04B-22 | Apply Form | NEGATIVE | Portfolio = `not-a-url` | — | 1. Submit. | Rejected. | P1-High |
| CP-04B-23 | Apply Form | NEGATIVE | Portfolio = `http://` | — | 1. Submit. | Rejected (scheme without host). | P2-Medium |
| CP-04B-24 | Apply Form | NEGATIVE | Skip required screening question | Job has required screening Qs | 1. Leave one question blank. 2. Submit. | Server rejects with message naming the missing question. | P0-Critical |
| CP-04B-25 | Apply Form | NEGATIVE | All screening answers = spaces | — | 1. Fill answers with `"   "`. 2. Submit. | Rejected on server. | P1-High |
| CP-04B-26 | Apply Form | NEGATIVE | Submission after deadline | Job deadline = yesterday | 1. Submit via AJAX. | Rejected with "Deadline passed". | P0-Critical |

### 4C — File Upload (NEGATIVE/EDGE)

| ID | Area | Type | Title | Preconditions | Steps | Expected Result | Severity if Fail |
|----|------|------|-------|---------------|-------|-----------------|------------------|
| CP-04C-27 | Upload | SECURITY | .exe renamed `.pdf` | — | 1. Rename `payload.exe` → `cv.pdf`. 2. Upload. | Rejected via MIME sniffing (`finfo`); file not stored. | P0-Critical |
| CP-04C-28 | Upload | SECURITY | .php renamed `.pdf` | — | 1. `shell.php` → `cv.pdf`. 2. Upload. | Rejected; even if accepted file must not be executable from upload dir (Area 7). | P0-Critical |
| CP-04C-29 | Upload | EDGE | .zip renamed `.docx` | — | 1. Upload renamed zip. | **Document actual behavior**: ideally rejected (true DOCX MIME = `application/vnd.openxmlformats…`). | P1-High |
| CP-04C-30 | Upload | NEGATIVE | Image (.jpg) as CV | — | 1. Upload `photo.jpg`. | Rejected with allowed-types message. | P1-High |
| CP-04C-31 | Upload | NEGATIVE | .txt CV | — | 1. Upload `notes.txt`. | Rejected. | P2-Medium |
| CP-04C-32 | Upload | NEGATIVE | 5MB + 1 byte | File = 5,242,881 bytes | 1. Upload. | Rejected with size message, not generic "upload failed". | P1-High |
| CP-04C-33 | Upload | NEGATIVE | 50MB file | — | 1. Upload 50MB file. | Rejected with friendly message; no white screen even if PHP `post_max_size` is hit (check JS for size pre-check). | P1-High |
| CP-04C-34 | Upload | NEGATIVE | No CV at all (bypass client) | — | 1. Strip the file field from POST. 2. Submit. | Server returns 400/JSON error; row not created. | P0-Critical |
| CP-04C-35 | Upload | EDGE | Upload dir unwritable at submit time | `chmod 555` on upload dir mid-test | 1. Submit valid app. | Graceful error, no fatal/white screen; nothing inserted into DB if file write failed (transactional). | P1-High |

### 4D — Security (SECURITY)

| ID | Area | Type | Title | Preconditions | Steps | Expected Result | Severity if Fail |
|----|------|------|-------|---------------|-------|-----------------|------------------|
| CP-04D-36 | Security | SECURITY | Missing nonce | — | 1. Strip nonce from POST. 2. Submit via curl. | 403 / nonce error; no DB write. | P0-Critical |
| CP-04D-37 | Security | SECURITY | Expired nonce | Session > 24h old | 1. Reuse a 25h-old nonce. | Rejected with nonce error. | P1-High |
| CP-04D-38 | Security | SECURITY | Duplicate rapid submissions | — | 1. Submit valid app. 2. Immediately resubmit twice (same email + job). | First accepted; 2nd & 3rd rejected as duplicate (DB unique constraint OR server check). | P0-Critical |
| CP-04D-39 | Security | POSITIVE | Same email, different job | Two jobs published | 1. Apply to Job A. 2. Apply to Job B with same email. | Both accepted (duplicate scoped to email+job_id). | P1-High |
| CP-04D-40 | Security | SECURITY | XSS in name | — | 1. Submit name `<script>alert(1)</script>`. 2. View in admin list + detail + emails. | Stored escaped or HTML-stripped; rendered as text everywhere; no script executes. | P0-Critical |
| CP-04D-41 | Security | SECURITY | XSS in cover letter | — | 1. Submit cover letter with `<img src=x onerror=alert(1)>`. 2. View in admin detail. | Escaped on output; no execution. | P0-Critical |
| CP-04D-42 | Security | SECURITY | XSS in screening answer | — | 1. Submit `<script>` payload as answer. 2. View admin detail. | Escaped; no execution. | P0-Critical |
| CP-04D-43 | Security | SECURITY | SQL injection in email | — | 1. Submit email `' OR 1=1--@x.com`. | Email validation rejects (`is_email()` false); nothing stored; no SQL error in log. | P0-Critical |
| CP-04D-44 | Security | SECURITY | CSRF — POST without valid nonce | — | 1. From external origin, POST to `admin-ajax.php?action=cp_submit_application`. | 403 / nonce failure. | P0-Critical |
| CP-04D-45 | Security | SECURITY | Tampered `cp_job_id` → draft post | Draft job exists | 1. Edit hidden field to draft job ID. 2. Submit. | Rejected with "job not available". | P1-High |
| CP-04D-46 | Security | SECURITY | Tampered `cp_job_id` → page ID | A WP Page exists | 1. Set `cp_job_id` to a page ID. 2. Submit. | Rejected (post type check). | P1-High |
| CP-04D-47 | Security | NEGATIVE | `cp_job_id=0` | — | 1. Submit with `cp_job_id=0`. | Rejected. | P1-High |
| CP-04D-48 | Security | NEGATIVE | `cp_job_id=-1` | — | 1. Submit with `-1`. | Rejected (`absint()` makes 0; same as above). | P1-High |
| CP-04D-49 | Security | SECURITY | Extra POST fields ignored | — | 1. Inject `&status=hired&id=1` extra fields. | Application saved with default status `pending`; injected fields ignored (whitelist on insert). | P0-Critical |

### 4E — Shortcode Context Edge Cases (EDGE)

| ID | Area | Type | Title | Preconditions | Steps | Expected Result | Severity if Fail |
|----|------|------|-------|---------------|-------|-----------------|------------------|
| CP-04E-50 | Apply Form | EDGE | `[career_apply]` on homepage | Homepage uses shortcode without `job_id` | 1. Visit homepage. | Shows clear message ("Choose a job to apply for"); does NOT silently bind to first/last job. | P0-Critical (B11) |
| CP-04E-51 | Apply Form | NEGATIVE | `[career_apply job_id="99999"]` non-existent | — | 1. Render. | Message "Job not found"; no fatal. | P1-High |
| CP-04E-52 | Apply Form | EDGE | Apply shortcode on non-job page no job_id | — | 1. Add shortcode to About page. | Friendly message, no form rendered, no PHP warning. | P1-High |
| CP-04E-53 | Apply Form | EDGE | Two `[career_apply]` on same page | — | 1. Page contains two shortcodes for different jobs. | Both forms work independently; element IDs unique (`#cp-apply-<jobid>`). | P2-Medium |
| CP-04E-54 | Apply Form | EDGE | Apply page after trash | Job trashed mid-session | 1. Trash job in another tab. 2. Submit existing apply form. | "No longer accepting" message; nothing stored. | P1-High |

---

## AREA 5 — Admin Dashboard — Applications List

### 5A — Display & Filtering

| ID | Area | Type | Title | Preconditions | Steps | Expected Result | Severity if Fail |
|----|------|------|-------|---------------|-------|-----------------|------------------|
| CP-05A-01 | Dashboard | POSITIVE | Zero applications empty state | No rows in `wp_cp_applications` | 1. Open Career Portal → Applications. | Empty state ("No applications yet"), no PHP notice. | P1-High |
| CP-05A-02 | Dashboard | POSITIVE | One application | 1 application | 1. Open dashboard. | Single row renders with name, email, position, status badge, date, Download CV. | P0-Critical |
| CP-05A-03 | Dashboard | POSITIVE | Pagination at 21+ | 21 applications | 1. Open dashboard. 2. Click page 2. | Pagination control visible; page 2 loads 1 record; query uses `LIMIT/OFFSET`. | P1-High |
| CP-05A-04 | Dashboard | POSITIVE | Filter by job | Multiple jobs with apps | 1. Pick job in dropdown. 2. Submit. | Only that job's applications. URL contains `?job_id=…`. | P1-High |
| CP-05A-05 | Dashboard | POSITIVE | Filter by status Pending | Mixed statuses | 1. Pick "Pending". | Only pending rows. | P1-High |
| CP-05A-06 | Dashboard | POSITIVE | Filter by job + status combined | — | 1. Pick both. | Intersection only. | P1-High |
| CP-05A-07 | Dashboard | EDGE | Filter combo with zero matches | — | 1. Pick combo with no rows. | Empty state, no error. | P2-Medium |
| CP-05A-08 | Dashboard | UI/UX | App with no CV → no download button | App row with NULL `cv_path` | 1. View row. | Download button hidden or disabled. | P1-High |
| CP-05A-09 | Dashboard | POSITIVE | App with CV → button works | — | 1. Click Download CV. | File downloads; correct filename. | P0-Critical |
| CP-05A-10 | Dashboard | UI/UX | Long applicant name in table | 200-char name | 1. View row. | Truncation or wrap; no overflow into other columns. | P2-Medium |
| CP-05A-11 | Dashboard | UI/UX | Status badge colors | Apps in all 5 statuses | 1. View list. | Each status visually distinct. | P2-Medium |
| CP-05A-12 | Dashboard | UI/UX | Date formatting | — | 1. View column. | Human-readable (e.g. `May 16, 2026 2:14 pm`), not raw `2026-05-16 14:14:23`. | P3-Low |

### 5B — Status Updates

| ID | Area | Type | Title | Preconditions | Steps | Expected Result | Severity if Fail |
|----|------|------|-------|---------------|-------|-----------------|------------------|
| CP-05B-13 | Status | POSITIVE | Pending → Reviewing | MailHog on | 1. Change dropdown. 2. Confirm modal. 3. Wait. | Badge updates without reload; DB row `status='reviewing'`; applicant email captured. | P0-Critical |
| CP-05B-14 | Status | POSITIVE | Reviewing → Interview | — | As above. | Interview email captured. | P1-High |
| CP-05B-15 | Status | POSITIVE | Interview → Hired | — | As above. | Hired email captured. | P1-High |
| CP-05B-16 | Status | POSITIVE | Hired → Rejected | — | As above. | Rejected email captured. | P1-High |
| CP-05B-17 | Status | EDGE | Same status reselected | — | 1. Reselect current status. | No DB write, no email, optional toast "no change". | P1-High |
| CP-05B-18 | Status | POSITIVE | Rejected → Pending uses "reopened" copy | — | 1. Change. | Email body reads "Your application has been reopened" (not generic pending). | P2-Medium |
| CP-05B-19 | Status | NEGATIVE | AJAX network failure | DevTools offline | 1. Toggle offline. 2. Change status. | Visible alert/inline error; dropdown reverts to previous value; no silent fail. | P1-High |
| CP-05B-20 | Concurrency | EDGE | Two admins update same row | Two browsers logged in as admin | 1. Both pick a status. 2. Both confirm. | Last write wins; no PHP fatal; both UIs eventually reflect server state on reload. | P1-High |
| CP-05B-21 | Status | UI/UX | Confirm dialog content | — | 1. Pick a status. | Dialog names applicant + new status; Cancel aborts (no email, no DB write); confirm proceeds. | P0-Critical (B6) |

### 5C — Security

| ID | Area | Type | Title | Preconditions | Steps | Expected Result | Severity if Fail |
|----|------|------|-------|---------------|-------|-----------------|------------------|
| CP-05C-22 | Security | SECURITY | Subscriber blocked from dashboard | Subscriber user | 1. Login as Subscriber. 2. Visit `admin.php?page=career-portal`. | `wp_die("permission")`. | P0-Critical |
| CP-05C-23 | Security | SECURITY | Editor blocked | Editor user | Same. | Blocked. | P0-Critical |
| CP-05C-24 | Security | SECURITY | Non-admin blocked from download | Editor user | 1. Visit `admin-post.php?action=cp_download_cv&id=1`. | 403 / wp_die. | P0-Critical |
| CP-05C-25 | Security | SECURITY | Logged-out CV download | Not logged in | 1. Visit download URL. | Redirect to login or 403. | P0-Critical |
| CP-05C-26 | Security | SECURITY | Multisite CV cross-site | Two sites in network | 1. As Admin of Site A, request Site B's app ID via download URL. | Blocked or scoped — admin cannot fetch other site's CV. | P0-Critical |
| CP-05C-27 | Security | SECURITY | IDOR — incrementing app IDs | Logged-in admin | 1. Walk `?paged=` and detail IDs. | All show within current site only; deleted IDs return "not found"; no cross-tenant leak. | P0-Critical |
| CP-05C-28 | Security | SECURITY | SQL injection in `?status=` | — | 1. Visit `?status=' OR 1=1--`. | Sanitized via allowlist; query returns empty or all (per allowlist default). No SQL error. | P0-Critical |
| CP-05C-29 | Security | SECURITY | SQL injection in `?job_id=` | — | 1. Visit `?job_id=' OR 1`. | `absint()` → 0; treated as "all jobs"; no error. | P0-Critical |
| CP-05C-30 | Security | EDGE | `?paged=0` | — | 1. Visit. | Defaults to page 1; no fatal. | P2-Medium |
| CP-05C-31 | Security | EDGE | `?paged=-1` | — | 1. Visit. | Defaults to 1. | P2-Medium |
| CP-05C-32 | Security | EDGE | `?paged=99999` | — | 1. Visit. | Empty table, pagination still functional. | P2-Medium |

---

## AREA 6 — Single Application View (Admin)

| ID | Area | Type | Title | Preconditions | Steps | Expected Result | Severity if Fail |
|----|------|------|-------|---------------|-------|-----------------|------------------|
| CP-06-01 | Detail View | POSITIVE | All fields render | Full application | 1. Open detail. | Name, email, phone, position, portfolio (clickable), submitted date, cover letter, screening Q&A all visible and correct. | P0-Critical |
| CP-06-02 | Detail View | POSITIVE | CV filename format | App with PDF | 1. Click Download CV. | Downloaded as `Firstname_Lastname_CV.pdf` with correct extension. | P1-High |
| CP-06-03 | Detail View | UI/UX | Missing phone shows `—` | App without phone | 1. Open detail. | Em-dash placeholder, not blank or `null`. | P2-Medium |
| CP-06-04 | Detail View | UI/UX | Missing portfolio shows `—` | App without portfolio | 1. Open detail. | Em-dash, no broken `<a href="">`. | P2-Medium |
| CP-06-05 | Detail View | UI/UX | No cover letter → section hidden | — | 1. Open detail. | Cover letter heading absent. | P2-Medium |
| CP-06-06 | Detail View | UI/UX | No screening answers → section hidden | Job without screening Qs | 1. Open detail. | Screening section absent. | P2-Medium |
| CP-06-07 | Detail View | POSITIVE | Long screening answers display fully | 10,000-char answer | 1. Open detail. | Full text scrollable/wrapped, not truncated with "…". | P1-High |
| CP-06-08 | Detail View | POSITIVE | Status dropdown updates + emails | — | 1. Change status on detail. | DB updated, email sent. | P1-High |
| CP-06-09 | Detail View | UI/UX | Live badge color refresh | — | 1. Change status. | Badge color updates without reload. | P2-Medium |
| CP-06-10 | Detail View | UI/UX | Back link preserves filters | Came from `?status=hired` | 1. Click Back. | Returns to filtered list (`?status=hired`). | P2-Medium |
| CP-06-11 | Detail View | NEGATIVE | View ID=0 | — | 1. Visit `&id=0`. | "Application not found" message. | P1-High |
| CP-06-12 | Detail View | NEGATIVE | View deleted ID | App since deleted | 1. Visit detail URL. | "Application not found". | P1-High |

---

## AREA 7 — CV Download

| ID | Area | Type | Title | Preconditions | Steps | Expected Result | Severity if Fail |
|----|------|------|-------|---------------|-------|-----------------|------------------|
| CP-07-01 | CV | POSITIVE | Download valid PDF | — | 1. Click Download. | File downloads; content matches uploaded; filename `<Name>_CV.pdf`. | P0-Critical |
| CP-07-02 | CV | POSITIVE | Download .docx | — | 1. Download. | Correct extension preserved. | P1-High |
| CP-07-03 | CV | POSITIVE | Filename sanitization | Applicant name `José/Smith?` | 1. Download. | Filename sanitized: `Jose_Smith_CV.pdf`; no slashes/specials. | P1-High |
| CP-07-04 | CV | NEGATIVE | Download for app with no CV | App with NULL path | 1. Hit `admin-post.php?action=cp_download_cv&id=…`. | "File not found" / wp_die. | P1-High |
| CP-07-05 | CV | NEGATIVE | File deleted from disk | DB row exists, file removed manually | 1. Click Download. | "File not found on server" — no PHP warning, no 0-byte file served. | P1-High |
| CP-07-06 | CV | SECURITY | Content-Disposition: attachment | — | 1. Inspect response headers. | `Content-Disposition: attachment; filename=…`; not `inline`. | P1-High |
| CP-07-07 | CV | SECURITY | Direct URL access blocked | Apache | 1. Visit `<site>/wp-content/career-portal-uploads/<file>.pdf`. | 403 Forbidden from `.htaccess`. | P0-Critical (B8) |
| CP-07-08 | CV | SECURITY | Guess filename in browser | — | 1. Guess names like `cv-1.pdf`. | 403 even on correct guesses. | P0-Critical |
| CP-07-09 | CV | EMAIL | CV link from admin email (logged in) | — | 1. Click link in admin email while logged in. | Download succeeds. | P1-High |
| CP-07-10 | CV | EMAIL | CV link from admin email (logged out) | — | 1. Click link logged out. | Redirect to `wp-login.php`; after login → file. | P1-High |

---

## AREA 8 — Email Notifications

### 8A — Submission Emails

| ID | Area | Type | Title | Preconditions | Steps | Expected Result | Severity if Fail |
|----|------|------|-------|---------------|-------|-----------------|------------------|
| CP-08A-01 | Email | EMAIL | Admin email full payload | MailHog | 1. Submit valid app. | Admin email contains name, email, phone, position, portfolio, submitted date, cover letter, screening Q&A, CV download link, dashboard link. | P0-Critical |
| CP-08A-02 | Email | EMAIL | Applicant confirmation payload | — | 1. Submit. | Email contains position title, application ID, submitted date, company/site name. | P0-Critical |
| CP-08A-03 | Email | EMAIL | Sent to submitted email | — | 1. Submit with `tester@example.com`. | MailHog shows To = `tester@example.com`. | P1-High |
| CP-08A-04 | Email | EMAIL | Admin email uses `cp_admin_email` setting | Setting differs from `admin_email` | 1. Set custom email in settings. 2. Submit. | Sent to setting value, not `get_option('admin_email')`. | P1-High |
| CP-08A-05 | Email | EMAIL | Renders in Gmail + Outlook | Gmail + Outlook account | 1. Re-route via real SMTP. | Renders cleanly (no broken layout, images allowed). | P2-Medium |
| CP-08A-06 | Email | EMAIL | Content-Type HTML | — | 1. Inspect email headers. | `Content-Type: text/html; charset=UTF-8`. | P1-High |
| CP-08A-07 | Email | SECURITY | HTML escaped in body | Job title `<b>X & Co</b>` | 1. Submit; view email. | Title shown as literal text, no rendered tags; `&` preserved. | P0-Critical |
| CP-08A-08 | Email | EMAIL | First-name salutation | Name = `Maria Garcia Lopez` | 1. Submit; view applicant email. | Salutation `Hi Maria,`. | P2-Medium |
| CP-08A-09 | Email | EMAIL | Dashboard CTA link | — | 1. Click "View in Dashboard" in admin email. | Lands on the specific application detail page. | P2-Medium |
| CP-08A-10 | Email | EDGE | Mail failure does not block save | Force `wp_mail` to return false (filter) | 1. Submit. | App still saved to DB; admin sees standard success response (logging optional). | P1-High |

### 8B — Status Change Emails

| ID | Area | Type | Title | Preconditions | Steps | Expected Result | Severity if Fail |
|----|------|------|-------|---------------|-------|-----------------|------------------|
| CP-08B-11 | Email | EMAIL | Reviewing email copy | — | 1. Change to reviewing. | Body uses the "we're reviewing" template. | P1-High |
| CP-08B-12 | Email | EMAIL | Interview email copy | — | 1. Change to interview. | Body uses interview template. | P1-High |
| CP-08B-13 | Email | EMAIL | Hired email copy | — | 1. Change to hired. | Body uses hired template. | P1-High |
| CP-08B-14 | Email | EMAIL | Rejected email copy | — | 1. Change to rejected. | Body uses rejected template (polite, no exposed PII of others). | P1-High |
| CP-08B-15 | Email | EMAIL | Rejected → Pending reopens | — | 1. Move rejected → pending. | "Application reopened" copy, not generic pending. | P2-Medium |
| CP-08B-16 | Email | EMAIL | Same → same status: no email | — | 1. Select current status. | MailHog records nothing new. | P2-Medium |
| CP-08B-17 | Email | EDGE | Plus-tag emails delivered | — | 1. Submit `user+test@gmail.com`. | Delivered to that exact address. | P2-Medium |
| CP-08B-18 | Email | EDGE | Self-review scenario | Admin email = applicant email | 1. Submit using admin's address. | Both notifications still sent (or single combined — document); no dedup that drops one. | P2-Medium |
| CP-08B-19 | Email | SECURITY | Job title `&`/`<` escaped in subject + body | Title `M & A <Lead>` | 1. Trigger any status email. | Subject + body show literal text; no broken markup; no encoding artifacts (`&amp;amp;`). | P1-High |

---

## AREA 9 — Settings Page

| ID | Area | Type | Title | Preconditions | Steps | Expected Result | Severity if Fail |
|----|------|------|-------|---------------|-------|-----------------|------------------|
| CP-09-01 | Settings | POSITIVE | Save valid email | — | 1. Set `hr@acme.com`. 2. Save. 3. Submit an application. | Setting persists; admin email goes to `hr@acme.com`. | P1-High |
| CP-09-02 | Settings | NEGATIVE | Invalid email rejected | — | 1. Save `not-an-email`. | Rejected with inline error OR sanitized to empty. | P1-High |
| CP-09-03 | Settings | EDGE | Empty email falls back to `admin_email` | — | 1. Clear field. 2. Save. 3. Submit app. | Notification sent to `get_option('admin_email')`. | P1-High |
| CP-09-04 | Settings | UI/UX | Shortcodes reference table renders | — | 1. Open Settings. | Both shortcodes listed with usage hints; copy-friendly. | P3-Low |
| CP-09-05 | Settings | SECURITY | Nonce required to save | — | 1. POST settings form with bad `_wpnonce`. | Rejected, settings unchanged. | P1-High |
| CP-09-06 | Settings | SECURITY | Non-admin blocked | Editor user | 1. Visit settings URL. | wp_die / access denied. | P0-Critical |

---

## AREA 10 — UI/UX Quality

### 10A — Frontend Form

| ID | Area | Type | Title | Preconditions | Steps | Expected Result | Severity if Fail |
|----|------|------|-------|---------------|-------|-----------------|------------------|
| CP-10A-01 | UI | UI/UX | Desktop layout 1280px | — | 1. Open form at 1280px. | Form fits with comfortable spacing; two-column where designed. | P2-Medium |
| CP-10A-02 | UI | UI/UX | Tablet layout 768px | — | 1. Resize. | Layout adapts. | P2-Medium |
| CP-10A-03 | UI | UI/UX | Mobile layout 375px | — | 1. Resize / use device emulator. | Two-column collapses to single column; tap targets ≥ 44px. | P1-High |
| CP-10A-04 | UI | ACCESSIBILITY | Labels associated with inputs | — | 1. Inspect HTML. | Every input has `<label for="…">` or wraps `<label>`. | P1-High |
| CP-10A-05 | UI | UI/UX | Helpful placeholders | — | 1. Inspect. | Each input has descriptive placeholder distinct from the label. | P3-Low |
| CP-10A-06 | UI | UI/UX | Required asterisk + legend | — | 1. View form. | `*` next to required labels with explanatory legend. | P3-Low |
| CP-10A-07 | UI | UI/UX | Inline error per field | — | 1. Submit bad data. | Errors appear under the failing field, not just at top. | P1-High |
| CP-10A-08 | UI | UI/UX | Success above the fold | — | 1. Submit. | Success banner visible without scrolling on desktop. | P2-Medium |
| CP-10A-09 | UI | UI/UX | Submit button loading state | — | 1. Submit. | Button shows spinner / "Submitting…". | P2-Medium |
| CP-10A-10 | UI | UI/UX | Submit button disabled during submit | — | 1. Click submit twice fast. | Second click no-op; one row created. | P0-Critical |
| CP-10A-11 | UI | UI/UX | Form hides on success | — | 1. Submit. | Form collapses/slides up; success replaces it. | P2-Medium |
| CP-10A-12 | UI | UI/UX | Smooth scroll to `#cp-apply` | — | 1. Click Apply Now. | Smooth scroll (not jump). | P3-Low |
| CP-10A-13 | UI | UI/UX | Filename shown after selection | — | 1. Pick a CV. | Selected filename displayed near input. | P2-Medium |
| CP-10A-14 | UI | UI/UX | Hint text visible | — | 1. View form. | "PDF/DOC/DOCX, max 5MB" visible. | P2-Medium |
| CP-10A-15 | UI | ACCESSIBILITY | Logical tab order | — | 1. Tab through. | Order top→bottom matches visual order. | P1-High |
| CP-10A-16 | UI | UI/UX | Cover letter textarea sized | — | 1. View. | Resizable or ≥6 rows visible. | P3-Low |

### 10B — Job Listing Cards

| ID | Area | Type | Title | Preconditions | Steps | Expected Result | Severity if Fail |
|----|------|------|-------|---------------|-------|-----------------|------------------|
| CP-10B-17 | UI | UI/UX | Card hover state | — | 1. Hover card. | Shadow/lift animation. | P3-Low |
| CP-10B-18 | UI | UI/UX | Meta tags scannable | — | 1. View. | Tags visually distinct (color/icon). | P3-Low |
| CP-10B-19 | UI | UI/UX | Primary vs secondary buttons | — | 1. View. | Apply Now is the primary CTA; View Details secondary. | P2-Medium |
| CP-10B-20 | UI | UI/UX | Mobile card responsive | — | 1. 375px. | No overflow, full-width buttons. | P2-Medium |
| CP-10B-21 | UI | UI/UX | Empty meta — no gap | Job with no meta | 1. View. | Meta row hidden. | P2-Medium |

### 10C — Admin Dashboard

| ID | Area | Type | Title | Preconditions | Steps | Expected Result | Severity if Fail |
|----|------|------|-------|---------------|-------|-----------------|------------------|
| CP-10C-22 | UI | UI/UX | Stats cards correct counts | — | 1. View dashboard. | 4 cards (Total / Pending / Interview / Hired) match DB `COUNT()`. | P1-High |
| CP-10C-23 | UI | ACCESSIBILITY | Badge not color-only | — | 1. Inspect. | Status text inside badge. | P1-High |
| CP-10C-24 | UI | UI/UX | Row hover state | — | 1. Hover. | Visible row highlight. | P3-Low |
| CP-10C-25 | UI | UI/UX | Dropdown single-click | — | 1. Use. | One click to change. | P3-Low |
| CP-10C-26 | UI | UI/UX | Confirm dialog clarity | — | 1. Change status. | Modal names applicant + new status, e.g. "Change Maria's status to Rejected?". | P1-High |
| CP-10C-27 | UI | UI/UX | Long emails don't break layout | 80-char email | 1. View. | Truncate with ellipsis or wrap. | P3-Low |
| CP-10C-28 | UI | UI/UX | Pagination functional | — | 1. Use prev/next. | Pages change correctly. | P2-Medium |
| CP-10C-29 | UI | UI/UX | Filter reset | — | 1. Click Reset. | All filters cleared, full list returns. | P2-Medium |

---

## AREA 11 — Accessibility

| ID | Area | Type | Title | Preconditions | Steps | Expected Result | Severity if Fail |
|----|------|------|-------|---------------|-------|-----------------|------------------|
| CP-11-01 | A11Y | ACCESSIBILITY | Inputs have `<label>` | — | 1. axe-core scan. | No "missing label" violations. | P1-High |
| CP-11-02 | A11Y | ACCESSIBILITY | `aria-required="true"` | — | 1. Inspect required inputs. | Attribute present. | P2-Medium |
| CP-11-03 | A11Y | ACCESSIBILITY | Errors announced | — | 1. Trigger error with NVDA/VoiceOver. | Announced via `role="alert"` / `aria-live="assertive"`. | P1-High |
| CP-11-04 | A11Y | ACCESSIBILITY | Success announced | — | 1. Submit valid. | Announced via `aria-live`. | P2-Medium |
| CP-11-05 | A11Y | ACCESSIBILITY | File upload keyboard-accessible | — | 1. Tab to file input. 2. Press Enter/Space. | Native picker opens. | P1-High |
| CP-11-06 | A11Y | ACCESSIBILITY | Badge text, not color alone | — | 1. View grayscale. | Status still identifiable by text. | P1-High |
| CP-11-07 | A11Y | ACCESSIBILITY | Admin table `<th scope="col">` | — | 1. Inspect. | All header cells have `scope="col"`. | P2-Medium |
| CP-11-08 | A11Y | ACCESSIBILITY | Back link focusable | — | 1. Tab to link. | Receives focus with visible ring. | P2-Medium |
| CP-11-09 | A11Y | ACCESSIBILITY | Submit visible focus ring | — | 1. Tab to submit. | Default browser ring NOT removed without replacement. | P1-High |
| CP-11-10 | A11Y | ACCESSIBILITY | Body text contrast AA | — | 1. axe / Contrast Analyzer. | ≥ 4.5:1 on white. | P1-High |
| CP-11-11 | A11Y | ACCESSIBILITY | Placeholder contrast AA | — | 1. Analyze. | ≥ 4.5:1. | P2-Medium |
| CP-11-12 | A11Y | ACCESSIBILITY | Badge contrast AA on its bg | — | 1. Analyze each badge. | ≥ 4.5:1 (or ≥ 3:1 for large text). | P1-High |
| CP-11-13 | A11Y | ACCESSIBILITY | Keyboard-only completion | — | 1. Unplug mouse. 2. Apply end-to-end. | Possible without mouse. | P1-High |
| CP-11-14 | A11Y | ACCESSIBILITY | `<noscript>` fallback | — | 1. Disable JS. 2. Load apply page. | `<noscript>` shown ("Please enable JS"); see also Area 14 NoJS handler. | P2-Medium |

---

## AREA 12 — Performance & Load

| ID | Area | Type | Title | Preconditions | Steps | Expected Result | Severity if Fail |
|----|------|------|-------|---------------|-------|-----------------|------------------|
| CP-12-01 | Perf | PERFORMANCE | 100 jobs in `[career_listings]` | Seed 100 jobs | 1. Load page; measure TTFB + LCP. | TTFB ≤ 1s, LCP ≤ 3s on Lighthouse desktop. | P1-High |
| CP-12-02 | Perf | PERFORMANCE | 500 applications in dashboard | Seed 500 rows | 1. Load page. | Page loads ≤ 5s; pagination prevents fetching all. | P1-High |
| CP-12-03 | Perf | PERFORMANCE | Pagination uses LIMIT/OFFSET | — | 1. Query Monitor / SAVEQUERIES. | Queries use `LIMIT 20 OFFSET …`; no full-table fetch. | P1-High |
| CP-12-04 | Perf | PERFORMANCE | 5MB upload no timeout | `max_execution_time = 30` | 1. Upload 5MB on slow throttled connection. | Completes; no 504/timeout. | P1-High |
| CP-12-05 | Perf | PERFORMANCE | Assets only on shortcode pages | — | 1. Network tab on homepage. | Plugin CSS/JS absent. | P2-Medium |
| CP-12-06 | Perf | PERFORMANCE | No render-blocking inline scripts | — | 1. Lighthouse / view source. | Scripts deferred or footer-enqueued; no inline blocking critical path. | P2-Medium |
| CP-12-07 | Perf | PERFORMANCE | No SQL_CALC_FOUND_ROWS | — | 1. Query Monitor. | `get_posts()` uses `'no_found_rows' => true` (or equivalent). | P3-Low |
| CP-12-08 | Concurrency | PERFORMANCE | 10 concurrent submissions | Apache Bench / k6 | 1. Run 10 parallel submissions different emails. | All 10 rows saved; no race-condition duplicates; CV files all distinct on disk. | P1-High |

---

## AREA 13 — Browser & Device Compatibility

Each row exercises the **same checklist**: form renders, file upload works, AJAX submission works, success/error messages display, autofill works, no console errors.

| ID | Area | Type | Title | Preconditions | Steps | Expected Result | Severity if Fail |
|----|------|------|-------|---------------|-------|-----------------|------------------|
| CP-13-01 | Browser | UI/UX | Chrome latest (desktop) | — | Run the standard checklist. | All checks pass. | P1-High |
| CP-13-02 | Browser | UI/UX | Firefox latest (desktop) | — | Run checklist. | All pass. | P1-High |
| CP-13-03 | Browser | UI/UX | Safari latest (macOS) | — | Run checklist. | All pass. | P1-High |
| CP-13-04 | Browser | UI/UX | Edge latest (desktop) | — | Run checklist. | All pass. | P1-High |
| CP-13-05 | Browser | UI/UX | Chrome on Android | — | Run checklist incl. file pick from Google Drive. | All pass. | P1-High |
| CP-13-06 | Browser | UI/UX | Safari on iOS | — | Run checklist incl. file pick from iCloud Drive + Files app. | All pass. | P1-High |
| CP-13-07 | Browser | UI/UX | Samsung Internet | — | Run checklist on Galaxy. | All pass. | P2-Medium |

---

## AREA 14 — Data Integrity & Concurrency

| ID | Area | Type | Title | Preconditions | Steps | Expected Result | Severity if Fail |
|----|------|------|-------|---------------|-------|-----------------|------------------|
| CP-14-01 | Data | POSITIVE | Submitted values match DB | — | 1. Submit known values. 2. Query `wp_cp_applications`. | Byte-perfect match for every column (after expected sanitization). | P0-Critical |
| CP-14-02 | Data | POSITIVE | Screening Q text + A match | — | 1. Query `wp_cp_screening_answers`. | Question text + answer match exactly. | P0-Critical |
| CP-14-03 | Data | POSITIVE | CV filename in DB matches disk | — | 1. Compare. | Equal; file exists; size matches. | P1-High |
| CP-14-04 | Concurrency | EDGE | Same email+job concurrent | Two requests via `ab -n 2 -c 2` | 1. Run. | Exactly one row inserted; second returns duplicate error. | P0-Critical (B4) |
| CP-14-05 | Concurrency | POSITIVE | Different emails concurrent | — | 1. 2 simultaneous unique-email submissions. | Both saved. | P1-High |
| CP-14-06 | Concurrency | EDGE | Concurrent status update | — | 1. Two admins update same row. | Last write wins; no fatal; final state matches one of the two. | P1-High |
| CP-14-07 | Data | NEGATIVE | DB insert failure | Drop `wp_cp_applications` table temporarily | 1. Submit. | User sees error JSON, not white screen; admin log captures error; CV file rolled back (no orphaned file). | P0-Critical (B5) |
| CP-14-08 | Data | EDGE | 200-char full_name | — | 1. Submit 200-char name. | Stored complete OR truncated cleanly with warning; never SQL error. | P2-Medium |
| CP-14-09 | Data | EDGE | 200-char email | — | 1. Submit. | Either accepted (within RFC 254) or rejected with clear validation message. | P2-Medium |
| CP-14-10 | Data | EDGE | 10,000-char screening answer | — | 1. Submit. | Full text stored in TEXT column; displayed intact. | P1-High |

---

## AREA 15 — Regression Smoke (Run After Every Fix)

Single end-to-end golden path. **All 18 steps must pass before sign-off.**

| ID | Area | Type | Title | Preconditions | Steps | Expected Result | Severity if Fail |
|----|------|------|-------|---------------|-------|-----------------|------------------|
| CP-15-01 | Smoke | POSITIVE | Fresh activate | Clean install | Activate. | Tables + upload dir + .htaccess + index.php present. | P0-Critical |
| CP-15-02 | Smoke | POSITIVE | Create "Senior Designer" | — | Title "Senior Designer", Full-time, Accra, GHS 8,000/month, deadline +30d, 2 screening Qs, portfolio required → Publish. | Job saved with all meta. | P0-Critical |
| CP-15-03 | Smoke | POSITIVE | Appears in listings | Page with `[career_listings]` | View page. | Job visible. | P0-Critical |
| CP-15-04 | Smoke | UI/UX | Apply Now scrolls | — | Click Apply Now. | Smooth scroll to `#cp-apply`. | P2-Medium |
| CP-15-05 | Smoke | POSITIVE | Submit valid app | MailHog on | Fill all fields incl. PDF CV + portfolio + both screening answers. Submit. | Success message. | P0-Critical |
| CP-15-06 | Smoke | UI/UX | Form hidden on success | — | Observe. | Form collapses, success banner replaces. | P1-High |
| CP-15-07 | Smoke | EMAIL | Admin email received | — | Check MailHog. | Full admin email present. | P0-Critical |
| CP-15-08 | Smoke | EMAIL | Applicant email received | — | Check MailHog. | Confirmation email present. | P0-Critical |
| CP-15-09 | Smoke | POSITIVE | Row in dashboard with Pending | — | Open dashboard. | One row, status = Pending. | P0-Critical |
| CP-15-10 | Smoke | POSITIVE | Download CV | — | Click Download. | Correct file + filename. | P1-High |
| CP-15-11 | Smoke | EMAIL | Change to Interview → email | — | Change status. | Interview email captured. | P1-High |
| CP-15-12 | Smoke | EMAIL | Change to Hired → email | — | Change status. | Hired email captured. | P1-High |
| CP-15-13 | Smoke | POSITIVE | Filter by job | — | Filter dropdown. | Only Senior Designer apps listed. | P1-High |
| CP-15-14 | Smoke | POSITIVE | Filter by Hired | — | Filter. | Only Hired apps listed. | P1-High |
| CP-15-15 | Smoke | POSITIVE | View single application | — | Open detail. | All fields correct. | P1-High |
| CP-15-16 | Smoke | POSITIVE | Deactivate | — | Deactivate plugin. | CPT menu gone; shortcodes return empty; DB data intact. | P1-High |
| CP-15-17 | Smoke | POSITIVE | Reactivate | — | Reactivate. | Data + CPT restored; no duplicate table errors. | P1-High |
| CP-15-18 | Smoke | POSITIVE | Uninstall | — | Delete plugin. | Both tables dropped; all `cp_job` posts + meta deleted; `cp_*` options removed; **`career-portal-uploads/` directory + files remain**. | P0-Critical (B12) |

---

## Priority Bug Triggers (must FAIL — if any of these PASS, auto-file as P0)

| # | Linked Test IDs | Scenario |
|---|-----------------|----------|
| B1 | CP-04B-18, CP-04C-34 | Application without CV saves successfully |
| B2 | CP-04B-24, CP-04B-25 | Screening answers skipped on required-Q job |
| B3 | CP-02-10, CP-04B-26 | Application accepted after deadline |
| B4 | CP-04D-38, CP-14-04 | Same email + job accepted more than once |
| B5 | CP-14-07, CP-04C-35 | PHP fatal / white screen on any submission |
| B6 | CP-05B-21 | Status change sends email with no confirm/undo |
| B7 | CP-05C-22..27, CP-09-06 | Non-admin can access application data |
| B8 | CP-07-07, CP-07-08 | CV file accessible via direct URL |
| B9 | CP-02-08, CP-04D-40..42 | XSS payload rendered unescaped in admin |
| B10 | CP-04D-43, CP-05C-28, CP-05C-29 | SQL injection succeeds in any field |
| B11 | CP-04E-50 | `[career_apply]` on homepage loads wrong job silently |
| B12 | CP-15-18, CP-01-04 | Uninstall leaves orphaned tables |

---

## Test Data Pack (use across all NEGATIVE/EDGE rows above)

| Field | Test Values |
|-------|-------------|
| Name | `""`, `"   "`, `<script>alert(1)</script>`, 300-char Lorem, `Madonna`, `José Müller`, `田中` |
| Email | `a`, `@b.com`, `test@test`, `' OR 1=1--`, `user+test@gmail.com`, 200-char string |
| Phone | `""`, `abc`, `++44`, 100-digit string |
| CV | none, 0-byte PDF, 1MB PDF, exact 5MB PDF, 5MB+1B, `.exe`→`.pdf`, `.php`→`.pdf`, `.jpg` |
| Portfolio | `""`, `javascript:alert(1)`, `data:text/html,<h1>x</h1>`, `http://`, `not-a-url`, `https://behance.net/you` |
| Screening | skip all, spaces only, 10,000-char answer, HTML tags |
| Job ID | `0`, `-1`, draft post ID, page ID, trashed job ID, `99999` |
| Status param | `pending`, `hired`, `' OR 1=1--`, `<script>`, `foobar` |

---

## Environment Checklist

- [ ] WordPress 6.4+
- [ ] PHP tested on 7.4 **and** 8.x
- [ ] MySQL 5.7+ / MariaDB 10.3+
- [ ] Apache w/ mod_rewrite (or nginx equivalent)
- [ ] Pretty permalinks = Post name
- [ ] `WP_DEBUG = true`, `WP_DEBUG_LOG = true`, `WP_DEBUG_DISPLAY = false`
- [ ] MailHog (or WP Mail SMTP test mode) capturing outbound mail
- [ ] Browser DevTools open during all AJAX tests
- [ ] Test users: Administrator, Editor, Author, Subscriber
- [ ] Two browsers available for concurrency tests
- [ ] axe-core / Lighthouse installed
- [ ] Apache Bench (`ab`) or `k6` for concurrency / load tests

---

## Deliverables from QA

1. **Test Execution Report** — every test ID marked `Pass` / `Fail` / `Blocked` / `N/A` with date + tester + build SHA.
2. **Bug Report** — per failure: ID, severity, env, steps, actual vs expected, screenshot/HAR/debug.log excerpt.
3. **Coverage Summary** — per-area pass rate; overall pass rate; P0/P1 outstanding counts.
4. **Sign-off Checklist** — every B1..B12 trigger confirmed *failing as designed* (or remediated and re-tested).

---

## Coverage Snapshot

| Area | # Cases |
|------|---------|
| 1. Activation & Environment | 10 |
| 2. Jobs Admin | 16 |
| 3. Listings Shortcode | 12 |
| 4. Apply Form (A+B+C+D+E) | 54 |
| 5. Dashboard List | 32 |
| 6. Single Application View | 12 |
| 7. CV Download | 10 |
| 8. Email Notifications | 19 |
| 9. Settings Page | 6 |
| 10. UI/UX | 29 |
| 11. Accessibility | 14 |
| 12. Performance & Load | 8 |
| 13. Browser Compatibility | 7 |
| 14. Data Integrity & Concurrency | 10 |
| 15. Regression Smoke | 18 |
| **Total** | **257** |
