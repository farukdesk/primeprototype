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
└── util/                    # AppResult, formatters
```

## Notes

- **Push notifications (FCM) are intentionally omitted** so the project imports and
  runs without a Firebase `google-services.json`. The API already exposes a
  `push/register.php` endpoint if you later add Firebase.
- The app is HTTPS-only (see `network_security_config.xml`).
