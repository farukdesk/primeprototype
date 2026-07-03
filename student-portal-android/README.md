# PU Student Portal — Native Android App

A production-ready native **Android (Kotlin)** app for the Prime University student
portal. It shows students exactly what they see on the current student profile:
their dashboard, notices, finances, and profile. It talks to the existing PHP REST
API under `admin/api/student/`, so no backend changes are required.

> This is a full Android Studio / Gradle project. Open it in Android Studio,
> let it sync, and run — no Flutter/Dart toolchain needed.

---

## Features

- **Login** with student ID / username + password (token-based session).
- **Dashboard** — welcome header, notices & outstanding-balance stat cards, recent notices.
- **Notices** — University / Department segmented list with detail screen and attachments.
- **Finances** — fee summary, per-semester breakdown with progress, and payment history.
- **Profile** — avatar, academic and contact information.
- **Settings** — account summary, Privacy Policy link, app version, and Sign Out.
- **Push notifications** — receives announcements pushed from the admin panel's
  *App Notification* module via Firebase Cloud Messaging (FCM).
- Secure token storage (EncryptedSharedPreferences), Material 3 UI, edge-to-edge, HTTPS-only.

## Tech stack

| Area            | Choice                                   |
|-----------------|------------------------------------------|
| Language        | Kotlin                                   |
| Architecture    | MVVM (ViewModel + Repository)            |
| Networking      | Retrofit + OkHttp + Gson                 |
| Async           | Kotlin Coroutines                        |
| UI              | Android Views + ViewBinding + Material 3 |
| Secure storage  | AndroidX Security (EncryptedSharedPreferences) |
| Min / Target SDK| 24 / 34                                  |

## Requirements

- Android Studio (Koala / 2024.1 or newer recommended)
- JDK 17
- Android SDK Platform 34 + Build-Tools 34
- Internet access to Google's Maven repository (`dl.google.com`) on first sync to
  download AndroidX / the Android Gradle Plugin.

## Getting started

1. **Open** the `student-portal-android/` folder in Android Studio
   (*File ▸ Open*), or import it as an existing Gradle project.
2. Let Gradle **sync** (it downloads AGP, Kotlin, and AndroidX dependencies).
3. Pick a device/emulator and press **Run ▶**.

That's it — the app defaults to the live API base URL.

## Configuration

### API base URL

Set in `app/build.gradle` via `BuildConfig.API_BASE_URL`
(default `https://primeuniversity.ac.bd/admin/api/student/`). Change it there if
your server differs, then re-sync. Keep the trailing slash.

### Push notifications (Firebase Cloud Messaging)

The app receives push notifications sent from the admin panel's **App Notification**
module. Delivery uses Firebase Cloud Messaging (FCM HTTP v1). To enable it you need
a Firebase project — set it up once:

**1. Create the Firebase project & Android app**
- Go to the [Firebase console](https://console.firebase.google.com/) and create a
  project (or reuse an existing one).
- Add an **Android app** with package name **`bd.ac.primeuniversity.studentportal`**
  (the debug build uses the `.debug` suffix; add
  `bd.ac.primeuniversity.studentportal.debug` too if you want pushes in debug).

**2. Add `google-services.json` to the app**
- Download `google-services.json` from the Firebase console and place it in
  `student-portal-android/app/`.
- The `com.google.gms.google-services` Gradle plugin is applied **only when this
  file is present**, so the project still builds without it (push simply stays
  inactive). Re-sync after adding it. The file is git-ignored.

**3. Configure the server (admin panel)**
- In the Firebase console open **Project settings → Service accounts → Generate new
  private key** and download the service-account JSON.
- In the admin panel go to **App Notification → FCM Settings** and paste that JSON.
  It is stored in the `settings` table (key `fcm_service_account`) and used by the
  FCM HTTP v1 sender.

**4. Run the migration** — apply `admin/app-notifications.sql` once to create the
`app_notifications` table and register the module.

Once configured, publishing a notification from *App Notification* pushes it to
every installed app that has registered a device token (via
`admin/api/student/push/register.php`). On Android 13+ the app requests the
`POST_NOTIFICATIONS` permission at runtime.

### Release signing (optional)

To build a signed release, create `keystore.properties` in this folder:

```properties
storeFile=/absolute/path/to/your.jks
storePassword=****
keyAlias=****
keyPassword=****
```

Then build the `release` variant. If the file is absent, debug builds still work.
`keystore.properties` and `*.jks` are already git-ignored.

## Project structure

```
app/src/main/java/bd/ac/primeuniversity/studentportal/
├── PrimeApp.kt              # Application + shared session state
├── data/
│   ├── api/                 # Retrofit service, client, auth interceptor
│   ├── local/               # Encrypted token storage
│   ├── model/               # Data models + API response DTOs
│   └── repo/                # StudentRepository (all API access)
├── ui/
│   ├── splash/  login/  main/
│   ├── dashboard/ notices/ finances/ profile/ settings/
├── messaging/               # FCM service + notification helpers
└── util/                    # AppResult, formatters
```

## Notes

- **Push notifications (FCM)** activate once a Firebase `google-services.json` is
  added to `app/` and the admin panel's *App Notification → FCM Settings* holds a
  service-account credential (see *Configuration → Push notifications* above).
  Without `google-services.json` the project still builds and runs; push is simply
  inactive. Device tokens are registered via `push/register.php`.
- The app is HTTPS-only (see `network_security_config.xml`).
