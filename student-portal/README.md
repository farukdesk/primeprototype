# Prime University Student Portal – Android App

**Full production-ready Flutter Android app for Prime University students.**

Students can log in with their portal credentials and access:
- 📢 **Notices** – University-wide and department-specific, with **push notifications**
- 💰 **Finances** – Fee schedule, outstanding balance, payment history
- 👤 **Profile** – Academic info and contact details
- 🏠 **Dashboard** – Quick stats and recent notices

---

## Architecture Overview

```
Backend (PHP REST API)                Flutter App (Android)
─────────────────────────────         ─────────────────────────────────────
admin/api/student/
  auth/login.php       ◄──────────── ApiService.login()
  auth/logout.php      ◄──────────── ApiService.logout()
  auth/me.php          ◄──────────── ApiService.me()
  notices.php          ◄──────────── ApiService.getNotices()
  finances.php         ◄──────────── ApiService.getFinances()
  push/register.php    ◄──────────── FcmService.registerToken()

Website notice publish hooks → sp_notify_students_notice()
  → Sends EMAIL to students
  → Sends FCM PUSH to student devices  ──► Android device notifications
```

---

## ──────────────────────────────────────────────
## STEP-BY-STEP IMPLEMENTATION GUIDE
## ──────────────────────────────────────────────

### STEP 1 – Run the SQL Migration

Connect to your database (`admin_primepnew2026`) and run:

```sql
source admin/student-portal-api.sql;
```

This creates:
- `student_push_tokens` – stores FCM device tokens for student accounts
- Adds `student_fcm_server_key` row in the `settings` table
- Adds `push_sent_at` column to `cms_notices` and `dept_notices`

---

### STEP 2 – Create a Firebase Project

1. Go to **[Firebase Console](https://console.firebase.google.com)** → **Add project**
2. Name it: `Prime University Student Portal`
3. **Add an Android app:**
   - Package name: `bd.ac.primeuniversity.studentportal`
   - App nickname: `PU Student Portal`
   - Download `google-services.json`
4. Place `google-services.json` at:
   ```
   student-portal/android/app/google-services.json
   ```

---

### STEP 3 – Get the FCM Server Key

1. In Firebase Console → Project Settings → **Cloud Messaging** tab
2. Find **Server key** (under "Cloud Messaging API (Legacy)")
   > ⚠️ If you see "Firebase Cloud Messaging API (Legacy) is disabled", click the three-dot menu and **Enable** it.
3. Copy the **Server key** (a long string starting with `AAAA...`)
4. In your PUMIS admin panel → **Settings** → find the row labelled
   **`Student Portal FCM Server Key`** → paste the key and save.

> 💡 **Alternative:** If you already have an FCM server key set for the admin app, you can use the same key for the student portal. The student portal uses a separate `student_push_tokens` table so there is no conflict.

---

### STEP 4 – Update the API Base URL

Open `student-portal/lib/services/api_service.dart` and update line 15:

```dart
static const String baseUrl = 'https://primeuniversity.ac.bd/admin/api/student';
```

Replace with your actual server URL if it differs. **No trailing slash.**

---

### STEP 5 – Add Your App Logo

Place a **200×200 white-on-transparent PNG** at:
```
student-portal/assets/images/splash_logo.png
```

Also replace the splash icon in:
```
student-portal/android/app/src/main/res/mipmap-*/ic_launcher.png
```
(Standard Android launcher icon sizes: 48×48, 72×72, 96×96, 144×144, 192×192)

After adding the logo, generate the native splash screen:
```bash
cd student-portal
flutter pub run flutter_native_splash:create --path=flutter_native_splash.yaml
```

---

### STEP 6 – Open in Android Studio

1. Open **Android Studio**
2. **File → Open** → select the `student-portal/` folder
3. Android Studio will detect the Flutter project automatically
4. Wait for **Gradle sync** to complete (may take 2–5 minutes on first run)
5. Click **Run ▶** to test on an emulator or connected device

---

### STEP 7 – Generate a Release Keystore (One-time, KEEP SAFE)

Open a terminal and run:
```bash
keytool -genkey -v \
  -keystore pu-student-portal-release.jks \
  -alias pusp \
  -keyalg RSA \
  -keysize 2048 \
  -validity 10000
```
Fill in your details when prompted. **Back up this file securely.**

---

### STEP 8 – Configure Release Signing

Create `student-portal/android/key.properties`:
```properties
storePassword=<your_keystore_password>
keyPassword=<your_key_password>
keyAlias=pusp
storeFile=<absolute_path_to_pu-student-portal-release.jks>
```

> ⚠️ **NEVER commit `key.properties` or the `.jks` file to version control.**

---

### STEP 9 – Build the Release APK / AAB

```bash
cd student-portal

# Install dependencies
flutter pub get

# Debug APK (for testing on device)
flutter build apk --debug

# Release APK (for direct distribution)
flutter build apk --release --split-per-abi

# Release AAB (for Google Play Store – preferred)
flutter build appbundle --release
```

Output locations:
| Build type  | Path |
|-------------|------|
| Debug APK   | `build/app/outputs/flutter-apk/app-debug.apk` |
| Release APK | `build/app/outputs/flutter-apk/app-arm64-v8a-release.apk` |
| Release AAB | `build/app/outputs/bundle/release/app-release.aab` |

**Install directly to a phone (debug):**
```bash
flutter install
```

---

### STEP 10 – Test Push Notifications

#### A. End-to-end test from the website
1. Log in to the **PUMIS admin panel** as a super admin
2. Go to **CMS → Notice Board → Edit** any published notice
3. Re-save it (this re-triggers the push hook for testing)
4. The student app should receive a push notification within seconds

#### B. Manual test from Firebase Console
1. Firebase Console → Messaging → **New campaign**
2. Select **Firebase Notification messages**
3. Fill in Title and Body
4. Under **Target**, choose **Topic** → enter `pumis_broadcast`
   *(or target specific tokens)*
5. Click **Review → Publish**

#### C. Test via cURL (server-side)
```bash
curl -X POST https://fcm.googleapis.com/fcm/send \
  -H "Authorization: key=YOUR_FCM_SERVER_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "registration_ids": ["DEVICE_FCM_TOKEN_HERE"],
    "notification": {
      "title": "Test Notice",
      "body": "This is a test push notification",
      "sound": "default"
    },
    "priority": "high"
  }'
```

---

### STEP 11 – Publish to Google Play Store

1. Create a [Google Play Developer account](https://play.google.com/console) ($25 one-time fee)
2. Create a **new app** → enter app details
3. Complete the **Store listing** (required):

| Item | Requirement |
|------|-------------|
| App name | `PU Student Portal` |
| Short description | ≤ 80 characters |
| Full description | Describe features |
| App icon | 512×512 PNG |
| Feature graphic | 1024×500 PNG |
| Screenshots | At least 2 phone screenshots |
| Privacy policy URL | `https://primeuniversity.ac.bd/privacy-policy.php` |

4. Complete **App content** section (content rating, target audience, etc.)
5. Under **Release → Production**, upload the **AAB** file
6. Submit for **Google review** (takes 1–3 days for new apps)

> 💡 **Tip for faster approval:** First release to **Internal testing** track, test with 5 team members, then promote to Production.

---

## How Push Notifications Work (End-to-End)

```
Admin publishes/approves a notice on the website
    ↓
PHP: sp_notify_students_notice() is called
    ↓
    ├── [Email] Sends email to all student portal accounts
    └── [FCM]   sp_send_fcm_notice_push() is called
                    ↓
                Query student_push_tokens for relevant students
                    ↓
                POST to https://fcm.googleapis.com/fcm/send
                (multicast – up to 500 tokens per batch)
                    ↓
                Firebase delivers notification to student devices
                    ↓
                Android shows notification in tray even when app is closed
                    ↓
                Student taps notification → app opens to Notices tab
```

### Which notices trigger push notifications?

| Action | Push sent? |
|--------|-----------|
| Super admin publishes a university notice (is_published: 0→1) | ✅ |
| Non-super admin's notice is **approved** (approve-create flow) | ✅ |
| New **department notice** created with `is_active = 1` | ✅ (dept students only) |
| Department notice edited and re-activated | ✅ |
| Notice edited but status unchanged | ❌ |

---

## Project Structure

```
student-portal/
├── lib/
│   ├── main.dart                        # App entry point
│   ├── app.dart                         # Router + theme
│   ├── theme/
│   │   └── app_theme.dart               # Brand colours, Material 3 theme
│   ├── models/
│   │   ├── student_model.dart           # Student profile data
│   │   ├── notice_model.dart            # University/dept notice
│   │   └── finance_model.dart           # Fee summary + payments
│   ├── services/
│   │   ├── api_service.dart             # Dio HTTP client → student API
│   │   ├── auth_service.dart            # Login, logout, session restore
│   │   ├── fcm_service.dart             # Firebase push notifications
│   │   ├── storage_service.dart         # Secure token storage
│   │   └── connectivity_service.dart    # Online/offline detection
│   └── screens/
│       ├── splash_screen.dart           # Boot + session restore
│       ├── login_screen.dart            # Login UI
│       ├── home_screen.dart             # Bottom nav host
│       ├── notice_detail_screen.dart    # Full notice + attachment
│       ├── settings_screen.dart         # Logout, about, privacy
│       └── tabs/
│           ├── dashboard_tab.dart       # Welcome card + stats + recent notices
│           ├── notices_tab.dart         # University/dept notice list
│           ├── finances_tab.dart        # Fee schedule + payments
│           └── profile_tab.dart         # Student academic profile
├── android/
│   ├── app/
│   │   ├── build.gradle                 # App-level build (signing, package name)
│   │   ├── proguard-rules.pro           # Release minification rules
│   │   └── src/main/
│   │       ├── AndroidManifest.xml      # Permissions, FCM config
│   │       ├── kotlin/bd/ac/primeuniversity/studentportal/
│   │       │   └── MainActivity.kt      # Flutter activity
│   │       └── res/
│   │           ├── values/styles.xml    # Splash + normal themes
│   │           └── xml/network_security_config.xml
│   ├── build.gradle                     # Project-level build
│   ├── settings.gradle
│   └── gradle.properties
├── assets/images/                       # Put splash_logo.png here
├── pubspec.yaml                         # Flutter dependencies
├── flutter_native_splash.yaml           # Splash screen config
└── README.md                            # This file
```

---

## Backend Files Added/Modified

```
admin/
├── student-portal-api.sql              # Run this SQL migration once
├── api/student/
│   ├── includes/
│   │   └── auth_student_api.php        # Token auth middleware for students
│   ├── auth/
│   │   ├── login.php                   # POST – student login → token
│   │   ├── logout.php                  # POST – revoke token
│   │   └── me.php                      # GET  – student profile + stats
│   ├── notices.php                     # GET  – university/dept notices (paginated)
│   ├── finances.php                    # GET  – fee summary + payments
│   └── push/
│       └── register.php                # POST – register FCM device token
└── students/
    └── helpers.php                     # MODIFIED: sp_notify_students_notice()
                                        #   now sends FCM push in addition to email
```

---

## Security Notes

- API tokens are SHA-256 hashed in `api_tokens` (plain token only sent once)
- `android:usesCleartextTraffic="false"` enforces HTTPS
- `EncryptedSharedPreferences` (flutter_secure_storage) protects the token on-device
- `minSdkVersion 23` ensures Android Keystore is always available
- FCM server key is never hardcoded in the app; it is stored only on the server
- Student API validates both the token AND that the user is a portal student

---

## Prerequisites

| Tool | Version |
|------|---------|
| Flutter SDK | ≥ 3.22.0 |
| Android Studio | Hedgehog or later |
| Android SDK | API 23+ (minSdk), API 34 (targetSdk) |
| Firebase project | Required for push notifications |
| PHP server | Running PUMIS backend |
| MySQL | `admin/student-portal-api.sql` migration applied |
