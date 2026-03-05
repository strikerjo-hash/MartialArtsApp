import 'dart:convert';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Secure token and user data storage using encrypted storage.
class SecureStorage {
  static const _accessTokenKey = 'access_token';
  static const _refreshTokenKey = 'refresh_token';
  static const _userDataKey = 'user_data';
  static const _userTypeKey = 'user_type';
  static const _schoolIdKey = 'school_id';

  final FlutterSecureStorage _storage;

  SecureStorage({FlutterSecureStorage? storage})
      : _storage = storage ??
            const FlutterSecureStorage(
              aOptions: AndroidOptions(encryptedSharedPreferences: true),
            );

  // --- Access Token ---
  Future<String?> getAccessToken() => _storage.read(key: _accessTokenKey);

  Future<void> setAccessToken(String token) =>
      _storage.write(key: _accessTokenKey, value: token);

  // --- Refresh Token ---
  Future<String?> getRefreshToken() => _storage.read(key: _refreshTokenKey);

  Future<void> setRefreshToken(String token) =>
      _storage.write(key: _refreshTokenKey, value: token);

  // --- User Type (student, admin) ---
  Future<String?> getUserType() => _storage.read(key: _userTypeKey);

  Future<void> setUserType(String type) =>
      _storage.write(key: _userTypeKey, value: type);

  // --- School ID ---
  Future<int> getSchoolId() async {
    final value = await _storage.read(key: _schoolIdKey);
    return value != null ? int.tryParse(value) ?? 1 : 1;
  }

  Future<void> setSchoolId(int id) =>
      _storage.write(key: _schoolIdKey, value: id.toString());

  // --- User Data (JSON) ---
  Future<Map<String, dynamic>?> getUserData() async {
    final json = await _storage.read(key: _userDataKey);
    if (json == null) return null;
    try {
      return jsonDecode(json) as Map<String, dynamic>;
    } catch (_) {
      return null;
    }
  }

  Future<void> setUserData(Map<String, dynamic> data) =>
      _storage.write(key: _userDataKey, value: jsonEncode(data));

  // --- Store all tokens at once ---
  Future<void> saveAuthTokens({
    required String accessToken,
    required String refreshToken,
    required Map<String, dynamic> user,
    required String userType,
  }) async {
    await Future.wait([
      setAccessToken(accessToken),
      setRefreshToken(refreshToken),
      setUserData(user),
      setUserType(userType),
      setSchoolId(user['school_id'] as int? ?? 1),
    ]);
  }

  // --- Clear all stored data (logout) ---
  Future<void> clearAll() => _storage.deleteAll();

  // --- Check if user is logged in ---
  Future<bool> hasTokens() async {
    final token = await getAccessToken();
    return token != null && token.isNotEmpty;
  }
}
