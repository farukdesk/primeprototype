# Prime University Student API v1 – Third-Party Integration Guide

This API lets an approved external application **create student records** and **publish
final results (CGPA)** directly in the Prime University database. Records created through
the API are identical to ones entered by staff in the admin panel (same fields, same
auto-generated Student ID, same audit trail).

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
   * `students:create` – create students (§6)
   * `results:create` – publish final results / CGPA (§7)
   * `reference:read` – read lookup data (departments, programs, boards, …) (§5)

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
(disabled client, IP not allowed, missing scope). See §9 for the full code list.

---

## 3. Rate limits

Each client has a per-minute quota (default **60 requests/minute**, sliding window).
When exceeded you receive `429 rate_limited` with a `Retry-After` header. Back off and
retry after the indicated time; do not hammer the endpoint. Bulk loads should be
throttled to stay under your quota (contact IT if you need a higher limit). For results,
prefer the bulk form of §7 (up to 200 per request) over many single calls.

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
distinct student. (The results endpoint is additionally an *upsert*, so re-sending the
same result is always safe even without an idempotency key.)

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

### 6.7 Success response `201 Created`

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

### 6.8 Create a student **together with their result** (one call)

If your portal already holds the student's final result, add a `result` object (alias
`final_result`) to the same request. The student and the result are written in **one
transaction**: if anything in the result is invalid, the student is not created either,
and you get a normal `422` whose `errors` keys are prefixed with `result.`.

* Requires **both** scopes `students:create` and `results:create` on your key
  (otherwise `403 insufficient_scope`).
* `result` takes the fields of §7.1 **except** `student_id` (the new student is used).
* `batch` inside `result` defaults to the student's `batch`; `recorded_date` defaults to today.
* The graduation rule of §7 applies to the new record: with `"status": "Active"` (or
  `"result": { "mark_graduated": true, … }`) the student is stored as `Graduated`.
  With the default status `Not Admitted Yet` the status is left unchanged.

```bash
curl -X POST https://primeuniversity.ac.bd/admin/api/v1/students/create.php \
  -H "X-API-Key: $PU_API_KEY" \
  -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: portal-student-5521" \
  -d '{
    "department": "CSE",
    "program": 7,
    "semester": "Spring 2021",
    "batch": "52nd Batch",
    "status": "Active",
    "name": "Md. Rakib Hasan",
    "father_name": "Md. Abul Hasan",
    "mother_name": "Salma Begum",
    "contact_no": "+8801711000000",
    "email": "rakib@example.com",
    "date_of_birth": "2002-03-11",
    "sex": "Male",
    "guardian": { "name": "Md. Abul Hasan", "relationship": "Father", "phone": "+8801711000000" },
    "result": {
      "semester": "Fall 2024",
      "cgpa": 3.42,
      "recorded_date": "2025-01-10"
    }
  }'
```

`201 Created`:

```json
{
  "ok": true,
  "message": "Student created and result published.",
  "data": {
    "id": 18430,
    "student_id": "210303070018",
    "student_id_source": "generated",
    "full_name": "Md. Rakib Hasan",
    "status": "Graduated",
    "department": { "id": 3, "code": "CSE", "name": "Computer Science & Engineering" },
    "program": { "id": 7, "name": "B.Sc. in CSE" },
    "admitted_semester": "Spring 2021",
    "result": {
      "action": "created",
      "result_id": 9312,
      "subject": "Final Result",
      "semester": "Fall 2024",
      "cgpa": "3.42",
      "batch": "52nd Batch",
      "recorded_date": "2025-01-10"
    },
    "created_at": "2026-09-13T10:15:42+06:00"
  },
  "warnings": []
}
```

`data.result` is `null` when no `result` object was sent. To publish or correct a result
for a student that already exists, use §7 instead.

---

## 7. Endpoint: publish final result (CGPA)

```
POST /results/create.php
Scope: results:create
Content-Type: application/json
```

Writes a student's **final result** into the university's result store: the same record
the public *Certificate Verification* page shows as *Final CGPA / Ending Semester /
Result Publish Date*. The student **must already exist** (create them with §6 first).

The operation is an **upsert**: the key is *(student, subject, semester)*. Sending the
same student + semester again updates the CGPA, batch and publish date instead of
creating a duplicate. Re-sending is therefore always safe.

When a valid CGPA is published for a student whose status is `Active` or `Dropped`, the
student is automatically marked **`Graduated`** (this mirrors the university's manual
process). Pass `"mark_graduated": true` to force the change for other statuses.

### 7.1 Field reference

| Field | Required | Type / format | Notes |
|---|---|---|---|
| `student_id` (*sid*) | **yes** | 1-25 letters/digits/hyphens | University Student ID. Leading-zero variants are tolerated (`0123` matches `123`) and reported in `warnings` |
| `semester` (*completion_semester*, *ending_semester*) | **yes** | `"<Spring|Summer|Fall> <YYYY>"` | The **completion / ending** semester, e.g. `"Fall 2024"` |
| `cgpa` (*final_cgpa*, *gpa*) | **yes** | number `0.01` – `4.00` | Stored with 2 decimals. Values such as `"incom."`, `"incomplete"`, `"withheld"` are **rejected** (422): only final results can be published |
| `subject` | no | string ≤ 100 | Result label; default `"Final Result"`. Leave the default unless IT tells you otherwise |
| `batch` | no | string ≤ 50 | Defaults to the batch on the student record |
| `recorded_date` (*publish_date*) | no | `YYYY-MM-DD`, not in the future | Result publish date shown on the verification page; default **today** |
| `mark_graduated` | no | boolean | Force status `Graduated`; default `false` (auto-applied anyway for `Active`/`Dropped`) |
| `student_name` (*name*) | no | string | Optional cross-check; a mismatch with the record is returned as a warning, the record is kept |

### 7.2 Single result

```bash
curl -X POST https://primeuniversity.ac.bd/admin/api/v1/results/create.php \
  -H "X-API-Key: $PU_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "student_id": "210302070045",
    "student_name": "Md. Rakib Hasan",
    "semester": "Fall 2024",
    "cgpa": 3.42,
    "batch": "52nd Batch",
    "recorded_date": "2025-01-10"
  }'
```

`201 Created` (first publication) or `200 OK` (existing result updated):

```json
{
  "ok": true,
  "message": "Result published.",
  "data": {
    "student_id": "210302070045",
    "action": "created",
    "result_id": 9310,
    "student": { "id": 15231, "student_id": "210302070045", "full_name": "Md. Rakib Hasan", "status": "Graduated" },
    "subject": "Final Result",
    "semester": "Fall 2024",
    "cgpa": "3.42",
    "batch": "52nd Batch",
    "recorded_date": "2025-01-10"
  },
  "warnings": []
}
```

Unknown student:

```json
{ "ok": false, "code": "student_not_found",
  "message": "No student with ID \"210302070099\". Create the student first via POST /v1/students/create.php.",
  "errors": { "student_id": "No student with ID \"210302070099\"..." } }
```

### 7.3 Bulk results (up to 200 per request)

Wrap the items in `results`. Top-level `semester`, `subject`, `batch`, `recorded_date`
and `mark_graduated` act as defaults for every item; an item can override them.

```bash
curl -X POST https://primeuniversity.ac.bd/admin/api/v1/results/create.php \
  -H "X-API-Key: $PU_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "semester": "Fall 2024",
    "recorded_date": "2025-01-10",
    "results": [
      { "student_id": "210302070045", "cgpa": 3.42 },
      { "student_id": "210302070046", "cgpa": 3.87, "batch": "52nd Batch" },
      { "student_id": "210302070099", "cgpa": 2.95 },
      { "student_id": "210302070050", "cgpa": "incom." }
    ]
  }'
```

Each item is processed independently. The response is `200 OK` as long as at least one
item succeeded (`422` only when **every** item failed); inspect `results[]`:

```json
{
  "ok": true,
  "message": "2 of 4 items failed. See results[].errors.",
  "summary": { "total": 4, "created": 1, "updated": 1, "failed": 2 },
  "results": [
    { "index": 0, "student_id": "210302070045", "status": "ok", "action": "updated", "result_id": 9310,
      "student": { "id": 15231, "student_id": "210302070045", "full_name": "Md. Rakib Hasan", "status": "Graduated" },
      "subject": "Final Result", "semester": "Fall 2024", "cgpa": "3.42", "batch": "52nd Batch", "recorded_date": "2025-01-10" },
    { "index": 1, "student_id": "210302070046", "status": "ok", "action": "created", "result_id": 9311, "...": "..." },
    { "index": 2, "student_id": "210302070099", "status": "failed",
      "errors": { "student_id": "No student with ID \"210302070099\". Create the student first via POST /v1/students/create.php." } },
    { "index": 3, "student_id": "210302070050", "status": "failed",
      "errors": { "cgpa": "Incomplete / withheld results cannot be published. Send the result once a final CGPA exists." } }
  ]
}
```

Re-submitting only the failed items (after fixing them) is safe because of the upsert.

### 7.4 Example: Python bulk

```python
import os, requests

batch = [{"student_id": r.sid, "cgpa": r.cgpa} for r in graduates]   # ≤ 200 per call
r = requests.post(
    "https://primeuniversity.ac.bd/admin/api/v1/results/create.php",
    headers={"X-API-Key": os.environ["PU_API_KEY"]},
    json={"semester": "Fall 2024", "recorded_date": "2025-01-10", "results": batch},
    timeout=60,
)
body = r.json()
for item in body.get("results", []):
    if item["status"] != "ok":
        log.warning("Result for %s failed: %s", item["student_id"], item["errors"])
```

---

## 8. Responses and error format

Every response is JSON with a top-level `ok` boolean.

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
`errors` (on `422`/`409`/`404`) maps field paths to messages; nested paths use dots.
Bulk result responses carry `summary` and `results[]` instead of a single `errors` map.

---

## 9. Error codes

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
| 404 | `student_not_found` | (results) No student with that ID; create the student first |
| 405 | `method_not_allowed` | Wrong HTTP verb |
| 409 | `duplicate_student_id` | The `student_id` you supplied already exists |
| 409 | `request_in_progress` | Same idempotency key is still processing; retry after `Retry-After` |
| 422 | `validation_failed` | Fix the fields listed in `errors` (or `results[].errors` for bulk) |
| 429 | `rate_limited` | Wait `Retry-After` seconds |
| 500 | `server_error` | Temporary failure; retry with the **same** idempotency key |

---

## 10. Integration checklist and best practices

- [ ] Call `GET /reference-data.php` on start-up (cache a few hours) and map your
      department/program/board values to the university's ids or exact names.
- [ ] Always send `X-Idempotency-Key` on student `POST`s and retry `5xx` / network errors
      with the same key (exponential back-off, max 5 attempts).
- [ ] Create the student **before** publishing their result; results for unknown IDs are
      rejected with `404 student_not_found`.
- [ ] Publish results in bulk (≤ 200 per call) and re-send only the failed items.
- [ ] Handle `422` by surfacing `errors` to the operator; do not retry unchanged data.
- [ ] Respect `429` and `Retry-After`; throttle bulk imports.
- [ ] Let the university generate `student_id` unless you have been explicitly given an
      ID range.
- [ ] Send dates as `YYYY-MM-DD`, phones with country code (`+880…`), UTF-8 text.
- [ ] Compress photos before sending (a 300×400 JPEG is plenty); keep under 5 MB.
- [ ] Persist `data.id`, `data.student_id`, `result_id` and any `warnings` in your system.
- [ ] Keep the API key server-side; rotate it if staff with access leave.
- [ ] Log the `X-API-Version` header so you notice when a new version is announced.

---

## 11. Support

* Technical issues: Prime University IT Office (see the contact you were given with your key). Include the timestamp, endpoint, HTTP status, `code` and your `X-Idempotency-Key`.
* Breaking changes will be released under a new base path (`/v2`). `/v1` fields may gain
  optional additions but existing fields will not change meaning.

---

## Appendix A – Operator notes (university IT)

1. Apply `admin/student-api-clients-v1.sql` once, then `admin/student-results-api-v1.sql`.
2. Issue a key (run on the server, never over HTTP):
   ```bash
   php admin/api/v1/bin/create-client.php --name="Partner CRM" \
       --ips=203.0.113.10 --rate=60 --expires=2027-12-31 --created-by=1
   ```
   Default scopes are `students:create,results:create,reference:read`; restrict with
   `--scopes=` (e.g. a results-only partner gets `--scopes=results:create`).
   `--created-by` is the `users.id` recorded as `students.created_by` and in `change_log`.
3. Manage: `--list`, `--revoke=<id>`, `--enable=<id>`.
4. Audit: `api_client_requests` holds every call (status, IP, created `students.id`);
   `students.api_client_id` and `student_results.api_client_id` identify API-written rows;
   every published result also appears in the Change Log as `final_result` on the student.
5. Results published through the API behave exactly like the **Final Result Publish**
   admin import (same `student_results` upsert key, same Graduated rule) and are visible
   on the public certificate-verification page immediately.
6. Adjust `default_status`, `scopes`, `rate_limit_per_min`, `ip_allowlist` directly in
   `api_clients` if needed.
