import 'dart:convert';
import 'dart:io';

import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:martial_arts_app/core/constants/api_endpoints.dart';
import 'package:martial_arts_app/core/network/api_client.dart';
import 'package:martial_arts_app/core/storage/secure_storage.dart';

/// Top-level handler for background FCM messages (must be top-level function).
@pragma('vm:entry-point')
Future<void> firebaseMessagingBackgroundHandler(RemoteMessage message) async {
  // Firebase is already initialized by the time this runs.
  debugPrint('[FCM] Background message: ${message.messageId}');
}

/// Manages Firebase Cloud Messaging — token registration, foreground/background
/// notification display, and deep-link payload routing.
class PushNotificationService {
  final FirebaseMessaging _messaging = FirebaseMessaging.instance;
  final FlutterLocalNotificationsPlugin _localNotifications =
      FlutterLocalNotificationsPlugin();
  final ApiClient _apiClient;
  final SecureStorage _storage;

  /// Callback invoked when user taps a notification; receives the route path.
  void Function(String route)? onNotificationTap;

  PushNotificationService({
    required ApiClient apiClient,
    required SecureStorage storage,
  })  : _apiClient = apiClient,
        _storage = storage;

  // ---------- Initialization ----------

  /// Call once after Firebase.initializeApp() and after user is authenticated.
  Future<void> initialize() async {
    // Request permission (required on iOS, auto-granted on Android 12 and below)
    final settings = await _messaging.requestPermission(
      alert: true,
      badge: true,
      sound: true,
      provisional: false,
    );

    debugPrint('[FCM] Permission status: ${settings.authorizationStatus}');

    if (settings.authorizationStatus == AuthorizationStatus.denied) {
      debugPrint('[FCM] Notifications permission denied');
      return;
    }

    // Configure local notifications for foreground display
    await _setupLocalNotifications();

    // Listen for foreground messages
    FirebaseMessaging.onMessage.listen(_handleForegroundMessage);

    // Listen for notification taps (app in background → brought to foreground)
    FirebaseMessaging.onMessageOpenedApp.listen(_handleNotificationTap);

    // Check if app was opened from a terminated state via notification
    final initialMessage = await _messaging.getInitialMessage();
    if (initialMessage != null) {
      _handleNotificationTap(initialMessage);
    }

    // Get and register FCM token
    await _registerToken();

    // Listen for token refresh
    _messaging.onTokenRefresh.listen(_onTokenRefresh);
  }

  // ---------- Local Notifications Setup ----------

  Future<void> _setupLocalNotifications() async {
    // Android channel
    const androidChannel = AndroidNotificationChannel(
      'martial_arts_default',
      'General Notifications',
      description: 'Default notification channel for the martial arts app',
      importance: Importance.high,
      playSound: true,
    );

    // Create the channel on Android
    await _localNotifications
        .resolvePlatformSpecificImplementation<
            AndroidFlutterLocalNotificationsPlugin>()
        ?.createNotificationChannel(androidChannel);

    // Initialize
    const androidSettings =
        AndroidInitializationSettings('@mipmap/ic_launcher');
    const iosSettings = DarwinInitializationSettings(
      requestAlertPermission: false, // Already requested via firebase_messaging
      requestBadgePermission: false,
      requestSoundPermission: false,
    );

    await _localNotifications.initialize(
      settings: const InitializationSettings(
        android: androidSettings,
        iOS: iosSettings,
      ),
      onDidReceiveNotificationResponse: _onLocalNotificationTap,
    );
  }

  // ---------- Token Management ----------

  /// Gets FCM token and registers it with the server.
  Future<void> _registerToken() async {
    try {
      final token = await _messaging.getToken();
      if (token == null) {
        debugPrint('[FCM] Could not get FCM token');
        return;
      }

      debugPrint('[FCM] Token: ${token.substring(0, 20)}...');
      await _sendTokenToServer(token);
    } catch (e) {
      debugPrint('[FCM] Error registering token: $e');
    }
  }

  /// Called when FCM refreshes the token.
  Future<void> _onTokenRefresh(String newToken) async {
    debugPrint('[FCM] Token refreshed');
    await _sendTokenToServer(newToken);
  }

  /// POST the token to the appropriate endpoint based on user type.
  Future<void> _sendTokenToServer(String fcmToken) async {
    try {
      final userType = await _storage.getUserType();
      final endpoint = userType == 'admin'
          ? ApiEndpoints.adminDeviceToken
          : ApiEndpoints.studentDeviceToken;

      await _apiClient.post(endpoint, data: {
        'fcm_token': fcmToken,
        'device_platform': Platform.isIOS ? 'ios' : 'android',
        'device_info': 'Flutter ${Platform.operatingSystem} '
            '${Platform.operatingSystemVersion}',
      });

      debugPrint('[FCM] Token registered with server');
    } catch (e) {
      debugPrint('[FCM] Error sending token to server: $e');
    }
  }

  /// Unregister the device token on logout.
  Future<void> unregisterToken() async {
    try {
      final token = await _messaging.getToken();
      if (token == null) return;

      final userType = await _storage.getUserType();
      final endpoint = userType == 'admin'
          ? ApiEndpoints.adminDeviceToken
          : ApiEndpoints.studentDeviceToken;

      await _apiClient.delete(endpoint, data: {'fcm_token': token});
      debugPrint('[FCM] Token unregistered from server');
    } catch (e) {
      debugPrint('[FCM] Error unregistering token: $e');
    }
  }

  // ---------- Message Handling ----------

  /// Display a local notification when a message arrives while app is in foreground.
  void _handleForegroundMessage(RemoteMessage message) {
    debugPrint('[FCM] Foreground message: ${message.notification?.title}');

    final notification = message.notification;
    if (notification == null) return;

    _localNotifications.show(
      id: message.hashCode,
      title: notification.title ?? 'Notification',
      body: notification.body ?? '',
      notificationDetails: NotificationDetails(
        android: AndroidNotificationDetails(
          'martial_arts_default',
          'General Notifications',
          channelDescription:
              'Default notification channel for the martial arts app',
          importance: Importance.high,
          priority: Priority.high,
          icon: '@mipmap/ic_launcher',
        ),
        iOS: const DarwinNotificationDetails(
          presentAlert: true,
          presentBadge: true,
          presentSound: true,
        ),
      ),
      // Pass data payload as JSON string so we can route on tap
      payload: jsonEncode(message.data),
    );
  }

  /// Called when user taps notification while app was in background/terminated.
  void _handleNotificationTap(RemoteMessage message) {
    final route = _extractRoute(message.data);
    if (route != null && onNotificationTap != null) {
      onNotificationTap!(route);
    }
  }

  /// Called when user taps a local notification (foreground case).
  void _onLocalNotificationTap(NotificationResponse response) {
    if (response.payload == null) return;

    try {
      final data = jsonDecode(response.payload!) as Map<String, dynamic>;
      final route = _extractRoute(data);
      if (route != null && onNotificationTap != null) {
        onNotificationTap!(route);
      }
    } catch (_) {}
  }

  /// Extracts a deep-link route from the notification data payload.
  ///
  /// Expected data fields from server:
  ///   type: 'message' | 'attendance' | 'event' | 'belt_promotion'
  ///   id: optional entity ID
  String? _extractRoute(Map<String, dynamic> data) {
    final type = data['type'] as String?;

    switch (type) {
      case 'message':
        return '/student/messages';
      case 'attendance':
        return '/student/attendance';
      case 'event':
        return '/student/events';
      case 'belt_promotion':
        return '/student/belts';
      case 'admin_message':
        return '/admin/messages';
      case 'admin_attendance':
        return '/admin/attendance';
      default:
        return null;
    }
  }
}
