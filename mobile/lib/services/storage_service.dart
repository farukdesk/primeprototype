import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Manages secure persistent storage of the API token and related data.
class StorageService {
  static const _storage = FlutterSecureStorage(
    aOptions: AndroidOptions(encryptedSharedPreferences: true),
  );

  static const _keyToken      = 'api_token';
  static const _keyExpiresAt  = 'api_token_expires_at';
  static const _keyDeviceId   = 'device_id';

  // ── Token ──────────────────────────────────────────────────────────────────

  static Future<void> saveToken(String token, String expiresAt) async {
    await Future.wait([
      _storage.write(key: _keyToken,     value: token),
      _storage.write(key: _keyExpiresAt, value: expiresAt),
    ]);
  }

  static Future<String?> getToken() async {
    final token     = await _storage.read(key: _keyToken);
    final expiresAt = await _storage.read(key: _keyExpiresAt);

    if (token == null || expiresAt == null) return null;

    // Validate expiry client-side (server also validates)
    final expiry = DateTime.tryParse(expiresAt);
    if (expiry == null || expiry.isBefore(DateTime.now())) {
      await clearToken();
      return null;
    }
    return token;
  }

  static Future<void> clearToken() async {
    await Future.wait([
      _storage.delete(key: _keyToken),
      _storage.delete(key: _keyExpiresAt),
    ]);
  }

  // ── Device ID ──────────────────────────────────────────────────────────────

  static Future<void> saveDeviceId(String deviceId) async {
    await _storage.write(key: _keyDeviceId, value: deviceId);
  }

  static Future<String?> getDeviceId() async {
    return _storage.read(key: _keyDeviceId);
  }

  // ── Clear all ─────────────────────────────────────────────────────────────

  static Future<void> clearAll() async {
    await _storage.deleteAll();
  }
}
