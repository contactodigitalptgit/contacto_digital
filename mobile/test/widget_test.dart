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

    expect(find.text('ÁREA DO CLIENTE'), findsOneWidget);
    expect(find.text('O seu evento.\nEm tempo real.'), findsOneWidget);
    expect(find.text('Email'), findsOneWidget);
    expect(find.text('Palavra-passe'), findsOneWidget);
    expect(find.text('Entrar no evento'), findsOneWidget);
    expect(find.text('Política de privacidade'), findsOneWidget);
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
    expect(find.text('TOTAL'), findsOneWidget);
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
      find.text('Desempenho por device'),
      300,
      scrollable: find.byType(Scrollable).first,
    );
    expect(find.text('Desempenho por device'), findsOneWidget);
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
    expect(find.text('TOTAL'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('summary cards and hourly chart expose touch details',
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

    final ticketCard = tester.widget<InkWell>(
      find
          .ancestor(
            of: find.text('TICKET MÉDIO'),
            matching: find.byType(InkWell),
          )
          .first,
    );
    ticketCard.onTap!();
    await tester.pumpAndSettle();
    expect(
        find.text('Valor médio faturado em cada transação.'), findsOneWidget);

    tester.state<NavigatorState>(find.byType(Navigator)).pop();
    await tester.pumpAndSettle();
    final hourBar = tester.widget<GestureDetector>(
      find
          .ancestor(
            of: find.text('20:00'),
            matching: find.byType(GestureDetector),
          )
          .first,
    );
    hourBar.onTap!();
    await tester.pumpAndSettle();

    expect(find.text('20:00'), findsNWidgets(2));
    expect(tester.takeException(), isNull);
  });

  testWidgets('client navigates to products and combines multiple zones',
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

    await tester.tap(find.text('Produtos').last);
    await tester.pumpAndSettle();
    expect(find.text('PESO DAS OFERTAS'), findsOneWidget);
    expect(find.text('FATURAÇÃO'), findsNothing);
    expect(find.text('RANKING DE PRODUTOS'), findsOneWidget);
    expect(find.text('2 referências apresentadas'), findsOneWidget);
    expect(find.text('Por faturação'), findsOneWidget);
    expect(find.text('Ref. 727 · 432 servidos'), findsOneWidget);

    final productRow = tester.widget<InkWell>(
      find
          .ancestor(
            of: find.text('Cerveja'),
            matching: find.byType(InkWell),
          )
          .first,
    );
    productRow.onTap!();
    await tester.pumpAndSettle();
    expect(find.text('Referência'), findsOneWidget);
    expect(find.text('Valor faturado'), findsOneWidget);
    expect(find.text('% das vendas'), findsOneWidget);
    tester.state<NavigatorState>(find.byType(Navigator)).pop();
    await tester.pumpAndSettle();

    await tester.tap(find.byTooltip('Ajustar filtros'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Bar 1').last);
    await tester.tap(find.text('Bar 2'));
    await tester.tap(find.text('Aplicar filtros'));
    await tester.pumpAndSettle();

    expect(apiClient.sectionRequests.last['section'], 'products');
    expect(
      apiClient.sectionRequests.last['filters']['bar_groups'],
      ['Bar 1', 'Bar 2'],
    );
    expect(tester.takeException(), isNull);
  });

  testWidgets('all client portal destinations open without layout errors',
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

    await tester.tap(find.text('Pagamentos').last);
    await tester.pumpAndSettle();
    expect(find.text('PAGAMENTOS × VENDAS'), findsOneWidget);
    await tester.ensureVisible(find.text('Bar 2 - POS A'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Bar 2 - POS A'));
    await tester.pumpAndSettle();
    expect(find.text('Pagamentos e vendas estão conciliados.'), findsOneWidget);
    expect(find.text('Conciliado'), findsOneWidget);
    tester.state<NavigatorState>(find.byType(Navigator)).pop();
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('quick-menu-button')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Zonas'));
    await tester.pumpAndSettle();
    expect(find.text('DEVICES'), findsOneWidget);

    await tester.tap(find.text('Ranking'));
    await tester.pumpAndSettle();
    expect(find.text('RANKING DE ZONAS'), findsOneWidget);
    await tester.tap(find.text('Devices'));
    await tester.pumpAndSettle();
    expect(find.text('RANKING DE DEVICES'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('quick-menu-button')));
    await tester.pumpAndSettle();
    expect(find.text('Exportar relatório'), findsNothing);
    await tester.tap(find.text('Comparar edições'));
    await tester.pumpAndSettle();
    expect(find.text('INDICADORES OPERACIONAIS'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}

class _FakeApiClient extends ApiClient {
  final List<int> dashboardRequests = [];
  final List<Map<String, dynamic>> sectionRequests = [];

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

  @override
  Future<Map<String, dynamic>> fetchConfiguration(int eventId) async => {
        'preset': 'complete',
        'blocks': [
          {
            'key': 'overview',
            'label': 'TOTAL SEM ZT',
            'visible': true,
            'available': true,
          },
          {
            'key': 'operations',
            'label': 'OPERAÇÃO DO EVENTO',
            'visible': true,
            'available': true,
          },
        ],
        'sections': [
          {'key': 'summary', 'visible': true, 'available': true},
          {'key': 'products', 'visible': true, 'available': true},
          {'key': 'reconciliation', 'visible': true, 'available': true},
          {'key': 'zones', 'visible': true, 'available': true},
          {'key': 'highlights', 'visible': true, 'available': true},
          {'key': 'comparison', 'visible': true, 'available': true},
        ],
      };

  @override
  Future<Map<String, dynamic>> fetchFilterOptions(int eventId) async => {
        'zones': [
          {'value': 'Bar 1', 'label': 'Bar 1'},
          {'value': 'Bar 2', 'label': 'Bar 2'},
        ],
        'stores': [
          {'value': 'Bar 1 - POS A', 'label': 'Bar 1 - POS A'},
          {'value': 'Bar 2 - POS B', 'label': 'Bar 2 - POS B'},
        ],
        'products': [
          {'value': 'P1', 'label': 'Cerveja'},
          {'value': 'P2', 'label': 'Água'},
        ],
      };

  @override
  Future<Map<String, dynamic>> fetchEventSection(
    int eventId,
    String section, {
    Map<String, dynamic> filters = const {},
  }) async {
    sectionRequests.add({
      'event_id': eventId,
      'section': section,
      'filters': filters,
    });

    if (section == 'products') {
      return {
        'summary': {
          'sold_quantity': 630,
          'offered_quantity': 12,
          'served_quantity': 642,
          'offer_share': 1.87,
          'total_sales': 38970.0,
          'products_count': 2,
        },
        'items': [
          {
            'product_code': '727',
            'description': 'Cerveja',
            'sold_quantity': 420,
            'offered_quantity': 12,
            'served_quantity': 432,
            'total_sales': 27836.31,
          },
          {
            'product_code': '730',
            'description': 'Água',
            'sold_quantity': 210,
            'offered_quantity': 0,
            'served_quantity': 210,
            'total_sales': 11134.52,
          },
        ],
        'daily': [],
      };
    }

    if (section == 'payments') {
      return {
        'summary': {
          'available': true,
          'multibanco': 30000.0,
          'cash': 8970.0,
          'zticket': 0.0,
          'other': 0.0,
          'documents_count': 4872,
        },
        'reconciliation': {
          'totals': {
            'payments_total': 38970.0,
            'sales_total': 38970.0,
            'difference': 0.0,
          },
          'items': [
            {
              'store_name': 'Bar 2 - POS A',
              'store_code': 'POS-A',
              'payments_total': 20000.0,
              'sales_total': 20000.0,
              'difference': 0.0,
            },
          ],
        },
      };
    }

    if (section == 'zones') {
      return {
        'summary': {
          'total_sales': 38970.0,
          'tickets_count': 4872,
          'devices_count': 2,
          'zones_count': 1,
          'leading_zone': {'label': 'Bar 1', 'total_sales': 38970.0},
        },
        'items': [
          {
            'label': 'Bar 1',
            'share': 100.0,
            'devices_count': 2,
            'total_sales': 38970.0,
            'products': [
              {
                'description': 'Cerveja',
                'sold_quantity': 420,
                'served_quantity': 432,
                'total_sales': 27836.31,
              },
            ],
            'items': [
              {
                'store_name': 'Bar 1 - POS A',
                'tickets_count': 120,
                'total_sales': 20000.0,
              },
            ],
          },
        ],
      };
    }

    return {'summary': {}, 'items': []};
  }
}
