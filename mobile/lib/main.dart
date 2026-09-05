import 'package:flutter/material.dart';
import 'package:intl/date_symbol_data_local.dart';

import 'api_client.dart';
import 'screens/event_summary_screen.dart';
import 'screens/login_screen.dart';
import 'theme/app_theme.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  // NumberFormat works out of the box, but DateFormat (used for "última
  // sincronização") throws LocaleDataException for any locale beyond the
  // default until its symbol data is loaded explicitly — found running
  // the app for real, not something a plain `flutter analyze` catches.
  await initializeDateFormatting('pt_PT');

  runApp(const ContactoDigitalApp());
}

class ContactoDigitalApp extends StatelessWidget {
  const ContactoDigitalApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Contacto Digital',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.dark,
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
            body: BrandBackground(
              child: Center(child: CircularProgressIndicator()),
            ),
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
