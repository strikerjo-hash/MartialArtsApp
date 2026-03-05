import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';
import 'package:martial_arts_app/app.dart';
import 'package:martial_arts_app/core/network/api_client.dart';
import 'package:martial_arts_app/core/storage/secure_storage.dart';
import 'package:martial_arts_app/features/notifications/push_notification_service.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Initialize Firebase
  await Firebase.initializeApp();

  // Register background message handler (must be top-level function)
  FirebaseMessaging.onBackgroundMessage(firebaseMessagingBackgroundHandler);

  // Initialize core services
  final storage = SecureStorage();
  final apiClient = ApiClient(storage: storage);

  // Initialize push notification service
  final pushService = PushNotificationService(
    apiClient: apiClient,
    storage: storage,
  );

  runApp(MartialArtsApp(
    storage: storage,
    apiClient: apiClient,
    pushService: pushService,
  ));
}
