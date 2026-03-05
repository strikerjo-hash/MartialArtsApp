import 'package:dio/dio.dart';
import 'package:martial_arts_app/core/network/auth_interceptor.dart';
import 'package:martial_arts_app/core/storage/secure_storage.dart';

/// Configured Dio HTTP client for API communication.
class ApiClient {
  late final Dio dio;
  final SecureStorage _storage;

  // TODO: Update this to your server's URL
  static const String defaultBaseUrl = 'http://localhost/procomp/api';

  ApiClient({
    required SecureStorage storage,
    String? baseUrl,
  }) : _storage = storage {
    dio = Dio(
      BaseOptions(
        baseUrl: baseUrl ?? defaultBaseUrl,
        connectTimeout: const Duration(seconds: 15),
        receiveTimeout: const Duration(seconds: 15),
        sendTimeout: const Duration(seconds: 15),
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
      ),
    );

    // Add auth interceptor for automatic token attachment and refresh
    dio.interceptors.add(AuthInterceptor(dio: dio, storage: _storage));

    // Add logging in debug mode
    dio.interceptors.add(LogInterceptor(
      requestBody: true,
      responseBody: true,
      error: true,
      logPrint: (o) => print('[API] $o'),
    ));
  }

  // --- GET ---
  Future<Response> get(
    String path, {
    Map<String, dynamic>? queryParameters,
  }) =>
      dio.get(path, queryParameters: queryParameters);

  // --- POST ---
  Future<Response> post(
    String path, {
    dynamic data,
  }) =>
      dio.post(path, data: data);

  // --- PUT ---
  Future<Response> put(
    String path, {
    dynamic data,
  }) =>
      dio.put(path, data: data);

  // --- DELETE ---
  Future<Response> delete(
    String path, {
    dynamic data,
  }) =>
      dio.delete(path, data: data);
}
