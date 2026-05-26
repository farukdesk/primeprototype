import 'package:flutter/foundation.dart';
import 'package:device_info_plus/device_info_plus.dart';

import 'api_service.dart';
import 'storage_service.dart';
import 'fcm_service.dart';
import '../models/user_model.dart';

/// Manages authentication state for the entire app.
/// Exposes [isLoggedIn], [currentUser], and login/logout actions.
class AuthService extends ChangeNotifier {
  bool _isInitialized = false;
  bool _isLoading = false;
  UserModel? _currentUser;
  String? _error;

  bool get isInitialized => _isInitialized;
  bool get isLoading     => _isLoading;
  bool get isLoggedIn    => _currentUser != null;
  UserModel? get currentUser => _currentUser;
  String? get error      => _error;

  void _clearError() {
    _error = null;
  }

  // ── Boot: attempt silent auto-login ──────────────────────────────────────

  Future<void> initialize() async {
    _isLoading = true;
    notifyListeners();

    try {
      final token = await StorageService.getToken();
      if (token != null) {
        final data = await ApiService.me(onUnauthorized: _onUnauthorized);
        if (data['ok'] == true) {
          _currentUser = UserModel.fromJson(data);
        } else {
          await StorageService.clearToken();
        }
      }
    } catch (e) {
      // Network error during auto-login – keep user logged in with cached state
      // if they had a valid (non-expired) token.
      debugPrint('AuthService.initialize error: $e');
    } finally {
      _isInitialized = true;
      _isLoading = false;
      notifyListeners();
    }
  }

  // ── Login ─────────────────────────────────────────────────────────────────

  Future<bool> login(String loginInput, String password) async {
    _clearError();
    _isLoading = true;
    notifyListeners();

    try {
      final deviceId   = await _getOrCreateDeviceId();
      final deviceName = await _getDeviceName();

      final data = await ApiService.login(
        login: loginInput,
        password: password,
        deviceId: deviceId,
        deviceName: deviceName,
        onUnauthorized: _onUnauthorized,
      );

      if (data['ok'] != true) {
        _error = data['error']?.toString() ?? 'Login failed.';
        return false;
      }

      final token     = data['token']      as String;
      final expiresAt = data['expires_at'] as String;

      await StorageService.saveToken(token, expiresAt);

      _currentUser = UserModel.fromJson(data);

      // Register FCM token in the background
      FcmService.registerToken(
        deviceId: deviceId,
        onUnauthorized: _onUnauthorized,
      );

      return true;
    } catch (e) {
      _error = ApiService.friendlyError(e);
      return false;
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  // ── Logout ────────────────────────────────────────────────────────────────

  Future<void> logout() async {
    await ApiService.logout(onUnauthorized: _onUnauthorized);
    await StorageService.clearToken();
    _currentUser = null;
    notifyListeners();
  }

  // ── Refresh me ───────────────────────────────────────────────────────────

  Future<void> refreshUser() async {
    try {
      final data = await ApiService.me(onUnauthorized: _onUnauthorized);
      if (data['ok'] == true) {
        _currentUser = UserModel.fromJson(data);
        notifyListeners();
      }
    } catch (_) {
      // Silently fail; stale data is fine for background refresh
    }
  }

  // ── Helpers ───────────────────────────────────────────────────────────────

  void _onUnauthorized() async {
    await StorageService.clearToken();
    _currentUser = null;
    notifyListeners();
  }

  Future<String> _getOrCreateDeviceId() async {
    var id = await StorageService.getDeviceId();
    if (id == null) {
      final info = DeviceInfoPlugin();
      final android = await info.androidInfo;
      id = android.id;
      await StorageService.saveDeviceId(id);
    }
    return id;
  }

  Future<String> _getDeviceName() async {
    try {
      final info    = DeviceInfoPlugin();
      final android = await info.androidInfo;
      return '${android.manufacturer} ${android.model}'.trim();
    } catch (_) {
      return 'Android Device';
    }
  }
}
