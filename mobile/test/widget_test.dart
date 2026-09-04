import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:contacto_digital_mobile/api_client.dart';
import 'package:contacto_digital_mobile/screens/login_screen.dart';

void main() {
  testWidgets('login screen shows the email and password fields',
      (WidgetTester tester) async {
    // Pumps LoginScreen directly rather than the whole app: the app's
    // startup gate reads the stored token via a platform channel
    // (flutter_secure_storage) that isn't mocked in a plain widget test,
    // which would leave the test stuck on the startup spinner forever.
    await tester.pumpWidget(
      MaterialApp(home: LoginScreen(apiClient: ApiClient())),
    );

    expect(find.text('O meu evento'), findsOneWidget);
    expect(find.text('Email'), findsOneWidget);
    expect(find.text('Palavra-passe'), findsOneWidget);
  });
}
