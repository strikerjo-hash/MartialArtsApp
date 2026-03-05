import 'dart:async';
import 'package:dio/dio.dart';
import 'package:martial_arts_app/core/constants/api_endpoints.dart';
import 'package:martial_arts_app/core/storage/secure_storage.dart';

/// Dio interceptor that:
/// 1. Attaches Bearer token to all authenticated requests
/// 2. Intercepts 401 responses and attempts token refresh
/// 3. Retries the original request with the new token
/// 4. Handles concurrent 401s by queuing requests during refresh
class AuthInterceptor extends QueuedInterceptor {
  final Dio _dio;
  final SecureStorage _storage;
  bool _isRefreshing = false;

  AuthInterceptor({
    required Dio dio,
    required SecureStorage storage,
  })  : _dio = dio,
        _storage = storage;

  @override
  Future<void> onRequest(
    RequestOptions options,
    RequestInterceptorHandler handler,
  ) async {
    // Skip auth header for public endpoints
    final publicPaths = [
      ApiEndpoints.login,
      ApiEndpoints.adminLogin,
      ApiEndpoints.refresh,
      ApiEndpoints.theme,
      ApiEndpoints.health,
    ];

    final isPublic = publicPaths.any((p) => options.path.contains(p));

    if (!isPublic) {
      final token = await _storage.getAccessToken();
      if (token != null && token.isNotEmpty) {
        options.headers['Authorization'] = 'Bearer $token';
      }
    }

    handler.next(options);
  }

  @override
  Future<void> onError(
    DioException err,
    ErrorInterceptorHandler handler,
  ) async {
    // Only handle 401 Unauthorized
    if (err.response?.statusCode != 401) {
      return handler.next(err);
    }

    // Don't try to refresh if the failing request IS the refresh endpoint
    if (err.requestOptions.path.contains(ApiEndpoints.refresh)) {
      // Refresh token itself is invalid — force logout
      await _storage.clearAll();
      return handler.next(err);
    }

    // Attempt token refresh
    if (!_isRefreshing) {
      _isRefreshing = true;

      try {
        final refreshToken = await _storage.getRefreshToken();
        if (refreshToken == null || refreshToken.isEmpty) {
          await _storage.clearAll();
          return handler.next(err);
        }

        // Use a separate Dio instance to avoid interceptor loops
        final refreshDio = Dio(BaseOptions(
          baseUrl: _dio.options.baseUrl,
          headers: {'Content-Type': 'application/json'},
        ));

        final response = await refreshDio.post(
          ApiEndpoints.refresh,
          data: {'refresh_token': refreshToken},
        );

        if (response.statusCode == 200) {
          final data = response.data;
          final newAccessToken = data['access_token'] as String;
          final newRefreshToken = data['refresh_token'] as String;

          // Store new tokens
          await _storage.setAccessToken(newAccessToken);
          await _storage.setRefreshToken(newRefreshToken);

          _isRefreshing = false;

          // Retry the original request with new token
          final options = err.requestOptions;
          options.headers['Authorization'] = 'Bearer $newAccessToken';

          final retryResponse = await _dio.fetch(options);
          return handler.resolve(retryResponse);
        }
      } catch (_) {
        // Refresh failed — clear tokens
        await _storage.clearAll();
      } finally {
        _isRefreshing = false;
      }
    }

    handler.next(err);
  }
}
