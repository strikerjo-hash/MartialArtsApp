import 'package:flutter/material.dart';

/// Builds a ThemeData from server-fetched school branding colors.
class AppTheme {
  // Default colors (Ocean Blue preset)
  static const Color defaultPrimary = Color(0xFF3B82F6);
  static const Color defaultSecondary = Color(0xFF1E293B);
  static const Color defaultAccent = Color(0xFFF59E0B);

  /// Build ThemeData from hex color strings fetched from /api/theme
  static ThemeData fromBranding({
    String? primaryHex,
    String? secondaryHex,
    String? accentHex,
  }) {
    final primary = _parseColor(primaryHex) ?? defaultPrimary;
    final secondary = _parseColor(secondaryHex) ?? defaultSecondary;
    final accent = _parseColor(accentHex) ?? defaultAccent;

    final colorScheme = ColorScheme.fromSeed(
      seedColor: primary,
      primary: primary,
      secondary: secondary,
      tertiary: accent,
      brightness: Brightness.light,
    );

    return ThemeData(
      useMaterial3: true,
      colorScheme: colorScheme,
      scaffoldBackgroundColor: Colors.grey[50],
      appBarTheme: AppBarTheme(
        backgroundColor: primary,
        foregroundColor: Colors.white,
        elevation: 0,
        centerTitle: false,
      ),
      navigationDrawerTheme: NavigationDrawerThemeData(
        backgroundColor: secondary,
      ),
      cardTheme: CardThemeData(
        elevation: 1,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: primary,
          foregroundColor: Colors.white,
          padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 14),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      ),
      floatingActionButtonTheme: FloatingActionButtonThemeData(
        backgroundColor: accent,
        foregroundColor: Colors.white,
      ),
    );
  }

  /// Default theme (before branding is fetched)
  static ThemeData get defaultTheme => fromBranding();

  static Color? _parseColor(String? hex) {
    if (hex == null || hex.isEmpty) return null;
    hex = hex.replaceFirst('#', '');
    if (hex.length == 6) hex = 'FF$hex';
    final value = int.tryParse(hex, radix: 16);
    return value != null ? Color(value) : null;
  }
}
