import 'package:flutter/material.dart';

class AppTheme {
  // ── Brand colours ──────────────────────────────────────────────────────────
  static const Color primary     = Color(0xFF1B3A6B); // Deep navy
  static const Color primaryDark = Color(0xFF0F2347);
  static const Color accent      = Color(0xFFC9A227); // Gold
  static const Color success     = Color(0xFF10B981);
  static const Color error       = Color(0xFFEF4444);
  static const Color warning     = Color(0xFFF59E0B);
  static const Color info        = Color(0xFF3B82F6);

  // ── Neutral colours ────────────────────────────────────────────────────────
  static const Color background  = Color(0xFFF0F2F5);
  static const Color surface     = Colors.white;
  static const Color textPrimary = Color(0xFF1A1F36);
  static const Color textSecondary = Color(0xFF6B7280);
  static const Color divider     = Color(0xFFE5E7EB);

  static ThemeData get lightTheme {
    const colorScheme = ColorScheme(
      brightness: Brightness.light,
      primary:    primary,
      onPrimary:  Colors.white,
      secondary:  accent,
      onSecondary: Colors.white,
      error:      error,
      onError:    Colors.white,
      surface:    surface,
      onSurface:  textPrimary,
    );

    return ThemeData(
      useMaterial3: true,
      colorScheme: colorScheme,
      scaffoldBackgroundColor: background,
      fontFamily: 'Roboto',

      appBarTheme: const AppBarTheme(
        backgroundColor: primary,
        foregroundColor: Colors.white,
        elevation: 0,
        centerTitle: false,
        titleTextStyle: TextStyle(
          fontSize: 18,
          fontWeight: FontWeight.w600,
          color: Colors.white,
          letterSpacing: 0.3,
        ),
      ),

      cardTheme: CardThemeData(
        color: surface,
        elevation: 0,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(16),
        ),
        margin: EdgeInsets.zero,
      ),

      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: primary,
          foregroundColor: Colors.white,
          elevation: 0,
          padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 14),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
          ),
          textStyle: const TextStyle(
            fontSize: 15,
            fontWeight: FontWeight.w600,
            letterSpacing: 0.3,
          ),
        ),
      ),

      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: const Color(0xFFF9FAFB),
        contentPadding: const EdgeInsets.symmetric(horizontal: 18, vertical: 16),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: Color(0xFFE5E7EB)),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: Color(0xFFE5E7EB)),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: primary, width: 2),
        ),
        errorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: error),
        ),
        labelStyle: const TextStyle(color: textSecondary),
        hintStyle: const TextStyle(color: Color(0xFFBEC3CC)),
      ),

      bottomNavigationBarTheme: const BottomNavigationBarThemeData(
        backgroundColor: Colors.white,
        selectedItemColor: primary,
        unselectedItemColor: Color(0xFF9CA3AF),
        type: BottomNavigationBarType.fixed,
        elevation: 12,
        selectedLabelStyle: TextStyle(fontSize: 11, fontWeight: FontWeight.w600),
        unselectedLabelStyle: TextStyle(fontSize: 11),
      ),

      chipTheme: ChipThemeData(
        backgroundColor: primary.withOpacity(0.08),
        labelStyle: const TextStyle(
          color: primary,
          fontSize: 12,
          fontWeight: FontWeight.w500,
        ),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      ),

      dividerTheme: const DividerThemeData(
        color: divider,
        thickness: 1,
        space: 1,
      ),
    );
  }
}
