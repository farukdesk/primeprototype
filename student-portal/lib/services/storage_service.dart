import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Persists the API token and device ID securely on-device.
class StorageService {
  static const _storage   = FlutterSecureStorage(
    aOptions: AndroidOptions(encryptedSharedPreferences: true),
  );
  static const _keyToken    = 'sp_api_token';
  static const _keyDeviceId = 'sp_device_id';

  static Future<void>    saveToken(String t)  async => _storage.write(key: _keyToken, value: t);
  static Future<String?> getToken()           async => _storage.read(key: _keyToken);
  static Future<void>    clearToken()         async => _storage.delete(key: _keyToken);

  static Future<void>    saveDeviceId(String d) async => _storage.write(key: _keyDeviceId, value: d);
  static Future<String?> getDeviceId()          async => _storage.read(key: _keyDeviceId);
}
