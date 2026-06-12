import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import 'storage_service.dart';

/// Central HTTP client for the Student Portal API.
class ApiService {
  // ── Base URL ───────────────────────────────────────────────────────────────
  // Change this to match your server. No trailing slash.
  static const String baseUrl = 'https://primeuniversity.ac.bd/admin/api/student';

  static final Dio _dio = Dio(
    BaseOptions(
      baseUrl: baseUrl,
      connectTimeout: const Duration(seconds: 15),
      receiveTimeout: const Duration(seconds: 30),
      sendTimeout:    const Duration(seconds: 30),
      responseType:   ResponseType.json,
    ),
  );

  static bool _interceptorsAdded = false;

  static void _ensure(VoidCallback onUnauthorized) {
    if (_interceptorsAdded) return;
    _interceptorsAdded = true;

    _dio.interceptors.add(InterceptorsWrapper(
      onRequest: (opts, handler) async {
        final token    = await StorageService.getToken();
        final deviceId = await StorageService.getDeviceId();
        if (token    != null) opts.headers['Authorization'] = 'Bearer $token';
        if (deviceId != null) opts.headers['X-Device-ID']   = deviceId;
        handler.next(opts);
      },
      onError: (err, handler) {
        if (err.response?.statusCode == 401) onUnauthorized();
        handler.next(err);
      },
    ));
  }

  // ── Auth ──────────────────────────────────────────────────────────────────

  static Future<Map<String, dynamic>> login({
    required String login,
    required String password,
    required String deviceId,
    required String deviceName,
    required VoidCallback onUnauthorized,
  }) async {
    _ensure(onUnauthorized);
    final resp = await _dio.post(
      '/auth/login.php',
      data: {
        'login':       login,
        'password':    password,
        'device_id':   deviceId,
        'device_name': deviceName,
      },
      options: Options(contentType: Headers.formUrlEncodedContentType),
    );
    return _parse(resp);
  }

  static Future<void> logout({required VoidCallback onUnauthorized}) async {
    _ensure(onUnauthorized);
    try {
      await _dio.post('/auth/logout.php');
    } catch (_) {}
  }

  static Future<Map<String, dynamic>> me({
    required VoidCallback onUnauthorized,
  }) async {
    _ensure(onUnauthorized);
    final resp = await _dio.get('/auth/me.php');
    return _parse(resp);
  }

  // ── Notices ───────────────────────────────────────────────────────────────

  static Future<Map<String, dynamic>> getNotices({
    required String type,
    required int page,
    required VoidCallback onUnauthorized,
  }) async {
    _ensure(onUnauthorized);
    final resp = await _dio.get('/notices.php', queryParameters: {
      'type':  type,
      'page':  page,
      'limit': 20,
    });
    return _parse(resp);
  }

  static Future<Map<String, dynamic>> getNoticeDetail({
    required int id,
    required String type,
    required VoidCallback onUnauthorized,
  }) async {
    _ensure(onUnauthorized);
    final resp = await _dio.get('/notices.php', queryParameters: {
      'id':   id,
      'type': type,
    });
    return _parse(resp);
  }

  // ── Finances ──────────────────────────────────────────────────────────────

  static Future<Map<String, dynamic>> getFinances({
    required VoidCallback onUnauthorized,
  }) async {
    _ensure(onUnauthorized);
    final resp = await _dio.get('/finances.php');
    return _parse(resp);
  }

  // ── Push token ────────────────────────────────────────────────────────────

  static Future<void> registerPushToken({
    required String fcmToken,
    required String deviceId,
    required VoidCallback onUnauthorized,
  }) async {
    _ensure(onUnauthorized);
    try {
      await _dio.post(
        '/push/register.php',
        data: {
          'fcm_token': fcmToken,
          'device_id': deviceId,
          'platform':  'android',
        },
        options: Options(contentType: Headers.formUrlEncodedContentType),
      );
    } catch (e) {
      debugPrint('FCM token registration failed: $e');
    }
  }

  // ── Helpers ───────────────────────────────────────────────────────────────

  static Map<String, dynamic> _parse(Response<dynamic> resp) {
    if (resp.data is Map<String, dynamic>) return resp.data as Map<String, dynamic>;
    if (resp.data is String) return jsonDecode(resp.data as String) as Map<String, dynamic>;
    throw const FormatException('Unexpected API response format.');
  }

  static bool isNetworkError(Object e) {
    if (e is DioException) {
      return e.type == DioExceptionType.connectionTimeout ||
          e.type == DioExceptionType.receiveTimeout ||
          e.type == DioExceptionType.sendTimeout ||
          e.type == DioExceptionType.connectionError ||
          (e.error is SocketException);
    }
    return e is SocketException;
  }

  static String friendlyError(Object e) {
    if (e is DioException) {
      final data = e.response?.data;
      if (data is Map && data['error'] != null) return data['error'].toString();
      if (isNetworkError(e)) return 'No internet connection. Please check your network.';
      return 'Server error (${e.response?.statusCode ?? 'unknown'}). Please try again.';
    }
    return e.toString();
  }
}
