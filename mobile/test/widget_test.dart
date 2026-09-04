import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';

import 'package:contacto_digital_mobile/api_client.dart';
import 'package:contacto_digital_mobile/screens/event_summary_screen.dart';
import 'package:contacto_digital_mobile/screens/login_screen.dart';
import 'package:contacto_digital_mobile/theme/app_theme.dart';

void main() {
  setUpAll(() => initializeDateFormatting('pt_PT'));

  testWidgets('login screen shows the email and password fields',
      (WidgetTester tester) async {
    // Pumps LoginScreen directly rather than the whole app: the app's
    // startup gate reads the stored token via a platform channel
    // (flutter_secure_storage) that isn't mocked in a plain widget test,
    // which would leave the test stuck on the startup spinner forever.
    await tester.pumpWidget(
      MaterialApp(home: LoginScreen(apiClient: ApiClient())),
    );

    expect(find.text('O MEU EVENTO'), findsOneWidget);
    expect(find.text('O seu evento,\nsempre por perto.'), findsOneWidget);
    expect(find.text('Email'), findsOneWidget);
    expect(find.text('Palavra-passe'), findsOneWidget);
    expect(find.text('Entrar no evento'), findsOneWidget);
  });

  testWidgets('event dashboard fits a compact phone viewport',
      (WidgetTester tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.dark,
        home: EventSummaryScreen(apiClient: _FakeApiClient()),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Festival de Verão'), findsOneWidget);
    expect(find.text('FATURAÇÃO DO EVENTO'), findsOneWidget);
    await tester.scrollUntilVisible(
      find.text('Vendas por hora'),
      300,
      scrollable: find.byType(Scrollable).first,
    );
    expect(find.text('Vendas por hora'), findsOneWidget);
    await tester.scrollUntilVisible(
      find.text('Produtos em destaque'),
      300,
      scrollable: find.byType(Scrollable).first,
    );
    expect(find.text('Produtos em destaque'), findsOneWidget);
    await tester.scrollUntilVisible(
      find.text('Desempenho por loja'),
      300,
      scrollable: find.byType(Scrollable).first,
    );
    expect(find.text('Desempenho por loja'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('client can switch between available events',
      (WidgetTester tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final apiClient = _FakeApiClient();

    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.dark,
        home: EventSummaryScreen(apiClient: apiClient),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.text('TROCAR'));
    await tester.pumpAndSettle();
    expect(find.text('Escolher evento'), findsOneWidget);

    await tester.tap(find.text('Festival Antigo'));
    await tester.pumpAndSettle();

    expect(find.text('Festival Antigo'), findsOneWidget);
    expect(apiClient.dashboardRequests.last, 8);
    expect(tester.takeException(), isNull);
  });

  testWidgets('event dashboard fits a wide viewport',
      (WidgetTester tester) async {
    tester.view.physicalSize = const Size(1280, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.dark,
        home: EventSummaryScreen(apiClient: _FakeApiClient()),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Festival de Verão'), findsOneWidget);
    expect(find.text('FATURAÇÃO DO EVENTO'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}

class _FakeApiClient extends ApiClient {
  final List<int> dashboardRequests = [];

  @override
  Future<List<Map<String, dynamic>>> fetchEvents() async => [
        {
          'id': 9,
          'title': 'Festival de Verão',
          'event_date': '2026-08-30T00:00:00.000000Z',
        },
        {
          'id': 8,
          'title': 'Festival Antigo',
          'event_date': '2026-08-10T00:00:00.000000Z',
        },
      ];

  @override
  Future<Map<String, dynamic>> fetchDashboard(int eventId) async {
    dashboardRequests.add(eventId);
    final totalSales = eventId == 8 ? 10000.0 : 92787.70;

    return {
      'summary': {
        'total_sales': totalSales,
        'tickets_count': eventId == 8 ? 500 : 4872,
        'average_ticket': eventId == 8 ? 20.0 : 19.0442,
        'stores_count': eventId == 8 ? 2 : 52,
        'machines_count': eventId == 8 ? 3 : 52,
        'last_synced_at': '2026-08-30T12:34:00.000000Z',
      },
      'hourly_sales': [
        {
          'hour': 20,
          'hour_label': '20:00',
          'total_sales': totalSales * 0.35,
          'tickets_count': 120,
        },
        {
          'hour': 21,
          'hour_label': '21:00',
          'total_sales': totalSales * 0.65,
          'tickets_count': 240,
        },
      ],
      'top_products': [
        {
          'description': 'Cerveja',
          'sold_quantity': 420,
          'offered_quantity': 12,
          'total_sales': totalSales * 0.3,
        },
        {
          'description': 'Água',
          'sold_quantity': 210,
          'offered_quantity': 0,
          'total_sales': totalSales * 0.12,
        },
      ],
      'top_stores': [
        {'store_name': 'Bar Central', 'total_sales': totalSales * 0.4},
        {'store_name': 'Restauração', 'total_sales': totalSales * 0.25},
        {'store_name': 'Zona VIP', 'total_sales': totalSales * 0.15},
      ],
    };
  }
}
