# PUMIS Admin – Android App

**Prime University Management Information System – Admin Mobile App**

A Flutter-based Android app for PUMIS administrators. Provides secure login, real-time push notifications, biometric authentication, and an offline-aware dashboard.

---

## Architecture Overview

```
Backend (PHP REST API)        Flutter App (Android)
──────────────────────        ─────────────────────────
admin/api/
  auth/login.php    ◄──────── ApiService.login()
  auth/logout.php   ◄──────── ApiService.logout()
  auth/me.php       ◄──────── ApiService.me()
  push/register.php ◄──────── FcmService.registerToken()

Firebase FCM server key
  (admin settings table)  ──► Push to api_push_tokens
                               ▼
                          Android device notifications
```

---

## Prerequisites

| Tool | Version |
|------|---------|
| Flutter SDK | ≥ 3.22.0 |
| Android Studio | Hedgehog or later |
| Android SDK | API 23+ (minSdk), API 34 (targetSdk) |
| Firebase project | Required for push notifications |
| PHP server | Running PUMIS backend |

---

## 1. Backend Setup

### Run the SQL migration
```sql
-- Connect to admin_primepnew2026 and run:
source admin/api-tokens.sql;
```

This creates:
- `api_tokens` – stores mobile session tokens
- `api_push_tokens` – stores FCM device tokens
- Adds `fcm_server_key` setting row

### Configure FCM server key
1. Go to [Firebase Console](https://console.firebase.google.com) → Your project → Project Settings → Cloud Messaging
2. Copy the **Server key** (legacy HTTP API)
3. In PUMIS admin → Settings → update `fcm_server_key`

### Update API base URL
Edit `mobile/lib/services/api_service.dart` line 13:
```dart
static const String baseUrl = 'https://primeuniversity.ac.bd/admin/api';
```

---

## 2. Firebase Project Setup

1. Go to [Firebase Console](https://console.firebase.google.com) → Add project
2. Add an **Android app**:
   - Package name: `bd.ac.primeuniversity.pumisadmin`
   - Download `google-services.json`
3. Place `google-services.json` at `mobile/android/app/google-services.json`

---

## 3. Flutter App Setup

```bash
cd mobile

# Install dependencies
flutter pub get

# Generate native splash screen
flutter pub run flutter_native_splash:create --path=flutter_native_splash.yaml

# Add splash logo (dark blue background + white logo PNG)
# Place a 200×200 white-on-transparent PNG at:
# mobile/assets/images/splash_logo.png
```

---

## 4. Release Signing

### Generate a keystore (do this once, keep the file safe)
```bash
keytool -genkey -v \
  -keystore pumis-release.jks \
  -alias pumis \
  -keyalg RSA \
  -keysize 2048 \
  -validity 10000
```

### Create `mobile/android/key.properties`
```
storePassword=<your_keystore_password>
keyPassword=<your_key_password>
keyAlias=pumis
storeFile=<absolute_or_relative_path_to_pumis-release.jks>
```

> ⚠️ **Never commit `key.properties` or `pumis-release.jks` to version control.**

---

## 5. Build

```bash
cd mobile

# Debug APK (for testing on device)
flutter build apk --debug

# Release AAB (for Google Play)
flutter build appbundle --release

# Release APK (for direct sideload distribution)
flutter build apk --release --split-per-abi
```

Output locations:
- APK: `build/app/outputs/flutter-apk/`
- AAB: `build/app/outputs/bundle/release/`

---

## 6. Google Play Store Publishing

### Option A – Internal Testing (Recommended for admin-only app)
1. Create a [Google Play Developer account](https://play.google.com/console) ($25 one-time)
2. Create a new app
3. Upload the release AAB to **Internal testing** track
4. Add tester email addresses (only those emails can install)
5. No public review required for internal testing

### Option B – Direct APK Distribution (No Play Store)
Host the release APK on your server and share the download link. Users must enable "Install from unknown sources" in Android settings.

### Required store metadata
| Item | Size |
|------|------|
| App icon | 512×512 PNG |
| Feature graphic | 1024×500 PNG |
| Screenshots | 2–8 (phone) |
| Short description | ≤ 80 characters |
| Privacy policy URL | `https://primeuniversity.ac.bd/privacy-policy.php` |

---

## 7. Push Notifications

Push notifications are sent automatically when:
- A **new IT Support Ticket** is created → notifies all users with `support-tickets` access
- A **Broadcast is approved** → notifies all users with `broadcast` access

### Manual push (future)
Implement `admin/api/push/send.php` to send manual pushes to specific users or groups.

### FCM token lifecycle
1. User logs in on the app
2. App fetches FCM token from Firebase → POSTs to `/api/push/register.php`
3. Token is stored in `api_push_tokens`
4. Backend calls `send_push_notification()` from `admin/api/includes/fcm.php`

---

## 8. Features

| Feature | Status |
|---------|--------|
| Admin login (username/email + password) | ✅ |
| Secure token storage (EncryptedSharedPreferences) | ✅ |
| Biometric auto-login (fingerprint / face) | ✅ |
| Native splash screen (dark blue, app logo) | ✅ |
| Offline detection + banner | ✅ |
| Push notifications (FCM) | ✅ |
| Auto-refresh dashboard (60s) | ✅ |
| Camera permission (for future use) | ✅ |
| Permission-aware module grid | ✅ |
| 30-day session tokens | ✅ |
| HTTPS-only network config | ✅ |

---

## 9. Security Notes

- API tokens are stored as SHA-256 hashes in the database (plain token only ever sent to the client once)
- `android:usesCleartextTraffic="false"` enforces HTTPS
- `EncryptedSharedPreferences` protects the token on-device
- Biometric check re-validates the user before granting access with a stored token
- `minSdkVersion 23` ensures Android Keystore is always available
- No secrets are hardcoded in the app; the FCM server key lives only on the server

---

## Project Structure

```
mobile/
├── lib/
│   ├── main.dart                   # App entry point
│   ├── app.dart                    # Router + theme
│   ├── models/
│   │   └── user_model.dart
│   ├── screens/
│   │   ├── splash_screen.dart      # Boot + session restore
│   │   ├── login_screen.dart       # Login + biometrics
│   │   └── dashboard_screen.dart   # Main dashboard
│   └── services/
│       ├── api_service.dart        # HTTP client (Dio)
│       ├── auth_service.dart       # Auth state management
│       ├── fcm_service.dart        # Firebase push notifications
│       ├── storage_service.dart    # Secure token storage
│       └── connectivity_service.dart
├── android/
│   ├── app/
│   │   ├── build.gradle            # App-level build (signing, minSdk)
│   │   └── src/main/
│   │       ├── AndroidManifest.xml # Permissions, FCM config
│   │       ├── kotlin/…/MainActivity.kt
│   │       └── res/
│   │           ├── values/styles.xml
│   │           └── xml/network_security_config.xml
│   ├── build.gradle                # Project-level build
│   ├── settings.gradle
│   └── gradle.properties
├── pubspec.yaml                    # Dependencies
└── flutter_native_splash.yaml      # Splash screen config
```

---

## Backend Files Added

```
admin/
├── api-tokens.sql                  # SQL migration (run once)
└── api/
    ├── includes/
    │   ├── auth_api.php            # Token middleware + helpers
    │   └── fcm.php                 # FCM push notification helper
    ├── auth/
    │   ├── login.php               # POST – issue token
    │   ├── logout.php              # POST – revoke token
    │   └── me.php                  # GET  – user profile + unread counts
    └── push/
        └── register.php            # POST – register FCM device token
```

Push notification hooks added to:
- `admin/support-tickets/create.php` (new ticket → notify IT staff)
- `admin/broadcast/review.php` (broadcast approved → notify broadcast users)
