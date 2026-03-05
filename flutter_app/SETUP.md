# Flutter App Setup Guide

## Prerequisites

1. **Flutter SDK** (3.x or later) — [Install Flutter](https://docs.flutter.dev/get-started/install)
2. **Android Studio** with Android SDK
3. **Firebase project** for push notifications

## Quick Start

### 1. Generate Platform Files

Since this project was scaffolded manually, you need to generate the platform-specific directories:

```bash
cd flutter_app

# Generate android/, ios/, test/ directories
flutter create --org com.martialarts --project-name martial_arts_app .

# Install dependencies
flutter pub get
```

### 2. Firebase Setup

1. Go to [Firebase Console](https://console.firebase.google.com/)
2. Create a new project (or use existing)
3. Add an Android app:
   - Package name: `com.martialarts.martial_arts_app`
   - Download `google-services.json`
   - Place it in `flutter_app/android/app/google-services.json`
4. Add Firebase SDK to Android:

**`android/build.gradle`** (project-level):
```gradle
buildscript {
    dependencies {
        classpath 'com.google.gms:google-services:4.4.0'
    }
}
```

**`android/app/build.gradle`** (app-level):
```gradle
apply plugin: 'com.google.gms.google-services'

android {
    defaultConfig {
        minSdkVersion 21  // Required for firebase_messaging
    }
}
```

### 3. Configure Server URL

Edit `lib/core/network/api_client.dart` and update:

```dart
static const String defaultBaseUrl = 'https://your-domain.com/api';
```

- For Android emulator testing: `http://10.0.2.2/MartialArtsApp/api`
- For physical device: Use your computer's LAN IP (e.g., `http://192.168.1.100/MartialArtsApp/api`)
- For production: Use your live server URL with HTTPS

### 4. Configure FCM Service Account (Server-Side)

The server uses the **FCM HTTP v1 API** with OAuth 2.0 service account authentication:

1. In **Firebase Console → Project Settings → Service accounts**
2. Click **"Generate new private key"** to download a JSON file
3. In the web admin panel, go to **Settings > System > Push Notifications (FCM)**
4. Upload the downloaded JSON file
5. The project ID and service account email will be detected automatically

> **Note**: The legacy FCM server key method is no longer supported (Google shut down the Legacy API in July 2024). The v1 API with service account authentication is required.

### 5. Build & Run

```bash
# Debug build
flutter run

# Release APK
flutter build apk --release

# The APK will be at: build/app/outputs/flutter-apk/app-release.apk
```

## Project Structure

```
lib/
├── main.dart                 # Entry point, Firebase init
├── app.dart                  # MaterialApp, GoRouter, theme
├── core/
│   ├── constants/            # API endpoints, app constants
│   ├── network/              # Dio client, auth interceptor
│   ├── storage/              # Secure storage, local cache
│   └── theme/                # Dynamic theme, branding
├── features/
│   ├── auth/                 # Login, AuthBloc, user model
│   ├── student/              # 7 screens: dashboard, classes, attendance, events, messages, belts, profile
│   ├── parent/               # 2 screens: children overview, child detail
│   ├── admin/                # 6 screens: dashboard, students, classes, attendance, events, messages
│   └── notifications/        # FCM service, token management
└── shared/
    └── widgets/              # Drawer, loading, skeleton, avatars, error states
```

## Architecture

- **State Management**: BLoC (flutter_bloc)
- **Navigation**: GoRouter with auth redirect guards
- **HTTP**: Dio with auto token refresh interceptor
- **Auth**: JWT access + refresh tokens, stored in flutter_secure_storage
- **Theming**: Dynamic theme fetched from /api/theme endpoint
- **Notifications**: Firebase Cloud Messaging with local notification display

## Troubleshooting

### "flutter: command not found"
Install Flutter SDK and add it to your PATH.

### Build errors about minSdkVersion
Ensure `android/app/build.gradle` has `minSdkVersion 21` or higher.

### API connection refused
- Emulator: Use `10.0.2.2` (maps to host machine's localhost)
- Physical device: Use your LAN IP and ensure phone is on same network
- Check CORS headers are set in `api/index.php`

### Push notifications not working
1. Verify `google-services.json` is in `android/app/`
2. Verify the Firebase service account JSON is uploaded in admin **Settings > System > Push Notifications**
3. Check that the Firebase project ID matches between the app's `google-services.json` and the service account
4. Check device token is registered: query the `device_tokens` table in the database
5. Check `app_log` table for FCM-related errors (category = 'fcm')
