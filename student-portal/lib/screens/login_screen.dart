import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';

import '../services/auth_service.dart';
import '../services/connectivity_service.dart';
import '../theme/app_theme.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});
  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey   = GlobalKey<FormState>();
  final _loginCtrl = TextEditingController();
  final _passCtrl  = TextEditingController();
  bool _obscure    = true;
  String? _error;

  @override
  void dispose() {
    _loginCtrl.dispose();
    _passCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _error = null);

    final err = await context.read<AuthService>().login(
      login:    _loginCtrl.text.trim(),
      password: _passCtrl.text,
    );

    if (!mounted) return;
    if (err != null) {
      setState(() => _error = err);
    } else {
      context.go('/home');
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth    = context.watch<AuthService>();
    final online  = context.watch<ConnectivityService>().isOnline;
    final loading = auth.isLoading;
    final size    = MediaQuery.of(context).size;

    return Scaffold(
      backgroundColor: AppTheme.background,
      body: SingleChildScrollView(
        child: SizedBox(
          height: size.height,
          child: Column(
            children: [
              // ── Header gradient ───────────────────────────────────────────
              Container(
                width: double.infinity,
                padding: EdgeInsets.only(
                  top: MediaQuery.of(context).padding.top + 48,
                  bottom: 48,
                  left: 32,
                  right: 32,
                ),
                decoration: const BoxDecoration(
                  gradient: LinearGradient(
                    colors: [AppTheme.primaryDark, AppTheme.primary],
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                  ),
                  borderRadius: BorderRadius.vertical(bottom: Radius.circular(36)),
                ),
                child: Column(
                  children: [
                    // Logo
                    Container(
                      width: 84,
                      height: 84,
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(20),
                        boxShadow: [
                          BoxShadow(
                            color: Colors.black.withOpacity(0.2),
                            blurRadius: 16,
                            offset: const Offset(0, 6),
                          ),
                        ],
                      ),
                      child: const Icon(
                        Icons.school_rounded,
                        size: 48,
                        color: AppTheme.primary,
                      ),
                    ),
                    const SizedBox(height: 20),
                    const Text(
                      'Prime University',
                      style: TextStyle(
                        color: Colors.white,
                        fontSize: 22,
                        fontWeight: FontWeight.w700,
                        letterSpacing: 0.3,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 12, vertical: 4),
                      decoration: BoxDecoration(
                        color: AppTheme.accent.withOpacity(0.2),
                        borderRadius: BorderRadius.circular(20),
                        border: Border.all(color: AppTheme.accent, width: 1),
                      ),
                      child: const Text(
                        'STUDENT PORTAL',
                        style: TextStyle(
                          color: AppTheme.accent,
                          fontSize: 11,
                          fontWeight: FontWeight.w700,
                          letterSpacing: 2.5,
                        ),
                      ),
                    ),
                  ],
                ),
              ),

              // ── Form card ─────────────────────────────────────────────────
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.all(24),
                  child: Form(
                    key: _formKey,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        const SizedBox(height: 12),
                        Text(
                          'Sign in to your account',
                          style: TextStyle(
                            fontSize: 18,
                            fontWeight: FontWeight.w700,
                            color: AppTheme.textPrimary,
                          ),
                        ),
                        const SizedBox(height: 6),
                        Text(
                          'Use your student portal credentials.',
                          style: TextStyle(
                            fontSize: 13,
                            color: AppTheme.textSecondary,
                          ),
                        ),

                        // Offline banner
                        if (!online) ...[
                          const SizedBox(height: 16),
                          Container(
                            padding: const EdgeInsets.symmetric(
                                horizontal: 14, vertical: 10),
                            decoration: BoxDecoration(
                              color: AppTheme.error.withOpacity(0.1),
                              borderRadius: BorderRadius.circular(10),
                              border: Border.all(
                                  color: AppTheme.error.withOpacity(0.3)),
                            ),
                            child: Row(
                              children: [
                                Icon(Icons.wifi_off_rounded,
                                    color: AppTheme.error, size: 16),
                                const SizedBox(width: 8),
                                Expanded(
                                  child: Text(
                                    'No internet connection.',
                                    style: TextStyle(
                                        color: AppTheme.error, fontSize: 13),
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ],

                        // Error message
                        if (_error != null) ...[
                          const SizedBox(height: 16),
                          Container(
                            padding: const EdgeInsets.symmetric(
                                horizontal: 14, vertical: 10),
                            decoration: BoxDecoration(
                              color: AppTheme.error.withOpacity(0.08),
                              borderRadius: BorderRadius.circular(10),
                              border: Border.all(
                                  color: AppTheme.error.withOpacity(0.3)),
                            ),
                            child: Row(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Icon(Icons.error_outline_rounded,
                                    color: AppTheme.error, size: 16),
                                const SizedBox(width: 8),
                                Expanded(
                                  child: Text(
                                    _error!,
                                    style: TextStyle(
                                        color: AppTheme.error, fontSize: 13),
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ],

                        const SizedBox(height: 24),

                        // Username field
                        TextFormField(
                          controller: _loginCtrl,
                          keyboardType: TextInputType.text,
                          textInputAction: TextInputAction.next,
                          autocorrect: false,
                          decoration: const InputDecoration(
                            labelText: 'Student ID or Username',
                            prefixIcon: Icon(Icons.person_outline_rounded),
                          ),
                          validator: (v) =>
                              (v == null || v.trim().isEmpty) ? 'Required' : null,
                        ),
                        const SizedBox(height: 16),

                        // Password field
                        TextFormField(
                          controller: _passCtrl,
                          obscureText: _obscure,
                          textInputAction: TextInputAction.done,
                          onFieldSubmitted: (_) => _submit(),
                          decoration: InputDecoration(
                            labelText: 'Password',
                            prefixIcon:
                                const Icon(Icons.lock_outline_rounded),
                            suffixIcon: IconButton(
                              icon: Icon(
                                _obscure
                                    ? Icons.visibility_outlined
                                    : Icons.visibility_off_outlined,
                              ),
                              onPressed: () =>
                                  setState(() => _obscure = !_obscure),
                            ),
                          ),
                          validator: (v) =>
                              (v == null || v.isEmpty) ? 'Required' : null,
                        ),

                        const SizedBox(height: 28),

                        // Login button
                        SizedBox(
                          height: 50,
                          child: ElevatedButton(
                            onPressed: (loading || !online) ? null : _submit,
                            child: loading
                                ? const SizedBox(
                                    width: 20,
                                    height: 20,
                                    child: CircularProgressIndicator(
                                      color: Colors.white,
                                      strokeWidth: 2.5,
                                    ),
                                  )
                                : const Text('Sign In'),
                          ),
                        ),

                        const Spacer(),

                        // Footer
                        Center(
                          child: Text(
                            '© ${DateTime.now().year} Prime University\nAll rights reserved.',
                            textAlign: TextAlign.center,
                            style: TextStyle(
                              color: AppTheme.textSecondary,
                              fontSize: 11,
                              height: 1.6,
                            ),
                          ),
                        ),
                        const SizedBox(height: 16),
                      ],
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
