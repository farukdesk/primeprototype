import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import 'storage_service.dart';

/// Central HTTP client for all PUMIS API calls.
/// Automatically attaches the Authorization header and handles 401 responses.
class ApiService {
  // ── Base URL ───────────────────────────────────────────────────────────────
  // Replace with your actual server URL (no trailing slash).
  static const String baseUrl = 'https://primeuniversity.ac.bd/admin/api';

  static final Dio _dio = Dio(
    BaseOptions(
      baseUrl: baseUrl,
      connectTimeout: const Duration(seconds: 15),
      receiveTimeout: const Duration(seconds: 30),
      sendTimeout: const Duration(seconds: 30),
      responseType: ResponseType.json,
    ),
  );

  static bool _interceptorsAdded = false;

  static void _ensureInterceptors(VoidCallback onUnauthorized) {
    if (_interceptorsAdded) return;
    _interceptorsAdded = true;

    _dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) async {
          final token = await StorageService.getToken();
          if (token != null) {
            options.headers['Authorization'] = 'Bearer ' + token;
          }
          final deviceId = await StorageService.getDeviceId();
          if (deviceId != null) {
            options.headers['X-Device-ID'] = deviceId;
          }
          handler.next(options);
        },
        onError: (error, handler) {
          if (error.response?.statusCode == 401) {
            onUnauthorized();
          }
          handler.next(error);
        },
      ),
    );
  }

  // ── Auth ──────────────────────────────────────────────────────────────────

  /// POST /auth/login.php
  static Future<Map<String, dynamic>> login({
    required String login,
    required String password,
    required String deviceId,
    required String deviceName,
    required VoidCallback onUnauthorized,
  }) async {
    _ensureInterceptors(onUnauthorized);
    final response = await _dio.post(
      '/auth/login.php',
      data: {
        'login': login,
        'password': password,
        'device_id': deviceId,
        'device_name': deviceName,
      },
      options: Options(contentType: Headers.formUrlEncodedContentType),
    );
    return _parse(response);
  }

  /// POST /auth/logout.php
  static Future<void> logout({required VoidCallback onUnauthorized}) async {
    _ensureInterceptors(onUnauthorized);
    try {
      await _dio.post('/auth/logout.php');
    } catch (_) {
      // Ignore errors on logout – we clear local state regardless
    }
  }

  /// GET /auth/me.php
  static Future<Map<String, dynamic>> me({
    required VoidCallback onUnauthorized,
  }) async {
    _ensureInterceptors(onUnauthorized);
    final response = await _dio.get('/auth/me.php');
    return _parse(response);
  }

  /// POST /push/register.php
  static Future<void> registerPushToken({
    required String fcmToken,
    required String deviceId,
    required VoidCallback onUnauthorized,
  }) async {
    _ensureInterceptors(onUnauthorized);
    try {
      await _dio.post(
        '/push/register.php',
        data: {
          'fcm_token': fcmToken,
          'device_id': deviceId,
          'platform': 'android',
        },
        options: Options(contentType: Headers.formUrlEncodedContentType),
      );
    } catch (e) {
      debugPrint('FCM token registration failed: $e');
    }
  }

  // ── Helpers ───────────────────────────────────────────────────────────────

  static Map<String, dynamic> _parse(Response<dynamic> response) {
    if (response.data is Map<String, dynamic>) {
      return response.data as Map<String, dynamic>;
    }
    if (response.data is String) {
      return jsonDecode(response.data as String) as Map<String, dynamic>;
    }
    throw const FormatException('Unexpected API response format.');
  }

  /// Returns true if an exception is network-related (no connectivity).
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
      if (data is Map && data['error'] != null) {
        return data['error'].toString();
      }
      if (isNetworkError(e)) {
        return 'No internet connection. Please check your network and try again.';
      }
      return 'Server error (${e.response?.statusCode ?? 'unknown'}). Please try again.';
    }
    return e.toString();
  }
}
