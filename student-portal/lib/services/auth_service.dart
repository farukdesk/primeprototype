import 'package:flutter/foundation.dart';
import 'package:device_info_plus/device_info_plus.dart';

import '../models/student_model.dart';
import 'api_service.dart';
import 'storage_service.dart';

/// Manages authentication state for the Student Portal.
class AuthService extends ChangeNotifier {
  StudentModel? _student;
  bool          _loading = false;

  StudentModel? get currentStudent => _student;
  bool          get isLoggedIn     => _student != null;
  bool          get isLoading      => _loading;

  // ── Called on app start ───────────────────────────────────────────────────

  Future<void> tryAutoLogin() async {
    final token = await StorageService.getToken();
    if (token == null) return;
    try {
      _loading = true;
      notifyListeners();
      final data = await ApiService.me(onUnauthorized: _handleUnauthorized);
      if (data['ok'] == true) {
        _student = StudentModel.fromMeJson(data);
      } else {
        await StorageService.clearToken();
      }
    } catch (_) {
      // Keep the cached state; the user can proceed offline
    } finally {
      _loading = false;
      notifyListeners();
    }
  }

  // ── Login ─────────────────────────────────────────────────────────────────

  Future<String?> login({
    required String login,
    required String password,
  }) async {
    _loading = true;
    notifyListeners();

    try {
      final deviceInfo = await _getDeviceInfo();
      final data = await ApiService.login(
        login:       login,
        password:    password,
        deviceId:    deviceInfo.$1,
        deviceName:  deviceInfo.$2,
        onUnauthorized: _handleUnauthorized,
      );

      if (data['ok'] != true) {
        return (data['error'] as String?) ?? 'Login failed.';
      }

      await StorageService.saveToken(data['token'] as String);
      await StorageService.saveDeviceId(deviceInfo.$1);
      _student = StudentModel.fromLoginJson(data);
      notifyListeners();
      return null; // success
    } catch (e) {
      return ApiService.friendlyError(e);
    } finally {
      _loading = false;
      notifyListeners();
    }
  }

  // ── Logout ────────────────────────────────────────────────────────────────

  Future<void> logout() async {
    await ApiService.logout(onUnauthorized: _handleUnauthorized);
    await StorageService.clearToken();
    _student = null;
    notifyListeners();
  }

  // ── Refresh profile ───────────────────────────────────────────────────────

  Future<void> refreshUser() async {
    try {
      final data = await ApiService.me(onUnauthorized: _handleUnauthorized);
      if (data['ok'] == true) {
        _student = StudentModel.fromMeJson(data);
        notifyListeners();
      }
    } catch (_) {}
  }

  // ── Helpers ───────────────────────────────────────────────────────────────

  void _handleUnauthorized() {
    StorageService.clearToken();
    _student = null;
    notifyListeners();
  }

  Future<(String, String)> _getDeviceInfo() async {
    final info = DeviceInfoPlugin();
    final android = await info.androidInfo;
    final id   = android.id;
    final name = '${android.manufacturer} ${android.model}';
    return (id, name);
  }
}
