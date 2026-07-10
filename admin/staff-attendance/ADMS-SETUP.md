# ZKTeco ECO → ERP attendance (ADMS / iclock) setup

This connects a ZKTeco device running in **Cloud Server (ADMS)** mode to the Staff
Attendance module. The device dials **out** to this server and pushes punches to a
fixed `/iclock/…` path; the receiver folds them into `att_records`.

## 1. Apply the database migration

```sh
mysql -u <user> -p <database> < admin/staff-attendance-adms.sql
```

This creates `att_devices`, `att_device_users`, `att_punch_log` and
`att_device_log`, and is safe to re-run.

## 2. Register the device in the ERP

Open **Staff Attendance → Devices** (requires the module's *can_edit* permission):

1. **Register Device** and enter the exact **Serial Number (SN)** shown on the
   device (Menu → System Info / About). Only whitelisted, active serials are
   accepted — every other request is rejected and logged.
2. Map each device **User ID** (the enrollment ID configured per employee on the
   device, sometimes labelled *PIN* in ZKTeco firmware) to an ERP staff member
   under **Device User ID → Staff Mapping**. Use *All devices* for a shared
   mapping, or pick a device to override it. Unmapped IDs are still
   logged (as *Unmapped*) but not folded into attendance.
3. Optionally set a **System user** (credited as `created_by` on auto-generated
   rows) and the **device time zone** (Asia/Dhaka = `360`).

## 3. Configure the device (Comm. → Cloud Server / ADMS)

| Device field | Value |
|---|---|
| **Enable Domain Name** | ON if you enter a domain; OFF for a raw IP |
| **Server Address** | This server's public IP or domain |
| **Server Port** | The port this app is served on (usually `80`, or `443` behind a TLS proxy) |

The device then pushes to `http(s)://<address>:<port>/iclock/` automatically. The
`/iclock/` path is hard-coded in the device firmware and cannot be changed, which
is why the receiver lives at the web root (`iclock/index.php`) and not under
`/admin`.

## 4. Hardening (recommended)

The device only authenticates by serial number, so layer additional controls
(all configurable per device on the Devices page):

- **IP allow-list** — restrict to the device's known source IP(s). Only the real
  `REMOTE_ADDR` is checked (a spoofed `X-Forwarded-For` cannot bypass it); if you
  run behind a reverse proxy, add the proxy IP or have it forward the real one.
- **Shared secret** — when set, requests must carry it as `?key=…`, an
  `Authorization` header, or an `X-ADMS-Key` header (compared in constant time).
  Useful when a reverse proxy can inject it for defence in depth.
- **TLS** — terminate HTTPS on a proxy and forward to the app so punches are not
  sent in clear text.
- **Network isolation** — keep the device on a management VLAN that can only reach
  this endpoint.

Every request (accepted or rejected) is audited in `att_device_log` and surfaced
under **Recent Requests** on the Devices page.

## 5. Verify end-to-end

1. On the device, force an upload (punch, or **Sync Data to Cloud / Upload**).
2. On the Devices page confirm **Last Seen** / **Last Push** update and the punch
   appears under **Recent Punches**.
3. Confirm the staff member's row shows the correct in/out time on the main
   Staff Attendance report. The earliest punch of a day becomes `in_time` and the
   latest becomes `out_time`; a single punch leaves `out_time` empty ("No Out
   Time"). Re-sent or out-of-order punches are de-duplicated and always converge
   to the correct row (the raw `att_punch_log` is the source of truth).
