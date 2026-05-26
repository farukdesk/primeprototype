import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:flutter/foundation.dart';

import 'api_service.dart';

/// Handles Firebase Cloud Messaging (FCM) setup and local notification display.
@pragma('vm:entry-point')
Future<void> _firebaseBackgroundHandler(RemoteMessage message) async {
  // Background messages are handled here; Firebase SDK initialises automatically.
  debugPrint('FCM background message: ${message.messageId}');
}

class FcmService {
  static final _localNotifications = FlutterLocalNotificationsPlugin();

  static const _androidChannel = AndroidNotificationChannel(
    'pumis_high_importance',
    'PUMIS Notifications',
    description: 'Important PUMIS admin notifications',
    importance: Importance.high,
    playSound: true,
    enableVibration: true,
  );

  // ── Called once before runApp ─────────────────────────────────────────────

  static void initBackground() {
    FirebaseMessaging.onBackgroundMessage(_firebaseBackgroundHandler);
  }

  // ── Full initialisation (called from app UI layer) ────────────────────────

  static Future<void> init() async {
    // Request permission (required on Android 13+)
    await FirebaseMessaging.instance.requestPermission(
      alert: true,
      badge: true,
      sound: true,
    );

    // Create high-importance Android channel
    await _localNotifications
        .resolvePlatformSpecificImplementation<
            AndroidFlutterLocalNotificationsPlugin>()
        ?.createNotificationChannel(_androidChannel);

    // Initialise local notifications plugin
    const androidInit = AndroidInitializationSettings('@mipmap/ic_launcher');
    await _localNotifications.initialize(
      const InitializationSettings(android: androidInit),
      onDidReceiveNotificationResponse: _onNotificationTap,
    );

    // Show notification when app is in foreground
    FirebaseMessaging.onMessage.listen(_showLocalNotification);

    // Notification tapped while app was in background
    FirebaseMessaging.onMessageOpenedApp.listen(_handleNotificationOpen);

    // App launched from a terminated state via notification
    final initial = await FirebaseMessaging.instance.getInitialMessage();
    if (initial != null) {
      _handleNotificationOpen(initial);
    }
  }

  // ── Register FCM token with the server ───────────────────────────────────

  static Future<void> registerToken({
    required String deviceId,
    required VoidCallback onUnauthorized,
  }) async {
    try {
      final token = await FirebaseMessaging.instance.getToken();
      if (token == null) return;

      await ApiService.registerPushToken(
        fcmToken: token,
        deviceId: deviceId,
        onUnauthorized: onUnauthorized,
      );

      // Refresh token when Firebase rotates it
      FirebaseMessaging.instance.onTokenRefresh.listen((newToken) {
        ApiService.registerPushToken(
          fcmToken: newToken,
          deviceId: deviceId,
          onUnauthorized: onUnauthorized,
        );
      });
    } catch (e) {
      debugPrint('FcmService.registerToken error: $e');
    }
  }

  // ── Private helpers ───────────────────────────────────────────────────────

  static Future<void> _showLocalNotification(RemoteMessage message) async {
    final notification = message.notification;
    final android      = message.notification?.android;
    if (notification == null || android == null) return;

    await _localNotifications.show(
      notification.hashCode,
      notification.title,
      notification.body,
      NotificationDetails(
        android: AndroidNotificationDetails(
          _androidChannel.id,
          _androidChannel.name,
          channelDescription: _androidChannel.description,
          importance: Importance.high,
          priority: Priority.high,
          icon: android.smallIcon,
        ),
      ),
      payload: message.data['type'],
    );
  }

  static void _onNotificationTap(NotificationResponse response) {
    // TODO: navigate to the relevant screen based on response.payload
    debugPrint('Notification tapped: ${response.payload}');
  }

  static void _handleNotificationOpen(RemoteMessage message) {
    // TODO: navigate to the relevant screen based on message.data['type']
    debugPrint('Notification opened app: ${message.data}');
  }
}
