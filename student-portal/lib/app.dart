import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import 'screens/splash_screen.dart';
import 'screens/login_screen.dart';
import 'screens/home_screen.dart';
import 'screens/notice_detail_screen.dart';
import 'screens/settings_screen.dart';
import 'services/auth_service.dart';
import 'theme/app_theme.dart';

class StudentPortalApp extends StatelessWidget {
  const StudentPortalApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp.router(
      title: 'PU Student Portal',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.lightTheme,
      routerConfig: _router(context),
    );
  }
}

GoRouter _router(BuildContext context) {
  return GoRouter(
    initialLocation: '/splash',
    redirect: (ctx, state) {
      final auth = ctx.read<AuthService>();
      if (state.matchedLocation == '/splash') return null;
      if (!auth.isLoggedIn && state.matchedLocation != '/login') return '/login';
      if (auth.isLoggedIn && state.matchedLocation == '/login') return '/home';
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
        path: '/home',
        builder: (_, __) => const HomeScreen(),
      ),
      GoRoute(
        path: '/notice-detail',
        builder: (_, state) {
          final extra = state.extra as Map<String, dynamic>;
          return NoticeDetailScreen(notice: extra);
        },
      ),
      GoRoute(
        path: '/settings',
        builder: (_, __) => const SettingsScreen(),
      ),
    ],
  );
}
