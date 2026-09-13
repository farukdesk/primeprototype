# Prime University Student API v1 – Third-Party Integration Guide

This API lets an approved external application create student records directly in the
Prime University student database. Records created through the API are identical to
ones entered by staff in the admin panel (same fields, same auto-generated Student ID,
same audit trail).

| | |
|---|---|
| **Base URL** | `https://primeuniversity.ac.bd/admin/api/v1` |
| **Format** | JSON request and response, UTF-8 |
| **Auth** | API key in the `X-API-Key` header |
| **Transport** | HTTPS only |
| **Version** | `1.0` (returned in the `X-API-Version` response header) |

---

## 1. Getting access

1. Send the university IT office: your organisation name, a technical contact e-mail,
   the **public IP address(es)** your servers will call from, and the expected request
   volume.
2. You receive an API key that looks like `pu_3f9a…` (51 characters). It is shown to
   you **once**; the university stores only a hash and cannot recover it.
3. Keys are bound to *scopes*. The standard partner key has:
   * `students:create` – create students
   * `reference:read` – read lookup data (departments, programs, boards, …)

**Protect the key.** Store it in a secrets manager or environment variable, never in
source control, mobile apps or browser JavaScript. Call the API from your **server**
only. If a key leaks, ask IT to revoke it and issue a new one.

---

## 2. Authentication and standard headers

Every request must carry the key:

```http
X-API-Key: pu_your_key_here
```

`Authorization: Bearer pu_your_key_here` is accepted as an alternative.

| Header | Direction | Purpose |
|---|---|---|
| `X-API-Key` | request | Your API key (required) |
| `Content-Type` | request | `application/json` or `multipart/form-data` |
| `X-Idempotency-Key` | request | Optional, strongly recommended for `POST` (see §4) |
| `X-API-Version` | response | API version that served the call |
| `X-RateLimit-Limit` / `X-RateLimit-Remaining` | response | Your per-minute quota and what is left |
| `Retry-After` | response | Seconds to wait after a `429` or `409 request_in_progress` |
| `X-Idempotent-Replayed: true` | response | The response is a replay of an earlier identical request |

Authentication failures return `401` (missing / invalid / expired key) or `403`
(disabled client, IP not allowed, missing scope). See §8 for the full code list.

---

## 3. Rate limits

Each client has a per-minute quota (default **60 requests/minute**, sliding window).
When exceeded you receive `429 rate_limited` with a `Retry-After` header. Back off and
retry after the indicated time; do not hammer the endpoint. Bulk loads should be
throttled to stay under your quota (contact IT if you need a higher limit).

---

## 4. Idempotency (safe retries)

Network failures can leave you unsure whether a `POST` succeeded. To make retries safe,
send a unique `X-Idempotency-Key` (1-100 chars: letters, digits, `.` `_` `:` `-`) with
every create request, e.g. your own applicant/record ID:

```http
X-Idempotency-Key: crm-applicant-84213
```

* First call: processed normally.
* Same key again after success: the **stored response is returned** (`201`, header
  `X-Idempotent-Replayed: true`) and **no second student is created**.
* Same key while the first call is still running: `409 request_in_progress`, retry in a
  few seconds.
* Same key after a failed attempt (4xx/5xx): the request is processed again.

Keys are scoped to your client and remembered indefinitely, so use a new key for each
distinct student.

---

## 5. Endpoint: reference data

```
GET /reference-data.php
Scope: reference:read
```

Returns all valid lookup values. Call it (and cache the result for a few hours) before
building create requests.

```json
{
  "ok": true,
  "data": {
    "departments": [
      { "id": 3, "code": "CSE", "name": "Computer Science & Engineering", "faculty": "Faculty of Engineering",
        "programs": [ { "id": 7, "name": "B.Sc. in CSE", "type": "Bachelor" } ] }
    ],
    "semesters": ["Spring 2025", "Summer 2025", "Fall 2025", "Spring 2026", "..."],
    "exam_titles": [ { "id": 1, "name": "Higher Secondary Certificate", "short_name": "HSC" } ],
    "boards":      [ { "id": 2, "name": "Dhaka Board", "short_name": "Dhaka" } ],
    "groups":      [ { "id": 1, "name": "Science" } ],
    "enums": {
      "sex": ["Male", "Female", "Other"],
      "status": ["Active", "Inactive", "Graduated", "Dropped", "Not Admitted Yet"],
      "shift": ["Morning", "Day", "Evening"],
      "section": ["A", "B", "C", "D", "E", "F", "G"],
      "blood_group": ["A+", "A-", "B+", "B-", "AB+", "AB-", "O+", "O-"],
      "semester_type": ["bi_semester", "trimester"]
    },
    "limits": { "photo_max_bytes": 5242880, "photo_types": ["image/jpeg", "image/png", "image/gif", "image/webp"],
                "max_qualifications": 10, "rate_limit_per_min": 60 }
  }
}
```

---

## 6. Endpoint: create student

```
POST /students/create.php
Scope: students:create
Content-Type: application/json   (or multipart/form-data for file upload)
```

### 6.1 Field reference

All strings are trimmed. Empty strings are treated as "not provided". Unknown fields
are ignored. Alternative names shown in *italics* are accepted aliases.

**Enrollment**

| Field | Required | Type / format | Notes |
|---|---|---|---|
| `department` (*dept_id*, *department_code*) | **yes** | id, code or exact name | e.g. `3`, `"CSE"` |
| `program` (*program_id*) | no | id or exact name | must belong to `department` |
| `semester` (*admitted_semester*) | **yes** | `"<Spring|Summer|Fall> <YYYY>"` | `"Spring 2026"`, `"fall-2026"`, `"2026 Summer"` all accepted |
| `year` | no | string ≤ 20 | academic year label, e.g. `"1st"` |
| `semester_type` | no | `bi_semester` \| `trimester` | |
| `batch` | no | string ≤ 50 | |
| `shift` | no | `Morning` \| `Day` \| `Evening` | |
| `section` | no | `A`…`G` | |
| `status` | no | see enums | defaults to the status configured for your client (normally `Not Admitted Yet`) |
| `student_id` | no | 1-20 letters/digits/hyphens | **omit to let the university generate it** (recommended) |

**Student**

| Field | Required | Type / format |
|---|---|---|
| `name` (*full_name*, *student_name*) | **yes** | string 2-255 |
| `father_name` | no | string ≤ 255 |
| `father_phone`, `father_occupation` | no | optional extras |
| `mother_name` | no | string ≤ 255 |
| `mother_phone`, `mother_occupation` | no | optional extras |
| `present_address` | no | string ≤ 1000 |
| `contact_no` (*phone*, *mobile*) | no | phone: digits, optional leading `+`, 6-20 chars |
| `email` | no | valid e-mail |
| `permanent_address` | no | string ≤ 1000 |
| `permanent_contact_no` (*permanent_phone*) | no | phone |
| `permanent_email` | no | valid e-mail |
| `nationality` | no | string ≤ 100 |
| `country` | no | string ≤ 100, default `Bangladesh` |
| `place_of_birth` | no | string ≤ 150 |
| `date_of_birth` (*dob*) | no | `YYYY-MM-DD`, not in the future |
| `religion` | no | string ≤ 50 |
| `sex` (*gender*) | no | `Male` \| `Female` \| `Other` (`M`/`F` accepted) |
| `blood_group` | no | see enums |
| `nid` (*national_id*) | no | string ≤ 50 |

**Guardian** – send as a nested `guardian` object (preferred) or as flat `guardian_*` fields.

| `guardian.` field | Flat alias | Type |
|---|---|---|
| `name` | `guardian_name` | string ≤ 255 |
| `profession` (*occupation*) | `guardian_profession` | string ≤ 150 |
| `address` | `guardian_address` | string ≤ 1000 |
| `phone` (*contact_no*) | `guardian_phone` | phone |
| `relationship` (*relation*) | `guardian_relationship` | string ≤ 100 |
| `email` | `guardian_email` | valid e-mail |
| `yearly_income` (*annual_income*) | `guardian_yearly_income` | number ≥ 0 (commas allowed) |

**Academic qualifications** – `academic_qualifications` (*qualifications*): array of up to 10 objects.

| Field | Type | Notes |
|---|---|---|
| `name_of_examination` (*exam_name*, *exam*) | string ≤ 100 | required per row; if it matches a known exam title (name or short name such as `HSC`) it is linked to it, otherwise stored as free text |
| `exam_title_id` | int | alternative to the name; must exist in reference data |
| `session` | string ≤ 30 | e.g. `"2019-2020"` |
| `group` (*group_name*) / `group_id` | string / int | e.g. `"Science"` |
| `board_university` (*board*, *university*) / `board_id` | string / int | e.g. `"Dhaka Board"` |
| `year_of_passing` (*passing_year*) | `YYYY` | |
| `division_grade` (*division*, *grade*) | string ≤ 50 | e.g. `"A+"`, `"First Class"` |
| `obtained_marks_cgpa` (*cgpa*, *gpa*, *total_marks*) | string ≤ 50 | e.g. `"5.00"`, `"3.85/4.00"` |

**Photo** – one of:

| Method | How |
|---|---|
| JSON | `photo_base64`: base64 string, with or without a `data:image/jpeg;base64,` prefix |
| multipart/form-data | file field `photo`, plus either a `payload` field holding the JSON document or the fields sent individually (`name=…`, `guardian[name]=…`, `academic_qualifications[0][name_of_examination]=…`) |

JPG, PNG, GIF or WEBP, max **5 MB**. The photo is validated by content, not by file extension.

### 6.2 Example: JSON request

```bash
curl -X POST https://primeuniversity.ac.bd/admin/api/v1/students/create.php \
  -H "X-API-Key: $PU_API_KEY" \
  -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: crm-applicant-84213" \
  -d @- <<'JSON'
{
  "department": "CSE",
  "program": 7,
  "semester": "Spring 2026",
  "year": "1st",

  "name": "Nusrat Jahan",
  "father_name": "Abdul Karim",
  "mother_name": "Rahima Begum",
  "present_address": "House 12, Road 5, Mirpur-2, Dhaka 1216",
  "contact_no": "+8801711000000",
  "email": "nusrat.jahan@example.com",
  "permanent_address": "Village Charpara, Post Kalihati, Tangail",
  "permanent_contact_no": "01911000000",
  "permanent_email": "nusrat.home@example.com",
  "nationality": "Bangladeshi",
  "place_of_birth": "Tangail",
  "date_of_birth": "2006-08-15",
  "religion": "Islam",
  "sex": "Female",

  "academic_qualifications": [
    { "name_of_examination": "SSC", "session": "2021-2022", "group": "Science",
      "board_university": "Dhaka Board", "year_of_passing": "2022",
      "division_grade": "A+", "obtained_marks_cgpa": "5.00" },
    { "name_of_examination": "HSC", "session": "2023-2024", "group": "Science",
      "board_university": "Dhaka Board", "year_of_passing": "2024",
      "division_grade": "A", "obtained_marks_cgpa": "4.83" }
  ],

  "guardian": {
    "name": "Abdul Karim",
    "profession": "Business",
    "address": "House 12, Road 5, Mirpur-2, Dhaka 1216",
    "phone": "+8801711000000",
    "relationship": "Father",
    "email": "abdul.karim@example.com",
    "yearly_income": 650000
  },

  "photo_base64": "data:image/jpeg;base64,/9j/4AAQSkZJRgABAQ..."
}
JSON
```

### 6.3 Example: multipart with photo file

```bash
curl -X POST https://primeuniversity.ac.bd/admin/api/v1/students/create.php \
  -H "X-API-Key: $PU_API_KEY" \
  -H "X-Idempotency-Key: crm-applicant-84213" \
  -F 'payload=@student.json;type=application/json' \
  -F 'photo=@nusrat.jpg'
```

`student.json` contains the same document as in §6.2 (without `photo_base64`).

### 6.4 Example: Node.js

```js
const res = await fetch('https://primeuniversity.ac.bd/admin/api/v1/students/create.php', {
  method: 'POST',
  headers: {
    'X-API-Key': process.env.PU_API_KEY,
    'Content-Type': 'application/json',
    'X-Idempotency-Key': `crm-applicant-${applicant.id}`,
  },
  body: JSON.stringify(payload),
});
const body = await res.json();
if (!body.ok) {
  // body.code, body.message, body.errors (for 422)
  throw new Error(`${res.status} ${body.code}: ${body.message}`);
}
console.log('Created student', body.data.student_id);
```

### 6.5 Example: PHP

```php
$ch = curl_init('https://primeuniversity.ac.bd/admin/api/v1/students/create.php');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'X-API-Key: ' . getenv('PU_API_KEY'),
        'Content-Type: application/json',
        'X-Idempotency-Key: crm-applicant-' . $applicantId,
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
]);
$body   = json_decode(curl_exec($ch), true);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
```

### 6.6 Example: Python

```python
import os, requests

r = requests.post(
    "https://primeuniversity.ac.bd/admin/api/v1/students/create.php",
    headers={"X-API-Key": os.environ["PU_API_KEY"],
             "X-Idempotency-Key": f"crm-applicant-{applicant_id}"},
    json=payload, timeout=30,
)
body = r.json()
if not body["ok"]:
    raise RuntimeError(f"{r.status_code} {body['code']}: {body['message']} {body.get('errors')}")
```

---

## 7. Responses

### 7.1 Success `201 Created`

```json
{
  "ok": true,
  "message": "Student created.",
  "data": {
    "id": 18422,
    "student_id": "260303070012",
    "student_id_source": "generated",
    "full_name": "Nusrat Jahan",
    "status": "Not Admitted Yet",
    "department": { "id": 3, "code": "CSE", "name": "Computer Science & Engineering" },
    "program": { "id": 7, "name": "B.Sc. in CSE" },
    "admitted_semester": "Spring 2026",
    "year": "1st",
    "email": "nusrat.jahan@example.com",
    "contact_no": "+8801711000000",
    "photo_url": "https://primeuniversity.ac.bd/admin/uploads/students/photos/9f2c….jpg",
    "academic_qualifications_saved": 2,
    "created_at": "2026-09-13T10:15:42+06:00"
  },
  "warnings": [
    "A student with the same email already exists: Nusrat Jahan (250303070004)."
  ]
}
```

Store `data.student_id` (the university's official ID) and `data.id` (internal row id)
on your side. `warnings` never block creation; review them to catch duplicates.

### 7.2 Error format

```json
{
  "ok": false,
  "code": "validation_failed",
  "message": "One or more fields are invalid.",
  "errors": {
    "semester": "Semester is required, e.g. \"Spring 2026\".",
    "guardian.email": "Must be a valid email address.",
    "academic_qualifications.1.year_of_passing": "Must be a 4-digit year."
  }
}
```

`code` is stable and safe to branch on; `message` is human-readable and may change.
`errors` (only on `422`/`409`) maps field paths to messages; nested paths use dots.

---

## 8. Error codes

| HTTP | `code` | Meaning / what to do |
|---|---|---|
| 400 | `invalid_json` | Body (or `payload` field) is not valid JSON |
| 400 | `invalid_idempotency_key` | Bad `X-Idempotency-Key` format |
| 401 | `missing_api_key` | Add the `X-API-Key` header |
| 401 | `invalid_api_key` | Key unknown or malformed |
| 401 | `api_key_expired` | Request a new key |
| 403 | `client_disabled` | Your access was revoked; contact IT |
| 403 | `ip_not_allowed` | Calling IP is not on your allow-list |
| 403 | `insufficient_scope` | Key lacks the scope for this endpoint |
| 405 | `method_not_allowed` | Wrong HTTP verb |
| 409 | `duplicate_student_id` | The `student_id` you supplied already exists |
| 409 | `request_in_progress` | Same idempotency key is still processing; retry after `Retry-After` |
| 422 | `validation_failed` | Fix the fields listed in `errors` |
| 429 | `rate_limited` | Wait `Retry-After` seconds |
| 500 | `server_error` | Temporary failure; retry with the **same** idempotency key |

---

## 9. Integration checklist and best practices

- [ ] Call `GET /reference-data.php` on start-up (cache a few hours) and map your
      department/program/board values to the university's ids or exact names.
- [ ] Always send `X-Idempotency-Key` on `POST` and retry `5xx` / network errors with the
      same key (exponential back-off, max 5 attempts).
- [ ] Handle `422` by surfacing `errors` to the operator; do not retry unchanged data.
- [ ] Respect `429` and `Retry-After`; throttle bulk imports.
- [ ] Let the university generate `student_id` unless you have been explicitly given an
      ID range.
- [ ] Send dates as `YYYY-MM-DD`, phones with country code (`+880…`), UTF-8 text.
- [ ] Compress photos before sending (a 300×400 JPEG is plenty); keep under 5 MB.
- [ ] Persist `data.id`, `data.student_id` and any `warnings` in your system.
- [ ] Keep the API key server-side; rotate it if staff with access leave.
- [ ] Log the `X-API-Version` header so you notice when a new version is announced.

---

## 10. Support

* Technical issues: Prime University IT Office (see the contact you were given with your key). Include the timestamp, endpoint, HTTP status, `code` and your `X-Idempotency-Key`.
* Breaking changes will be released under a new base path (`/v2`). `/v1` fields may gain
  optional additions but existing fields will not change meaning.

---

## Appendix A – Operator notes (university IT)

1. Apply `admin/student-api-clients-v1.sql` once.
2. Issue a key (run on the server, never over HTTP):
   ```bash
   php admin/api/v1/bin/create-client.php --name="Partner CRM" \
       --ips=203.0.113.10 --rate=60 --expires=2027-12-31 --created-by=1
   ```
   `--created-by` is the `users.id` recorded as `students.created_by` and in `change_log`.
3. Manage: `--list`, `--revoke=<id>`, `--enable=<id>`.
4. Audit: `api_client_requests` holds every call (status, IP, created `students.id`);
   `students.api_client_id` identifies API-created records.
5. Adjust `default_status`, `scopes`, `rate_limit_per_min`, `ip_allowlist` directly in
   `api_clients` if needed.
