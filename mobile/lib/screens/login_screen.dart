import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:go_router/go_router.dart';
import 'package:local_auth/local_auth.dart';
import 'package:provider/provider.dart';

import '../services/auth_service.dart';
import '../services/connectivity_service.dart';
import '../services/storage_service.dart';

/// Admin login screen.
/// Supports:
///  - Username/email + password login
///  - Biometric (fingerprint/face) quick-login when a valid token exists
///  - Offline banner when there is no internet connection
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey         = GlobalKey<FormState>();
  final _loginController = TextEditingController();
  final _passController  = TextEditingController();

  bool _obscurePassword  = true;
  bool _biometricPending = false;

  final _localAuth = LocalAuthentication();

  @override
  void initState() {
    super.initState();
    _tryBiometricAutoLogin();
  }

  @override
  void dispose() {
    _loginController.dispose();
    _passController.dispose();
    super.dispose();
  }

  // ── Biometric auto-login ─────────────────────────────────────────────────

  Future<void> _tryBiometricAutoLogin() async {
    // Only attempt if we already have a stored (non-expired) token
    final hasToken = await StorageService.getToken() != null;
    if (!hasToken) return;

    try {
      final canCheck   = await _localAuth.canCheckBiometrics;
      final isSupported = await _localAuth.isDeviceSupported();
      if (!canCheck || !isSupported) return;

      final available = await _localAuth.getAvailableBiometrics();
      if (available.isEmpty) return;

      setState(() => _biometricPending = true);
      final authenticated = await _localAuth.authenticate(
        localizedReason: 'Verify your identity to access PUMIS',
        options: const AuthenticationOptions(
          biometricOnly: false,
          stickyAuth: true,
        ),
      );
      setState(() => _biometricPending = false);

      if (authenticated && mounted) {
        // Token is still valid — refresh user info and proceed
        await context.read<AuthService>().refreshUser();
        if (mounted && context.read<AuthService>().isLoggedIn) {
          context.go('/dashboard');
        }
      }
    } on PlatformException catch (e) {
      setState(() => _biometricPending = false);
      debugPrint('Biometric error: $e');
    }
  }

  // ── Password login ────────────────────────────────────────────────────────

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    final auth = context.read<AuthService>();
    final ok   = await auth.login(
      _loginController.text.trim(),
      _passController.text,
    );

    if (!mounted) return;
    if (ok) {
      context.go('/dashboard');
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth        = context.watch<AuthService>();
    final connectivity = context.watch<ConnectivityService>();

    return Scaffold(
      backgroundColor: const Color(0xFF1A1F36),
      body: Column(
        children: [
          // ── Offline banner ───────────────────────────────────────────────
          if (!connectivity.isOnline)
            _OfflineBanner(),

          Expanded(
            child: SafeArea(
              child: Center(
                child: SingleChildScrollView(
                  padding: const EdgeInsets.symmetric(horizontal: 24),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      // ── Logo block ───────────────────────────────────────
                      _buildLogo(),
                      const SizedBox(height: 36),

                      // ── Biometric indicator ──────────────────────────────
                      if (_biometricPending)
                        _BiometricIndicator(),

                      // ── Card ─────────────────────────────────────────────
                      _buildCard(auth, connectivity),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildLogo() {
    return Column(
      children: [
        Container(
          width: 80,
          height: 80,
          decoration: BoxDecoration(
            color: Colors.white.withOpacity(0.1),
            borderRadius: BorderRadius.circular(20),
          ),
          child: const Icon(Icons.school_rounded, size: 44, color: Colors.white),
        ),
        const SizedBox(height: 16),
        const Text(
          'PUMIS',
          style: TextStyle(
            color: Colors.white,
            fontSize: 28,
            fontWeight: FontWeight.w700,
            letterSpacing: 2,
          ),
        ),
        const SizedBox(height: 4),
        Text(
          'Prime University Management System',
          style: TextStyle(
            color: Colors.white.withOpacity(0.55),
            fontSize: 12,
          ),
          textAlign: TextAlign.center,
        ),
      ],
    );
  }

  Widget _buildCard(AuthService auth, ConnectivityService connectivity) {
    return Container(
      padding: const EdgeInsets.all(28),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(24),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(0.25),
            blurRadius: 40,
            offset: const Offset(0, 16),
          ),
        ],
      ),
      child: Form(
        key: _formKey,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Text(
              'Sign In',
              style: TextStyle(
                fontSize: 20,
                fontWeight: FontWeight.w700,
                color: Color(0xFF1A1F36),
              ),
            ),
            const SizedBox(height: 4),
            const Text(
              'Enter your admin credentials',
              style: TextStyle(fontSize: 13, color: Color(0xFF9CA3AF)),
            ),

            // ── Error message ────────────────────────────────────────────
            if (auth.error != null) ...[
              const SizedBox(height: 16),
              _ErrorBanner(message: auth.error!),
            ],

            const SizedBox(height: 20),

            // ── Login field ──────────────────────────────────────────────
            TextFormField(
              controller: _loginController,
              keyboardType: TextInputType.emailAddress,
              textInputAction: TextInputAction.next,
              enabled: !auth.isLoading,
              decoration: const InputDecoration(
                labelText: 'Username or Email',
                prefixIcon: Icon(Icons.person_outline_rounded),
              ),
              validator: (v) =>
                  (v == null || v.trim().isEmpty) ? 'Please enter your username or email.' : null,
            ),

            const SizedBox(height: 14),

            // ── Password field ───────────────────────────────────────────
            TextFormField(
              controller: _passController,
              obscureText: _obscurePassword,
              textInputAction: TextInputAction.done,
              enabled: !auth.isLoading,
              onFieldSubmitted: (_) => _submit(),
              decoration: InputDecoration(
                labelText: 'Password',
                prefixIcon: const Icon(Icons.lock_outline_rounded),
                suffixIcon: IconButton(
                  icon: Icon(
                    _obscurePassword
                        ? Icons.visibility_off_outlined
                        : Icons.visibility_outlined,
                  ),
                  onPressed: () =>
                      setState(() => _obscurePassword = !_obscurePassword),
                ),
              ),
              validator: (v) =>
                  (v == null || v.isEmpty) ? 'Please enter your password.' : null,
            ),

            const SizedBox(height: 24),

            // ── Sign in button ───────────────────────────────────────────
            ElevatedButton.icon(
              onPressed: (!auth.isLoading && connectivity.isOnline)
                  ? _submit
                  : null,
              icon: auth.isLoading
                  ? const SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(
                        strokeWidth: 2,
                        color: Colors.white,
                      ),
                    )
                  : const Icon(Icons.login_rounded),
              label: Text(auth.isLoading ? 'Signing in…' : 'Sign In'),
            ),

            // ── Biometric button (only when token exists) ────────────────
            if (!_biometricPending) ...[
              const SizedBox(height: 12),
              OutlinedButton.icon(
                onPressed: _tryBiometricAutoLogin,
                icon: const Icon(Icons.fingerprint_rounded),
                label: const Text('Use Biometrics'),
                style: OutlinedButton.styleFrom(
                  minimumSize: const Size.fromHeight(52),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(12),
                  ),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

// ── Helper widgets ────────────────────────────────────────────────────────────

class _OfflineBanner extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return Container(
      color: const Color(0xFFFF6B6B),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
      child: Row(
        children: [
          const Icon(Icons.wifi_off_rounded, color: Colors.white, size: 18),
          const SizedBox(width: 10),
          const Expanded(
            child: Text(
              'You are offline. Some features may be unavailable.',
              style: TextStyle(color: Colors.white, fontSize: 13),
            ),
          ),
        ],
      ),
    );
  }
}

class _ErrorBanner extends StatelessWidget {
  final String message;
  const _ErrorBanner({required this.message});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      decoration: BoxDecoration(
        color: const Color(0xFFFEE2E2),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: const Color(0xFFFCA5A5)),
      ),
      child: Row(
        children: [
          const Icon(Icons.error_outline_rounded, color: Color(0xFFDC2626), size: 18),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              message,
              style: const TextStyle(color: Color(0xFFDC2626), fontSize: 13),
            ),
          ),
        ],
      ),
    );
  }
}

class _BiometricIndicator extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 20),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 14),
        decoration: BoxDecoration(
          color: Colors.white.withOpacity(0.1),
          borderRadius: BorderRadius.circular(14),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            const SizedBox(
              width: 22,
              height: 22,
              child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
            ),
            const SizedBox(width: 12),
            const Text(
              'Waiting for biometric…',
              style: TextStyle(color: Colors.white, fontSize: 13),
            ),
          ],
        ),
      ),
    );
  }
}
