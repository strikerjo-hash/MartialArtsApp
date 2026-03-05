import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:martial_arts_app/core/constants/api_endpoints.dart';
import 'package:martial_arts_app/core/network/api_client.dart';
import 'package:martial_arts_app/core/theme/app_theme.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Fetches school branding from /api/theme and builds a dynamic ThemeData.
/// Caches the last-fetched branding in SharedPreferences so the app
/// can show the correct colors even when offline.
class DynamicThemeProvider extends ChangeNotifier {
  static const _cacheKey = 'cached_school_branding';

  ThemeData _theme = AppTheme.defaultTheme;
  String _schoolName = 'Martial Arts School';
  String _schoolTagline = '';
  String? _logoUrl;
  bool _isLoaded = false;

  ThemeData get theme => _theme;
  String get schoolName => _schoolName;
  String get schoolTagline => _schoolTagline;
  String? get logoUrl => _logoUrl;
  bool get isLoaded => _isLoaded;

  /// Load cached branding first (instant), then fetch fresh from server.
  Future<void> initialize(ApiClient apiClient, {int schoolId = 1}) async {
    // 1. Load from cache immediately
    await _loadFromCache();

    // 2. Fetch fresh from server (non-blocking, will update UI when done)
    try {
      final response = await apiClient.get(
        ApiEndpoints.theme,
        queryParameters: {'school_id': schoolId},
      );

      final data = response.data as Map<String, dynamic>;
      _applyBranding(data);
      await _saveToCache(data);
    } catch (e) {
      debugPrint('[Theme] Could not fetch branding: $e');
      // Already using cached or default theme — that's fine
    }

    _isLoaded = true;
    notifyListeners();
  }

  void _applyBranding(Map<String, dynamic> data) {
    _schoolName = data['school_name'] as String? ?? _schoolName;
    _schoolTagline = data['school_tagline'] as String? ?? _schoolTagline;
    _logoUrl = data['logo_url'] as String?;

    _theme = AppTheme.fromBranding(
      primaryHex: data['primary_color'] as String?,
      secondaryHex: data['secondary_color'] as String?,
      accentHex: data['accent_color'] as String?,
    );

    notifyListeners();
  }

  Future<void> _loadFromCache() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final cached = prefs.getString(_cacheKey);
      if (cached != null) {
        final data = jsonDecode(cached) as Map<String, dynamic>;
        _applyBranding(data);
      }
    } catch (_) {}
  }

  Future<void> _saveToCache(Map<String, dynamic> data) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString(_cacheKey, jsonEncode(data));
    } catch (_) {}
  }
}
