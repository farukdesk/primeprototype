import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:flutter_native_splash/flutter_native_splash.dart';

import 'screens/splash_screen.dart';
import 'screens/login_screen.dart';
import 'screens/dashboard_screen.dart';
import 'services/auth_service.dart';

class PumisApp extends StatefulWidget {
  const PumisApp({super.key});

  @override
  State<PumisApp> createState() => _PumisAppState();
}

class _PumisAppState extends State<PumisApp> {
  late final GoRouter _router;

  @override
  void initState() {
    super.initState();
    _router = GoRouter(
      initialLocation: '/splash',
      refreshListenable: context.read<AuthService>(),
      redirect: (context, state) {
        final auth = context.read<AuthService>();
        final loggingIn = state.matchedLocation == '/login';
        final onSplash = state.matchedLocation == '/splash';

        if (onSplash) return null; // Always allow splash
        if (!auth.isInitialized) return '/splash';
        if (!auth.isLoggedIn && !loggingIn) return '/login';
        if (auth.isLoggedIn && loggingIn) return '/dashboard';
        return null;
      },
      routes: [
        GoRoute(
          path: '/splash',
          builder: (_, __) => const SplashScreen(),
        ),
        GoRoute(
          path: '/login',
          builder: (_, __) => const LoginScreen(),
        ),
        GoRoute(
          path: '/dashboard',
          builder: (_, __) => const DashboardScreen(),
        ),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp.router(
      title: 'PUMIS Admin',
      debugShowCheckedModeBanner: false,
      theme: _buildTheme(),
      routerConfig: _router,
    );
  }

  ThemeData _buildTheme() {
    const seedColor = Color(0xFF4F8EF7);
    return ThemeData(
      useMaterial3: true,
      colorScheme: ColorScheme.fromSeed(
        seedColor: seedColor,
        brightness: Brightness.light,
      ),
      fontFamily: 'Inter',
      appBarTheme: const AppBarTheme(
        backgroundColor: Color(0xFF1A1F36),
        foregroundColor: Colors.white,
        elevation: 0,
        centerTitle: true,
        titleTextStyle: TextStyle(
          fontFamily: 'Inter',
          fontSize: 18,
          fontWeight: FontWeight.w600,
          color: Colors.white,
        ),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: seedColor,
          foregroundColor: Colors.white,
          minimumSize: const Size.fromHeight(52),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
          ),
          textStyle: const TextStyle(
            fontFamily: 'Inter',
            fontWeight: FontWeight.w600,
            fontSize: 15,
          ),
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: Color(0xFFE5E7EB), width: 1.5),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: seedColor, width: 2),
        ),
        filled: true,
        fillColor: Colors.white,
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      ),
    );
  }
}
