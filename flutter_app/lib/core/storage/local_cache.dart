import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Simple offline cache for API responses using SharedPreferences.
/// Each entry has a TTL (time-to-live) after which it is considered stale.
///
/// Usage:
///   final cache = LocalCache();
///   await cache.put('student_profile', responseData, ttl: Duration(minutes: 30));
///   final cached = await cache.get<Map<String, dynamic>>('student_profile');
class LocalCache {
  static const _prefix = 'cache_';
  static const _tsPrefix = 'cache_ts_';

  /// Store a JSON-serializable value with optional TTL.
  Future<void> put(
    String key,
    dynamic value, {
    Duration ttl = const Duration(minutes: 30),
  }) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final jsonStr = jsonEncode(value);
      await prefs.setString('$_prefix$key', jsonStr);
      await prefs.setInt(
        '$_tsPrefix$key',
        DateTime.now().add(ttl).millisecondsSinceEpoch,
      );
    } catch (e) {
      debugPrint('[Cache] Error storing $key: $e');
    }
  }

  /// Retrieve a cached value. Returns null if not found or expired.
  Future<T?> get<T>(String key) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final jsonStr = prefs.getString('$_prefix$key');
      final expiresAt = prefs.getInt('$_tsPrefix$key');

      if (jsonStr == null || expiresAt == null) return null;

      // Check expiry
      if (DateTime.now().millisecondsSinceEpoch > expiresAt) {
        // Expired — remove and return null
        await remove(key);
        return null;
      }

      return jsonDecode(jsonStr) as T;
    } catch (e) {
      debugPrint('[Cache] Error reading $key: $e');
      return null;
    }
  }

  /// Retrieve cached value even if expired (for offline fallback).
  Future<T?> getStale<T>(String key) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final jsonStr = prefs.getString('$_prefix$key');
      if (jsonStr == null) return null;
      return jsonDecode(jsonStr) as T;
    } catch (e) {
      debugPrint('[Cache] Error reading stale $key: $e');
      return null;
    }
  }

  /// Check if a key has a valid (non-expired) cache entry.
  Future<bool> has(String key) async {
    final prefs = await SharedPreferences.getInstance();
    final expiresAt = prefs.getInt('$_tsPrefix$key');
    if (expiresAt == null) return false;
    return DateTime.now().millisecondsSinceEpoch <= expiresAt;
  }

  /// Remove a specific cache entry.
  Future<void> remove(String key) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove('$_prefix$key');
    await prefs.remove('$_tsPrefix$key');
  }

  /// Clear all cache entries.
  Future<void> clearAll() async {
    final prefs = await SharedPreferences.getInstance();
    final keys = prefs.getKeys();
    for (final key in keys) {
      if (key.startsWith(_prefix) || key.startsWith(_tsPrefix)) {
        await prefs.remove(key);
      }
    }
  }
}
