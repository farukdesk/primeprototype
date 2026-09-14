# SS Portal – partner web portal for the Prime University Student API

A stand-alone PHP web application that a **third-party organisation** hosts on its
own server, with its **own MySQL database and its own user logins**. Operators
fill in a student form; the portal stores the record locally and registers the
student at Prime University through the third-party **Student API v1**
(`admin/api/v1`, see `../admin/api/v1/API-GUIDE.md`). It can also publish the
student's final result (CGPA), either in the same call or later.

The portal never connects to the university database. All writes go through:

| Portal action | University endpoint | Scope |
|---|---|---|
| Load departments / programs / semesters / boards | `GET /reference-data.php` | `reference:read` |
| Save and send student (optionally with result) | `POST /students/create.php` | `students:create` (+ `results:create`) |
| Publish / update final result | `POST /results/create.php` | `results:create` |

The partner API key is read from `config.php` (or the `PU_API_KEY` environment
variable) and used **only from PHP on the server**; it is never sent to the browser.

---

## Requirements

* PHP 7.4+ with `pdo_mysql`, `curl`, `fileinfo`, `mbstring`, `json`
* MySQL 5.7+ / MariaDB 10.3+
* Apache with `mod_rewrite`-less `.htaccess` support (or equivalent rules on nginx to block `includes/`, `bin/`, `storage/`, `config.php`, `schema.sql`)
* HTTPS on the portal host
* A partner API key issued by Prime University IT with scopes `students:create`, `results:create`, `reference:read`, and the portal server's public IP on the key's allow-list

## Installation

1. Copy the `ss-portal-api/` folder to the partner web server (e.g. `/var/www/portal/`).
2. Create a database and load the schema:
   ```bash
   mysql -u root -p -e "CREATE DATABASE ss_portal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   mysql -u root -p ss_portal < schema.sql
   ```
3. Configure:
   ```bash
   cp config.sample.php config.php
   # edit db.* and pu_api.* ; prefer exporting PU_API_KEY in the web server environment
   ```
4. Make the photo folder writable by the web server user:
   ```bash
   chown -R www-data:www-data storage && chmod 750 storage storage/photos
   ```
5. Create the first administrator (run on the server, never over HTTP):
   ```bash
   php bin/create-user.php --username=admin --name="Portal Admin" --role=admin
   ```
   The generated password is printed once.
6. Open the portal in a browser, sign in, go to **Connection** and click
   **Test connection & refresh reference data**. A green message confirms the key,
   IP allow-list and scopes are working.

## Day-to-day use

* **Dashboard** – all local students with their status: `Draft` (only in the portal),
  `Registered at PU` (university Student ID shown), `Failed` (last error shown, retry available).
* **New student** – enrollment, personal, parents, guardian, academic qualifications, photo
  and an optional **final result**. Two buttons:
  * *Save draft* – stores locally only.
  * *Save and send to university* – stores locally, then calls `POST /students/create.php`.
* If the university answers `422 validation_failed`, its field errors are shown on the form
  (same field names) so the operator can correct and resend. Network / `5xx` / `429` failures
  are recorded and can be **retried** from the student page.
* **Student ID.** Leave *University Student ID* empty: the university continues the numbering
  already used by the students of the same admitted semester, department and program. It never
  invents a numbering. If none exists yet (first student of a new intake) it answers
  `422 student_id_pattern_not_found`, creates nothing, and the record stays a **Draft** with the
  notice *please contact the university admin for the Student ID*. Enter the ID the admin office
  issues in **University Student ID** (Edit) and send again.
* **Publish final result** – available once the student is registered; uses
  `POST /results/create.php` (an upsert, so re-sending the same semester is safe).
* **Edit a registered student** – the same form; *Save and update at university* pushes the
  whole local record with `POST /students/update.php` (the Student ID never changes). *Save
  locally only* keeps the change in the portal and flags the record **Update pending** until it
  is sent from the student page.
* **Delete** (administrators only) – `POST /students/delete.php` permanently removes the student,
  qualifications, final results, photo and files **at Prime University**; the portal record is
  **kept** and marked **Deleted** (who, when, why). Students who were never sent are simply
  marked deleted. The university refuses when the student has recorded payments.

The partner key needs the scopes `students:create`, `students:update`, `students:delete`,
`results:create` and `reference:read`. Existing installs: run `upgrade-1.1.sql` once.

## How duplicates are prevented

Every student create request carries `X-Idempotency-Key: <prefix>-student-<reference_no>`
(`reference_no` is the portal's own unique reference, e.g. `SSP-20260913-3F9A1C`).
Retrying after a timeout therefore returns the university's stored response
(`X-Idempotent-Replayed: true`) instead of creating a second student. The portal also
refuses to resend a record that is already `Registered at PU`.

## Data kept locally

| Table | Purpose |
|---|---|
| `ssp_users` | portal logins (bcrypt hashes, roles `admin` / `operator`) |
| `ssp_students` | local record, exact JSON payload sent, sync status, university `id` / `student_id` / status, last response, published result, and `internal_json` (portal-only flags / reference / notes) |
| `ssp_student_files` | internal documents per student: admission form, SSC, HSC, certificate, transcript, tabulation, other files |
| `ssp_api_log` | every API call: endpoint, idempotency key, HTTP status, code, duration, request and response bodies (photo bytes are not logged) |
| `ssp_cache` | cached reference data (default 6 h) |

Photos are stored in `storage/photos/` (not web-accessible) and sent to the university
as base64 inside the JSON request.

### Internal section (portal only)

Every student form has an **Internal** section for the partner's own bookkeeping:
**Apostille** (yes/no), **Online only** (yes/no), **Work done** (yes/no), **Reference**
(e.g. Bindu, Sir), **Internal notes**, and document uploads (**Admission form, SSC, HSC,
Certificate, Transcript, Tabulation, Other files**; several files per slot; PDF, JPG, PNG,
GIF, WEBP, DOC, DOCX; `file_max_bytes` in `config.php`, default 10 MB). Documents are kept
in `storage/files/` (not web-accessible) and served to signed-in users by `students/file.php`.
This data lives in `internal_json` / `ssp_student_files`, separate from `payload_json`, so it
is **never** sent to the university. Existing installs: run `upgrade-1.2.sql` once and make
`storage/files` writable like `storage/photos`.

## Security notes

* The portal is hidden from search engines three ways: `robots.txt` (`Disallow: /`), an `X-Robots-Tag: noindex, nofollow, noarchive` header (sent by `.htaccess` and by PHP), and `<meta name="robots">` on every page. If the portal lives in a sub-folder of a public site, also add `Disallow: /ss-portal-api/` to that site's root `robots.txt`.

* Keep `config.php` outside version control (already git-ignored) and readable only by the web server user.
* `.htaccess` files deny HTTP access to `includes/`, `bin/`, `storage/`, `config*.php`, `schema.sql` and this README. Replicate these rules if you use nginx.
* Sessions use `HttpOnly`, `SameSite=Lax` and `Secure` (when served over HTTPS); all forms are CSRF-protected; login is throttled after 5 failures.
* Rotate the API key with Prime University IT if staff with server access leave.

## Folder layout

```
ss-portal-api/
├─ index.php, login.php, logout.php, dashboard.php, status.php
├─ students/
│  ├─ create.php   create / edit unsent student, save or send
│  ├─ view.php     status, university data, payload, API log
│  ├─ sync.php     (POST) send / retry
│  ├─ result.php   publish / update final result
│  ├─ photo.php    serve local photo to signed-in users
│  └─ file.php     serve an internal document to signed-in users
├─ includes/
│  ├─ bootstrap.php        config, session, PDO, helpers
│  ├─ auth.php             users, login, CSRF
│  ├─ pu_api_client.php    cURL client for the Student API v1
│  ├─ reference_data.php   cached GET /reference-data.php
│  ├─ student_payload.php  form → API JSON, local validation, photo storage
│  ├─ internal_data.php    portal-only flags / reference / notes and document uploads
│  ├─ sync.php             send student, publish result, audit log
│  └─ layout.php           page chrome and form-field helpers
├─ bin/create-user.php     CLI user management
├─ assets/portal.css, portal.js
├─ storage/photos/         uploaded photos (private)
├─ storage/files/          internal documents (private, never sent to the university)
├─ schema.sql, upgrade-1.1.sql, upgrade-1.2.sql, config.sample.php, .htaccess, .gitignore
```
