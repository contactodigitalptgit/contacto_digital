import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;

/// Thin HTTP client for the Contacto Digital mobile API
/// (routes/api.php in the main Laravel app — see
/// docs/PLANO_DE_PERFORMANCE_SINCRONIZACAO.md, "app Flutter, cliente
/// acompanhar o evento"). Talks to the same aggregate-table-backed
/// endpoints the client web dashboard's fast path uses, so numbers here
/// always match the web dashboard.
///
/// Change [baseUrl] to point at a local dev server while testing
/// (e.g. http://10.0.2.2:8000/api on the Android emulator, or your
/// machine's LAN IP on a physical device — localhost from the device
/// itself is never the dev machine).
class ApiException implements Exception {
  ApiException(this.message, {this.statusCode});

  final String message;
  final int? statusCode;

  @override
  String toString() => message;
}

class ApiClient {
  ApiClient({String? baseUrl}) : baseUrl = baseUrl ?? defaultBaseUrl;

  static const String defaultBaseUrl =
      'https://portal.contactodigital.pt/api';

  final String baseUrl;
  final http.Client _http = http.Client();
  final FlutterSecureStorage _storage = const FlutterSecureStorage();

  static const _tokenKey = 'auth_token';

  Future<String?> readToken() => _storage.read(key: _tokenKey);

  Future<void> _saveToken(String token) =>
      _storage.write(key: _tokenKey, value: token);

  Future<void> clearToken() => _storage.delete(key: _tokenKey);

  Future<Map<String, dynamic>> login(String email, String password) async {
    final response = await _http.post(
      Uri.parse('$baseUrl/login'),
      headers: _jsonHeaders(),
      body: jsonEncode({'email': email, 'password': password}),
    );

    final body = _decode(response);

    if (response.statusCode != 200) {
      throw ApiException(_errorMessage(body), statusCode: response.statusCode);
    }

    await _saveToken(body['token'] as String);

    return (body['client'] as Map).cast<String, dynamic>();
  }

  Future<void> logout() async {
    final token = await readToken();
    if (token == null) return;

    try {
      await _http.post(
        Uri.parse('$baseUrl/logout'),
        headers: await _authHeaders(),
      );
    } finally {
      await clearToken();
    }
  }

  Future<List<Map<String, dynamic>>> fetchEvents() async {
    final body = await _get('/events');

    return (body['events'] as List)
        .map((event) => (event as Map).cast<String, dynamic>())
        .toList();
  }

  Future<Map<String, dynamic>> fetchSummary(int eventId) async {
    final body = await _get('/events/$eventId/summary');

    return (body['summary'] as Map).cast<String, dynamic>();
  }

  Future<List<Map<String, dynamic>>> fetchTopStores(int eventId) async {
    final body = await _get('/events/$eventId/top-stores');

    return (body['top_stores'] as List)
        .map((store) => (store as Map).cast<String, dynamic>())
        .toList();
  }

  Future<Map<String, dynamic>> _get(String path) async {
    final response = await _http.get(
      Uri.parse('$baseUrl$path'),
      headers: await _authHeaders(),
    );

    final body = _decode(response);

    if (response.statusCode == 401 || response.statusCode == 403) {
      // Token revoked (client deactivated) or expired — the app must
      // fall back to the login screen, not keep retrying silently.
      await clearToken();
      throw ApiException(_errorMessage(body), statusCode: response.statusCode);
    }

    if (response.statusCode != 200) {
      throw ApiException(_errorMessage(body), statusCode: response.statusCode);
    }

    return body;
  }

  Map<String, dynamic> _decode(http.Response response) {
    if (response.body.isEmpty) return {};

    try {
      return jsonDecode(response.body) as Map<String, dynamic>;
    } on FormatException {
      return {'message': 'Resposta inesperada do servidor.'};
    }
  }

  String _errorMessage(Map<String, dynamic> body) {
    if (body['errors'] is Map) {
      final errors = (body['errors'] as Map).values.expand((v) => v as List);
      if (errors.isNotEmpty) return errors.first.toString();
    }

    return (body['message'] as String?) ?? 'Ocorreu um erro inesperado.';
  }

  Map<String, String> _jsonHeaders() => {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      };

  Future<Map<String, String>> _authHeaders() async {
    final token = await readToken();

    return {
      ..._jsonHeaders(),
      if (token != null) 'Authorization': 'Bearer $token',
    };
  }
}
