import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:flutter/foundation.dart';

import 'api_service.dart';
import 'storage_service.dart';

/// Handles Firebase Cloud Messaging for push notifications.
class FcmService {
  static final FlutterLocalNotificationsPlugin _localNotif =
      FlutterLocalNotificationsPlugin();

  static const _channelId   = 'sp_high_importance';
  static const _channelName = 'PU Notices';
  static const _channelDesc = 'Prime University important notices';

  /// Register the background message handler – must be called before runApp().
  static void initBackground() {
    FirebaseMessaging.onBackgroundMessage(_firebaseBackgroundHandler);
  }

  /// Initialize FCM, request permission, set up local notification channel.
  static Future<void> init() async {
    // Request notification permission (Android 13+)
    final messaging = FirebaseMessaging.instance;
    await messaging.requestPermission(
      alert:      true,
      badge:      true,
      sound:      true,
      provisional: false,
    );

    // Android local notification channel
    const androidChannel = AndroidNotificationChannel(
      _channelId,
      _channelName,
      description:  _channelDesc,
      importance:   Importance.high,
      playSound:    true,
      enableVibration: true,
    );

    await _localNotif
        .resolvePlatformSpecificImplementation<
            AndroidFlutterLocalNotificationsPlugin>()
        ?.createNotificationChannel(androidChannel);

    const initSettings = InitializationSettings(
      android: AndroidInitializationSettings('@mipmap/ic_launcher'),
    );
    await _localNotif.initialize(
      initSettings,
      onDidReceiveNotificationResponse: _onNotificationTap,
    );

    // Foreground messages
    FirebaseMessaging.onMessage.listen(_showForegroundNotification);

    // Subscribe to the university-wide topic so ALL students receive push
    // notifications even before logging in (topic subscription is handled
    // by the app at login time).
    //
    // NOTE: Individual student tokens are also registered via the API for
    // targeted dept-specific notifications.
  }

  /// Subscribe to the global notices topic and register device token with API.
  static Future<void> registerToken({required Function() onUnauthorized}) async {
    try {
      final token = await FirebaseMessaging.instance.getToken();
      if (token == null) return;

      final deviceId = await StorageService.getDeviceId() ?? '';
      await ApiService.registerPushToken(
        fcmToken:       token,
        deviceId:       deviceId,
        onUnauthorized: onUnauthorized,
      );

      // Listen for token refresh
      FirebaseMessaging.instance.onTokenRefresh.listen((newToken) {
        ApiService.registerPushToken(
          fcmToken:       newToken,
          deviceId:       deviceId,
          onUnauthorized: onUnauthorized,
        );
      });
    } catch (e) {
      debugPrint('FCM registerToken error: $e');
    }
  }

  // ── Private helpers ───────────────────────────────────────────────────────

  static Future<void> _showForegroundNotification(RemoteMessage message) async {
    final n = message.notification;
    if (n == null) return;

    await _localNotif.show(
      n.hashCode,
      n.title,
      n.body,
      NotificationDetails(
        android: AndroidNotificationDetails(
          _channelId,
          _channelName,
          channelDescription: _channelDesc,
          importance:  Importance.high,
          priority:    Priority.high,
          icon:        '@mipmap/ic_launcher',
          color:       const Color(0xFF1B3A6B),
          styleInformation: BigTextStyleInformation(n.body ?? ''),
        ),
      ),
    );
  }

  static void _onNotificationTap(NotificationResponse response) {
    // Navigation handled by the app on next foreground entry via
    // FirebaseMessaging.instance.getInitialMessage() in SplashScreen.
  }
}

@pragma('vm:entry-point')
Future<void> _firebaseBackgroundHandler(RemoteMessage message) async {
  // Background messages are auto-displayed by FCM; no additional code needed.
}
