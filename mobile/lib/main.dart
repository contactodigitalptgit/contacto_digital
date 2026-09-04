import 'package:flutter/material.dart';

import 'api_client.dart';
import 'screens/event_summary_screen.dart';
import 'screens/login_screen.dart';

void main() {
  runApp(const ContactoDigitalApp());
}

class ContactoDigitalApp extends StatelessWidget {
  const ContactoDigitalApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'O meu evento',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        colorSchemeSeed: Colors.indigo,
        useMaterial3: true,
      ),
      home: const _StartupGate(),
    );
  }
}

/// Decides login vs. summary based on whether a token is already stored —
/// the app never asks a returning client to log in again just to see
/// their event; an expired/revoked token is instead caught by
/// [ApiClient]'s 401/403 handling once a request actually fails.
class _StartupGate extends StatefulWidget {
  const _StartupGate();

  @override
  State<_StartupGate> createState() => _StartupGateState();
}

class _StartupGateState extends State<_StartupGate> {
  final _apiClient = ApiClient();
  late final Future<String?> _tokenFuture = _apiClient.readToken();

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<String?>(
      future: _tokenFuture,
      builder: (context, snapshot) {
        if (snapshot.connectionState != ConnectionState.done) {
          return const Scaffold(
            body: Center(child: CircularProgressIndicator()),
          );
        }

        final hasToken = snapshot.data != null;

        return hasToken
            ? EventSummaryScreen(apiClient: _apiClient)
            : LoginScreen(apiClient: _apiClient);
      },
    );
  }
}
