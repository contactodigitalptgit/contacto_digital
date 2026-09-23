import 'dart:async';
import 'dart:math' as math;
import 'dart:ui';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart';

import '../api_client.dart';
import '../theme/app_theme.dart';
import 'login_screen.dart';

part 'event_portal_sections.dart';

class EventSummaryScreen extends StatefulWidget {
  const EventSummaryScreen({super.key, required this.apiClient});

  final ApiClient apiClient;

  @override
  State<EventSummaryScreen> createState() => _EventSummaryScreenState();
}

class _EventSummaryScreenState extends State<EventSummaryScreen> {
  static const _refreshInterval = Duration(seconds: 60);

  final _currency = NumberFormat.currency(locale: 'pt_PT', symbol: '€');
  final _integer = NumberFormat.decimalPattern('pt_PT');

  List<Map<String, dynamic>> _events = [];
  Map<String, dynamic>? _event;
  Map<String, dynamic>? _summary;
  List<Map<String, dynamic>> _topStores = [];
  List<Map<String, dynamic>> _topProducts = [];
  List<Map<String, dynamic>> _hourlySales = [];

  String _activeSection = 'summary';
  DashboardFilters _filters = const DashboardFilters();
  Map<String, dynamic>? _filterOptions;
  Map<String, dynamic>? _configuration;
  final Map<String, Map<String, dynamic>> _sectionData = {};
  final TextEditingController _sectionSearchController =
      TextEditingController();
  bool _sectionLoading = false;
  String? _sectionError;
  String _sectionSearch = '';
  int? _selectedHourIndex;
  bool _quickMenuOpen = false;
  String _rankingScope = 'zones';
  bool _productSortBySales = false;

  int? _selectedEventId;
  int _requestVersion = 0;
  bool _loading = true;
  bool _refreshing = false;
  bool _requestInFlight = false;
  String? _error;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _load();
    _timer = Timer.periodic(_refreshInterval, (_) => _load(silent: true));
  }

  @override
  void dispose() {
    _timer?.cancel();
    _sectionSearchController.dispose();
    super.dispose();
  }

  Future<void> _load({bool silent = false, int? eventId}) async {
    if (silent && _requestInFlight) return;

    final requestVersion = ++_requestVersion;
    _requestInFlight = true;

    if (!silent && mounted) {
      setState(() {
        _loading = _summary == null || eventId != null;
        _refreshing = _summary != null && eventId == null;
        if (eventId != null) _error = null;
      });
    }

    try {
      final events = await widget.apiClient.fetchEvents();

      if (events.isEmpty) {
        if (!mounted || requestVersion != _requestVersion) return;
        setState(() {
          _events = [];
          _event = null;
          _summary = null;
          _error = null;
          _loading = false;
          _refreshing = false;
        });
        return;
      }

      final preferredId = eventId ?? _selectedEventId;
      final selectedEvent = events.firstWhere(
        (candidate) => candidate['id'] == preferredId,
        orElse: () => events.first,
      );
      final selectedId = selectedEvent['id'] as int;
      final requestFilters =
          selectedId == _selectedEventId ? _filters : _filters.dateRangeOnly;
      final responses = await Future.wait<dynamic>([
        requestFilters.activeCount > 0
            ? widget.apiClient.fetchEventSection(
                selectedId,
                'dashboard',
                filters: requestFilters.toQuery(),
              )
            : widget.apiClient.fetchDashboard(selectedId),
        _fetchConfigurationSafely(selectedId),
        _fetchZonesSafely(selectedId, filters: requestFilters),
      ]);
      final dashboard = (responses[0] as Map).cast<String, dynamic>();
      final configuration = responses[1] as Map<String, dynamic>?;
      final zones = responses[2] as Map<String, dynamic>?;
      final summary = (dashboard['summary'] as Map).cast<String, dynamic>();
      final leadingZone = _map(zones?['summary'])['leading_zone'];
      if (leadingZone is Map && leadingZone.isNotEmpty) {
        summary['leading_zone'] = leadingZone.cast<String, dynamic>();
      }
      // Older API responses do not include the aggregate quantity. The zones
      // response is already loaded for the leader card and provides it safely.
      final zoneItems = _mapList(zones?['items']);
      final zoneQuantity = zoneItems.fold<double>(
        0,
        (total, zone) =>
            total + ((zone['quantity_total'] as num?)?.toDouble() ?? 0),
      );
      final summaryQuantity =
          (summary['total_quantity'] as num?)?.toDouble() ?? 0;
      if (summaryQuantity <= 0 && zoneQuantity > 0) {
        summary['total_quantity'] = zoneQuantity;
      }
      final summaryZones = (summary['zones_count'] as num?)?.toInt() ?? 0;
      if (summaryZones <= 0 && zoneItems.isNotEmpty) {
        summary['zones_count'] = zoneItems.length;
      }

      if (!mounted || requestVersion != _requestVersion) return;
      String? sectionToLoad;
      setState(() {
        final eventChanged = _selectedEventId != selectedId;
        _events = events;
        _selectedEventId = selectedId;
        _event = selectedEvent;
        _summary = summary;
        _topStores = _mapList(dashboard['top_stores']);
        _topProducts = _mapList(dashboard['top_products']);
        _hourlySales = _mapList(dashboard['hourly_sales']);
        _error = null;
        _loading = false;
        _refreshing = false;
        _configuration = configuration ?? _configuration;
        if (eventChanged) {
          _activeSection = _initialSection(configuration);
          sectionToLoad = _activeSection;
          _filters = requestFilters;
          _filterOptions = null;
          _sectionData.clear();
          _sectionError = null;
          _sectionSearch = '';
          _sectionSearchController.clear();
        }
      });
      if (sectionToLoad != null &&
          sectionToLoad != 'summary' &&
          sectionToLoad != 'more') {
        unawaited(_loadSection(sectionToLoad!));
      }
    } on ApiException catch (exception) {
      if (!mounted || requestVersion != _requestVersion) return;

      if (exception.statusCode == 401 || exception.statusCode == 403) {
        _goToLogin();
        return;
      }

      setState(() {
        _error = exception.message;
        _loading = false;
        _refreshing = false;
      });
    } finally {
      if (requestVersion == _requestVersion) _requestInFlight = false;
    }
  }

  Future<Map<String, dynamic>?> _fetchConfigurationSafely(int eventId) async {
    try {
      return await widget.apiClient.fetchConfiguration(eventId);
    } on ApiException {
      return null;
    }
  }

  Future<Map<String, dynamic>?> _fetchZonesSafely(
    int eventId, {
    DashboardFilters? filters,
  }) async {
    try {
      return await widget.apiClient.fetchEventSection(
        eventId,
        'zones',
        filters: (filters ?? _filters).toQuery(),
      );
    } on ApiException {
      return null;
    }
  }

  List<Map<String, dynamic>> _mapList(dynamic value) {
    if (value is! List) return [];

    return value
        .whereType<Map>()
        .map((item) => item.cast<String, dynamic>())
        .toList();
  }

  Future<void> _logout() async {
    await widget.apiClient.logout();
    if (!mounted) return;
    _goToLogin();
  }

  void _goToLogin() {
    Navigator.of(context).pushAndRemoveUntil(
      MaterialPageRoute(
        builder: (_) => LoginScreen(apiClient: widget.apiClient),
      ),
      (route) => false,
    );
  }

  Future<void> _showEventPicker() async {
    if (_events.length < 2) return;

    final selected = await showModalBottomSheet<int>(
      context: context,
      backgroundColor: AppColors.surface,
      showDragHandle: true,
      useSafeArea: true,
      builder: (context) => ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 680),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 4, 20, 24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text(
                'Escolher evento',
                style: TextStyle(fontSize: 22, fontWeight: FontWeight.w700),
              ),
              const SizedBox(height: 6),
              const Text(
                'Os resultados serão atualizados para o evento selecionado.',
                style: TextStyle(color: AppColors.textMuted, fontSize: 13),
              ),
              const SizedBox(height: 18),
              Flexible(
                child: ListView.separated(
                  shrinkWrap: true,
                  itemCount: _events.length,
                  separatorBuilder: (_, __) => const SizedBox(height: 8),
                  itemBuilder: (context, index) {
                    final event = _events[index];
                    final id = event['id'] as int;
                    final isSelected = id == _selectedEventId;

                    return ListTile(
                      onTap: () => Navigator.of(context).pop(id),
                      contentPadding: const EdgeInsets.symmetric(
                        horizontal: 16,
                        vertical: 4,
                      ),
                      tileColor: isSelected
                          ? AppColors.lime.withValues(alpha: 0.08)
                          : AppColors.surfaceRaised,
                      shape: RoundedRectangleBorder(
                        side: BorderSide(
                          color: isSelected ? AppColors.lime : AppColors.border,
                        ),
                        borderRadius: BorderRadius.circular(18),
                      ),
                      leading: Icon(
                        isSelected
                            ? Icons.check_circle_rounded
                            : Icons.event_outlined,
                        color:
                            isSelected ? AppColors.lime : AppColors.textMuted,
                      ),
                      title: Text(
                        _clientFacingLabel(event['title'], fallback: 'Evento'),
                        style: const TextStyle(fontWeight: FontWeight.w600),
                      ),
                      subtitle: Text(
                        _eventDate(event, fallback: 'Evento disponível'),
                        style: const TextStyle(color: AppColors.textMuted),
                      ),
                    );
                  },
                ),
              ),
            ],
          ),
        ),
      ),
    );

    if (selected != null && selected != _selectedEventId) {
      await _load(eventId: selected);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      extendBody: true,
      body: BrandBackground(
        child: SafeArea(
          bottom: false,
          child: RefreshIndicator(
            onRefresh: _activeSection == 'summary'
                ? _load
                : () => _loadSection(_activeSection, force: true),
            color: AppColors.navy,
            backgroundColor: AppColors.lime,
            child: _buildBody(),
          ),
        ),
      ),
      bottomNavigationBar: _portalNavigation(),
    );
  }

  Widget _buildBody() {
    if (_loading) return _loadingView();

    if (_error != null && _summary == null) {
      return _blockingErrorView();
    }

    if (_event == null) return _emptyEventView();

    if (_activeSection != 'summary') {
      return _featureBody();
    }

    return LayoutBuilder(
      builder: (context, constraints) {
        final isWide = constraints.maxWidth >= 760;
        final pagePadding = isWide ? 32.0 : 20.0;

        return ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: EdgeInsets.fromLTRB(pagePadding, 18, pagePadding, 122),
          children: [
            Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 1180),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    _dashboardHeader(),
                    const SizedBox(height: 24),
                    _eventSelector(),
                    const SizedBox(height: 12),
                    _filterToolbar(),
                    const SizedBox(height: 12),
                    _syncStatus(_summary?['last_synced_at'] as String?),
                    if (_error != null) ...[
                      const SizedBox(height: 12),
                      _staleDataNotice(),
                    ],
                    const SizedBox(height: 18),
                    _overview(_summary!, isWide: isWide),
                    if (_sectionIsVisible('charts')) ...[
                      const SizedBox(height: 24),
                      _sectionTitle(
                        'Vendas por hora',
                        '${_hourlySales.length} HORAS',
                      ),
                      const SizedBox(height: 12),
                      _hourlyChart(),
                    ],
                    if (_sectionIsVisible('products')) ...[
                      const SizedBox(height: 24),
                      _sectionTitle(
                        _configuredSectionHeading(
                          'products',
                          'Produtos em destaque',
                        ),
                        'TOP 6',
                      ),
                      const SizedBox(height: 12),
                      _productsPanel(),
                    ],
                    if (_sectionIsVisible('zones')) ...[
                      const SizedBox(height: 24),
                      _sectionTitle(
                        _configuredSectionHeading(
                          'zones',
                          'Desempenho por device',
                        ),
                        'TOP 10',
                      ),
                      const SizedBox(height: 12),
                      _storesPanel(_summary!),
                    ],
                    const SizedBox(height: 28),
                    const Center(
                      child: Text(
                        'CASHLESS BY CONTACTO DIGITAL',
                        style: TextStyle(
                          color: AppColors.textMuted,
                          fontSize: 10,
                          fontWeight: FontWeight.w600,
                          letterSpacing: 2.1,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ],
        );
      },
    );
  }

  Widget _loadingView() {
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.all(24),
      children: const [
        SizedBox(height: 180),
        Center(child: BrandLogo(size: 64)),
        SizedBox(height: 30),
        Center(child: CircularProgressIndicator()),
        SizedBox(height: 14),
        Center(
          child: Text(
            'A preparar o seu dashboard...',
            style: TextStyle(color: AppColors.textMuted),
          ),
        ),
      ],
    );
  }

  Widget _blockingErrorView() {
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.all(24),
      children: [
        const SizedBox(height: 120),
        const Icon(
          Icons.cloud_off_rounded,
          size: 52,
          color: AppColors.warning,
        ),
        const SizedBox(height: 18),
        const Text(
          'Não foi possível atualizar',
          textAlign: TextAlign.center,
          style: TextStyle(fontSize: 22, fontWeight: FontWeight.w600),
        ),
        const SizedBox(height: 8),
        Text(
          _error!,
          textAlign: TextAlign.center,
          style: const TextStyle(color: AppColors.textMuted),
        ),
        const SizedBox(height: 24),
        Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 340),
            child: FilledButton(
              onPressed: _load,
              child: const Text('Tentar novamente'),
            ),
          ),
        ),
      ],
    );
  }

  Widget _emptyEventView() {
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.all(24),
      children: const [
        SizedBox(height: 130),
        Icon(Icons.event_busy_rounded, size: 52, color: AppColors.textMuted),
        SizedBox(height: 18),
        Text(
          'Ainda não existe um evento disponível.',
          textAlign: TextAlign.center,
          style: TextStyle(fontSize: 20, fontWeight: FontWeight.w600),
        ),
        SizedBox(height: 8),
        Text(
          'Assim que o evento estiver configurado, os resultados aparecem aqui.',
          textAlign: TextAlign.center,
          style: TextStyle(color: AppColors.textMuted),
        ),
      ],
    );
  }

  Widget _dashboardHeader() {
    return Row(
      children: [
        const BrandLogo(size: 43),
        const SizedBox(width: 12),
        const Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'CONTACTO DIGITAL',
                style: TextStyle(
                  color: AppColors.textMuted,
                  fontSize: 10,
                  fontWeight: FontWeight.w700,
                  letterSpacing: 1.8,
                ),
              ),
              SizedBox(height: 2),
              Text(
                'O meu evento',
                style: TextStyle(fontSize: 17, fontWeight: FontWeight.w600),
              ),
            ],
          ),
        ),
        IconButton.filledTonal(
          tooltip: 'Atualizar dados',
          onPressed: _refreshing ? null : _load,
          style: IconButton.styleFrom(
            backgroundColor: AppColors.surfaceRaised,
            foregroundColor: AppColors.white,
          ),
          icon: _refreshing
              ? const SizedBox(
                  width: 18,
                  height: 18,
                  child: CircularProgressIndicator(strokeWidth: 2),
                )
              : const Icon(Icons.refresh_rounded),
        ),
        PopupMenuButton<String>(
          tooltip: 'Abrir menu',
          color: AppColors.surfaceRaised,
          iconColor: AppColors.textSoft,
          onSelected: (value) {
            if (value == 'logout') _logout();
          },
          itemBuilder: (_) => const [
            PopupMenuItem(
              value: 'logout',
              child: Row(
                children: [
                  Icon(Icons.logout_rounded, size: 20),
                  SizedBox(width: 10),
                  Text('Terminar sessão'),
                ],
              ),
            ),
          ],
        ),
      ],
    );
  }

  Widget _eventSelector() {
    final canSwitch = _events.length > 1;
    final eventTitle = _clientFacingLabel(_event?['title'], fallback: 'Evento');

    return Semantics(
      container: true,
      button: canSwitch,
      label: canSwitch
          ? 'Evento selecionado: $eventTitle. Toque para trocar.'
          : 'Evento selecionado: $eventTitle.',
      child: ExcludeSemantics(
        child: Material(
          color: Colors.transparent,
          child: InkWell(
            onTap: canSwitch ? _showEventPicker : null,
            borderRadius: BorderRadius.circular(26),
            child: Ink(
              padding: const EdgeInsets.fromLTRB(20, 18, 18, 18),
              decoration: BoxDecoration(
                color: AppColors.surface.withValues(alpha: 0.9),
                border: Border.all(color: AppColors.border),
                borderRadius: BorderRadius.circular(26),
              ),
              child: Row(
                children: [
                  Container(
                    width: 42,
                    height: 42,
                    decoration: BoxDecoration(
                      color: AppColors.lime.withValues(alpha: 0.1),
                      borderRadius: BorderRadius.circular(14),
                    ),
                    child: const Icon(
                      Icons.calendar_month_outlined,
                      color: AppColors.lime,
                    ),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          _eventDate(_event!, fallback: 'EVENTO DISPONÍVEL'),
                          style: const TextStyle(
                            color: AppColors.textMuted,
                            fontSize: 9,
                            fontWeight: FontWeight.w700,
                            letterSpacing: 1.4,
                          ),
                        ),
                        const SizedBox(height: 5),
                        Text(
                          _clientFacingLabel(_event?['title'],
                              fallback: 'Evento'),
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                            fontSize: 20,
                            height: 1.12,
                            fontWeight: FontWeight.w700,
                            letterSpacing: -0.35,
                          ),
                        ),
                      ],
                    ),
                  ),
                  if (canSwitch) ...[
                    const SizedBox(width: 10),
                    const Column(
                      children: [
                        Icon(
                          Icons.unfold_more_rounded,
                          size: 22,
                          color: AppColors.textSoft,
                        ),
                        SizedBox(height: 2),
                        Text(
                          'TROCAR',
                          style: TextStyle(
                            color: AppColors.textMuted,
                            fontSize: 8,
                            fontWeight: FontWeight.w700,
                            letterSpacing: 1,
                          ),
                        ),
                      ],
                    ),
                  ],
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  String _eventDate(
    Map<String, dynamic> event, {
    required String fallback,
  }) {
    final parsed = DateTime.tryParse(event['event_date'] as String? ?? '');
    if (parsed == null) return fallback;

    return DateFormat("d 'DE' MMMM 'DE' y", 'pt_PT')
        .format(parsed.toLocal())
        .toUpperCase();
  }

  Widget _syncStatus(String? lastSyncedAt) {
    final parsed = DateTime.tryParse(lastSyncedAt ?? '')?.toLocal();
    final formatted = parsed == null
        ? 'A aguardar a primeira sincronização'
        : 'Atualizado ${_relativeTime(parsed)}';

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 11),
      decoration: BoxDecoration(
        color: AppColors.surface.withValues(alpha: 0.82),
        border: Border.all(color: AppColors.border),
        borderRadius: BorderRadius.circular(18),
      ),
      child: Row(
        children: [
          Container(
            width: 8,
            height: 8,
            decoration: BoxDecoration(
              color: parsed == null ? AppColors.warning : AppColors.lime,
              shape: BoxShape.circle,
              boxShadow: [
                BoxShadow(
                  color: (parsed == null ? AppColors.warning : AppColors.lime)
                      .withValues(alpha: 0.4),
                  blurRadius: 8,
                ),
              ],
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              formatted,
              style: const TextStyle(color: AppColors.textSoft, fontSize: 12),
            ),
          ),
          const Icon(Icons.bolt_rounded, color: AppColors.lime, size: 17),
        ],
      ),
    );
  }

  String _relativeTime(DateTime dateTime) {
    final difference = DateTime.now().difference(dateTime);
    if (difference.isNegative || difference.inMinutes < 1) return 'agora';
    if (difference.inMinutes < 60) return 'há ${difference.inMinutes} min';
    if (difference.inHours < 24) {
      return 'às ${DateFormat('HH:mm').format(dateTime)}';
    }

    return 'em ${DateFormat('dd/MM, HH:mm').format(dateTime)}';
  }

  Widget _staleDataNotice() {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.warning.withValues(alpha: 0.1),
        border: Border.all(color: AppColors.warning.withValues(alpha: 0.35)),
        borderRadius: BorderRadius.circular(18),
      ),
      child: const Row(
        children: [
          Icon(Icons.info_outline_rounded, color: AppColors.warning, size: 20),
          SizedBox(width: 10),
          Expanded(
            child: Text(
              'Não foi possível atualizar agora. Mantivemos os últimos dados disponíveis.',
              style: TextStyle(color: AppColors.textSoft, fontSize: 12),
            ),
          ),
        ],
      ),
    );
  }

  Widget _overview(Map<String, dynamic> summary, {required bool isWide}) {
    final showTotal = _configurationItemVisible('blocks', 'overview');
    final showOperations = _configurationItemVisible('blocks', 'operations');
    if (!showTotal && !showOperations) return const SizedBox.shrink();

    final peak = _peakHour();
    final activeHours = _hourlySales
        .where((item) => ((item['total_sales'] as num?) ?? 0) > 0)
        .length;
    final totalSales = (summary['total_sales'] as num?)?.toDouble() ?? 0;
    // The mobile release can run against a server that has not yet returned
    // the expanded summary fields. Keep the dashboard usable during rollout.
    final totalQuantity = (summary['total_quantity'] as num?)?.toDouble() ?? 0;
    final productsCount = (summary['products_count'] as num?)?.toInt() ?? 0;
    final zonesCount = (summary['zones_count'] as num?)?.toInt() ?? 0;
    final averagePerHour = activeHours > 0 ? totalSales / activeHours : 0.0;
    final leadingZone = _map(summary['leading_zone']);
    final leadingZoneSales =
        (leadingZone['total_sales'] as num?)?.toDouble() ?? 0;
    final leaderShare = totalSales > 0 ? leadingZoneSales / totalSales : 0.0;
    final compact = !isWide;

    final financialCards = [
      _MetricData(
        label: _configurationItemLabel(
          'metrics',
          'average_ticket',
          'TICKET MÉDIO',
        ).toUpperCase(),
        value: _currency.format(summary['average_ticket']),
        caption: 'Por transação',
        icon: Icons.sell_outlined,
        accent: AppColors.lime,
        onTap: () => _showMetricDetails(
          title: 'Ticket médio',
          value: _currency.format(summary['average_ticket']),
          description: 'Valor médio faturado em cada transação.',
          icon: Icons.sell_outlined,
          accent: AppColors.lime,
          details: [
            MapEntry('Faturação', _currency.format(totalSales)),
            MapEntry(
              'Transações',
              _integer.format(summary['tickets_count'] ?? 0),
            ),
          ],
        ),
      ),
      _MetricData(
        label: 'TRANSAÇÕES',
        value: _integer.format(summary['tickets_count']),
        caption: totalQuantity > 0
            ? '${_formatQuantity(totalQuantity)} unidades registadas'
            : 'Vendas registadas no evento',
        icon: Icons.receipt_long_outlined,
        accent: AppColors.blueBright,
        onTap: () => _showMetricDetails(
          title: 'Transações',
          value: _integer.format(summary['tickets_count'] ?? 0),
          description: 'Movimentos de venda registados durante o evento.',
          icon: Icons.receipt_long_outlined,
          accent: AppColors.blueBright,
          details: [
            MapEntry('Faturação', _currency.format(totalSales)),
            MapEntry('Unidades', _formatQuantity(totalQuantity)),
          ],
        ),
      ),
    ];
    final operationalCards = [
      _MetricData(
        label: 'UNIDADES VENDIDAS',
        value: totalQuantity > 0 ? '${_formatQuantity(totalQuantity)} un' : '—',
        caption: productsCount > 0
            ? '$productsCount referências no evento'
            : 'Dados de produto indisponíveis',
        icon: Icons.inventory_2_outlined,
        accent: AppColors.lime,
        onTap: () => _showMetricDetails(
          title: 'Unidades vendidas',
          value: totalQuantity > 0 ? _formatQuantity(totalQuantity) : '—',
          description: 'Quantidade total de artigos vendidos no evento.',
          icon: Icons.inventory_2_outlined,
          accent: AppColors.lime,
          details: [
            MapEntry('Referências', _integer.format(productsCount)),
            MapEntry(
                'Transações', _integer.format(summary['tickets_count'] ?? 0)),
          ],
        ),
      ),
      _MetricData(
        label: 'PICO DE FATURAÇÃO',
        value: peak['hour_label']?.toString() ?? 'Sem dados',
        caption: _currency.format(peak['total_sales'] ?? 0),
        icon: Icons.schedule_outlined,
        accent: AppColors.blueBright,
        onTap: () => _showMetricDetails(
          title: 'Pico de faturação',
          value: peak['hour_label']?.toString() ?? 'Sem dados',
          description: 'Hora com maior faturação durante o evento.',
          icon: Icons.schedule_outlined,
          accent: AppColors.blueBright,
          details: [
            MapEntry('Faturação', _currency.format(peak['total_sales'] ?? 0)),
            MapEntry(
              'Transações',
              _integer.format(peak['tickets_count'] ?? 0),
            ),
          ],
        ),
      ),
      _MetricData(
        label: 'RITMO MÉDIO',
        value: _currency.format(averagePerHour),
        caption: '$activeHours horas com vendas',
        icon: Icons.show_chart_rounded,
        accent: AppColors.lime,
        onTap: () => _showMetricDetails(
          title: 'Ritmo médio',
          value: _currency.format(averagePerHour),
          description: 'Faturação média por cada hora com vendas.',
          icon: Icons.show_chart_rounded,
          accent: AppColors.lime,
          details: [
            MapEntry('Horas com vendas', _integer.format(activeHours)),
            MapEntry('Faturação', _currency.format(totalSales)),
          ],
        ),
      ),
      _MetricData(
        label: 'DEVICES',
        value: _integer.format(summary['stores_count'] ?? 0),
        caption: zonesCount > 0
            ? '$zonesCount zonas com vendas'
            : 'Devices com vendas no evento',
        icon: Icons.storefront_outlined,
        accent: AppColors.blueBright,
        onTap: () => _showMetricDetails(
          title: 'Devices',
          value: _integer.format(summary['stores_count'] ?? 0),
          description: 'Devices que registaram atividade durante o evento.',
          icon: Icons.storefront_outlined,
          accent: AppColors.blueBright,
          details: [
            MapEntry('Zonas com vendas', _integer.format(zonesCount)),
            MapEntry('Faturação', _currency.format(totalSales)),
          ],
        ),
      ),
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (showTotal) ...[
          _metricGroupLabel('Resumo financeiro', 'Leitura rápida do evento'),
          const SizedBox(height: 12),
          _salesHero(summary),
          const SizedBox(height: 12),
          _leaderCard(
            leadingZone,
            share: leaderShare,
            fallbackLabel: 'Zona líder indisponível',
          ),
          const SizedBox(height: 12),
          _metricGrid(
            financialCards,
            crossAxisCount: compact ? 2 : 2,
            childAspectRatio: compact ? 1.4 : 1.9,
            mainAxisExtent: compact ? 112 : null,
          ),
        ],
        if (showTotal && showOperations) const SizedBox(height: 24),
        if (showOperations) ...[
          _metricGroupLabel('Operação do evento', 'Volume, ritmo e cobertura'),
          const SizedBox(height: 8),
          _metricGrid(
            operationalCards,
            crossAxisCount: compact ? 2 : 4,
            childAspectRatio: compact ? 1.4 : 1.48,
            mainAxisExtent: compact ? 112 : null,
          ),
        ],
      ],
    );
  }

  Map<String, dynamic> _peakHour() {
    if (_hourlySales.isEmpty) return const {};

    return _hourlySales.reduce((current, item) {
      final currentSales = (current['total_sales'] as num?)?.toDouble() ?? 0;
      final itemSales = (item['total_sales'] as num?)?.toDouble() ?? 0;
      return itemSales > currentSales ? item : current;
    });
  }

  Widget _metricGroupLabel(String title, String subtitle) {
    return Row(
      children: [
        Expanded(
          child: Text(
            title.toUpperCase(),
            style: const TextStyle(
              color: AppColors.textMuted,
              fontSize: 10,
              fontWeight: FontWeight.w700,
              letterSpacing: 1.55,
            ),
          ),
        ),
        Text(
          subtitle,
          style: const TextStyle(color: AppColors.textMuted, fontSize: 10),
        ),
      ],
    );
  }

  Widget _salesHero(Map<String, dynamic> summary) {
    return Container(
      constraints: const BoxConstraints(minHeight: 166),
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [Color(0xFF07335A), Color(0xFF052847), Color(0xFF061F36)],
        ),
        border: Border.all(color: AppColors.blueBright.withValues(alpha: 0.58)),
        borderRadius: BorderRadius.circular(30),
        boxShadow: const [
          BoxShadow(
            color: Color(0x40000000),
            blurRadius: 36,
            offset: Offset(0, 20),
          ),
        ],
      ),
      child: Stack(
        children: [
          Positioned(
            top: -66,
            right: -54,
            child: Container(
              width: 170,
              height: 170,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: AppColors.lime.withValues(alpha: 0.08),
              ),
            ),
          ),
          Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Row(
                children: [
                  Text(
                    'TOTAL',
                    style: TextStyle(
                      color: AppColors.textMuted,
                      fontSize: 11,
                      fontWeight: FontWeight.w700,
                      letterSpacing: 1.7,
                    ),
                  ),
                  Spacer(),
                  Icon(Icons.trending_up_rounded,
                      color: AppColors.lime, size: 21),
                ],
              ),
              const SizedBox(height: 20),
              FittedBox(
                fit: BoxFit.scaleDown,
                alignment: Alignment.centerLeft,
                child: Text(
                  _currency.format(summary['total_sales']),
                  style: const TextStyle(
                    color: AppColors.lime,
                    fontSize: 44,
                    fontWeight: FontWeight.w700,
                    letterSpacing: -1.8,
                  ),
                ),
              ),
              const SizedBox(height: 14),
              ClipRRect(
                borderRadius: BorderRadius.circular(99),
                child: const LinearProgressIndicator(value: 1, minHeight: 4),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _metricGrid(
    List<_MetricData> cards, {
    required int crossAxisCount,
    required double childAspectRatio,
    double? mainAxisExtent,
  }) {
    return LayoutBuilder(
      builder: (context, constraints) {
        const crossAxisSpacing = 12.0;
        final itemWidth =
            (constraints.maxWidth - (crossAxisCount - 1) * crossAxisSpacing) /
                crossAxisCount;
        final itemHeight = mainAxisExtent ?? itemWidth / childAspectRatio;

        return Wrap(
          spacing: crossAxisSpacing,
          runSpacing: 8,
          children: cards
              .map(
                (card) => SizedBox(
                  width: itemWidth,
                  height: itemHeight,
                  child: _metricCard(card),
                ),
              )
              .toList(),
        );
      },
    );
  }

  Widget _leaderCard(
    Map<String, dynamic> zone, {
    required double share,
    required String fallbackLabel,
  }) {
    final label = _clientFacingLabel(zone['label'], fallback: fallbackLabel);
    final sales = (zone['total_sales'] as num?)?.toDouble() ?? 0;

    return Material(
      color: Colors.transparent,
      child: InkWell(
        borderRadius: BorderRadius.circular(22),
        onTap: () => _showMetricDetails(
          title: 'Zona líder',
          value: label,
          description: 'Zona com maior faturação na seleção atual.',
          icon: Icons.star_rounded,
          accent: AppColors.lime,
          details: [
            MapEntry('Faturação', _currency.format(sales)),
            MapEntry(
              'Peso no total',
              '${(share * 100).toStringAsFixed(1).replaceAll('.', ',')}%',
            ),
            if (zone['quantity_total'] != null)
              MapEntry(
                'Unidades vendidas',
                _formatQuantity(
                    (zone['quantity_total'] as num?)?.toDouble() ?? 0),
              ),
          ],
        ),
        child: Ink(
          padding: const EdgeInsets.all(18),
          decoration: BoxDecoration(
            gradient: LinearGradient(
              colors: [
                AppColors.lime.withValues(alpha: 0.12),
                AppColors.surface.withValues(alpha: 0.92),
              ],
            ),
            border: Border.all(color: AppColors.lime.withValues(alpha: 0.55)),
            borderRadius: BorderRadius.circular(22),
          ),
          child: Row(
            children: [
              Container(
                width: 42,
                height: 42,
                decoration: BoxDecoration(
                  color: AppColors.lime.withValues(alpha: 0.13),
                  borderRadius: BorderRadius.circular(13),
                ),
                child: const Icon(Icons.star_rounded, color: AppColors.lime),
              ),
              const SizedBox(width: 13),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'ZONA LÍDER',
                      style: TextStyle(
                        color: AppColors.textMuted,
                        fontSize: 9,
                        fontWeight: FontWeight.w700,
                        letterSpacing: 1.25,
                      ),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      label,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                          fontSize: 17, fontWeight: FontWeight.w700),
                    ),
                    const SizedBox(height: 7),
                    ClipRRect(
                      borderRadius: BorderRadius.circular(99),
                      child: LinearProgressIndicator(
                        value: share.clamp(0, 1),
                        minHeight: 4,
                        backgroundColor: AppColors.surfaceRaised,
                        color: AppColors.lime,
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 12),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(
                    _currency.format(sales),
                    style: const TextStyle(
                        fontSize: 14, fontWeight: FontWeight.w700),
                  ),
                  const SizedBox(height: 3),
                  Text(
                    '${(share * 100).toStringAsFixed(1).replaceAll('.', ',')}% do total',
                    style: const TextStyle(
                        color: AppColors.textMuted, fontSize: 10),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _metricCard(_MetricData data) {
    return Semantics(
      button: true,
      label: '${data.label}: ${data.value}',
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: data.onTap,
          borderRadius: BorderRadius.circular(24),
          child: Ink(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: AppColors.surface.withValues(alpha: 0.88),
              border: Border.all(color: AppColors.border),
              borderRadius: BorderRadius.circular(24),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        data.label,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          color: AppColors.textMuted,
                          fontSize: 9,
                          fontWeight: FontWeight.w700,
                          letterSpacing: 1.3,
                        ),
                      ),
                    ),
                    const SizedBox(width: 6),
                    Icon(data.icon, size: 18, color: data.accent),
                  ],
                ),
                const Spacer(),
                FittedBox(
                  fit: BoxFit.scaleDown,
                  alignment: Alignment.centerLeft,
                  child: Text(
                    data.value,
                    style: const TextStyle(
                        fontSize: 25, fontWeight: FontWeight.w600),
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  data.caption,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style:
                      const TextStyle(color: AppColors.textMuted, fontSize: 11),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Future<void> _showMetricDetails({
    required String title,
    required String value,
    required String description,
    required IconData icon,
    required Color accent,
    required List<MapEntry<String, String>> details,
  }) async {
    unawaited(HapticFeedback.selectionClick());
    if (!mounted) return;

    await showModalBottomSheet<void>(
      context: context,
      backgroundColor: Colors.transparent,
      isScrollControlled: true,
      builder: (context) => SafeArea(
        top: false,
        child: Container(
          padding: const EdgeInsets.fromLTRB(22, 12, 22, 28),
          decoration: const BoxDecoration(
            color: AppColors.navy,
            borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Center(
                child: Container(
                  width: 42,
                  height: 4,
                  decoration: BoxDecoration(
                    color: AppColors.border,
                    borderRadius: BorderRadius.circular(8),
                  ),
                ),
              ),
              const SizedBox(height: 22),
              Row(
                children: [
                  Container(
                    width: 44,
                    height: 44,
                    decoration: BoxDecoration(
                      color: accent.withValues(alpha: 0.12),
                      borderRadius: BorderRadius.circular(14),
                    ),
                    child: Icon(icon, color: accent),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          _clientFacingLabel(title).toUpperCase(),
                          style: const TextStyle(
                            color: AppColors.textMuted,
                            fontSize: 10,
                            fontWeight: FontWeight.w700,
                            letterSpacing: 1.4,
                          ),
                        ),
                        const SizedBox(height: 3),
                        Text(
                          _clientFacingLabel(value),
                          style: const TextStyle(
                            fontSize: 26,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 18),
              Text(
                _clientFacingLabel(description),
                style: const TextStyle(
                  color: AppColors.textSoft,
                  fontSize: 14,
                  height: 1.4,
                ),
              ),
              const SizedBox(height: 18),
              ...details.map(
                (detail) => Container(
                  padding: const EdgeInsets.symmetric(vertical: 13),
                  decoration: const BoxDecoration(
                    border: Border(bottom: BorderSide(color: AppColors.border)),
                  ),
                  child: Row(
                    children: [
                      Expanded(
                        child: Text(
                          _clientFacingLabel(detail.key),
                          style: const TextStyle(color: AppColors.textMuted),
                        ),
                      ),
                      const SizedBox(width: 14),
                      Text(
                        _clientFacingLabel(detail.value),
                        style: const TextStyle(fontWeight: FontWeight.w700),
                      ),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _sectionTitle(String title, String kicker) {
    return Row(
      children: [
        Expanded(
          child: Text(
            title,
            style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w600),
          ),
        ),
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
          decoration: BoxDecoration(
            border: Border.all(color: AppColors.lime.withValues(alpha: 0.5)),
            borderRadius: BorderRadius.circular(99),
          ),
          child: Text(
            kicker,
            style: const TextStyle(
              color: AppColors.lime,
              fontSize: 9,
              fontWeight: FontWeight.w700,
              letterSpacing: 1.2,
            ),
          ),
        ),
      ],
    );
  }

  Widget _hourlyChart() {
    if (_hourlySales.isEmpty) {
      return _emptyPanel('Ainda não existem vendas por hora.');
    }

    final maxSales = _hourlySales.fold<double>(
      0,
      (current, item) => math.max(
        current,
        (item['total_sales'] as num?)?.toDouble() ?? 0,
      ),
    );
    final peakHour = _hourlySales.fold<Map<String, dynamic>>(
      _hourlySales.first,
      (current, item) => ((item['total_sales'] as num?) ?? 0).toDouble() >
              ((current['total_sales'] as num?) ?? 0).toDouble()
          ? item
          : current,
    );
    final peakIndex = _hourlySales.indexOf(peakHour);
    final selectedIndex = _selectedHourIndex != null &&
            _selectedHourIndex! >= 0 &&
            _selectedHourIndex! < _hourlySales.length
        ? _selectedHourIndex!
        : peakIndex;
    final selectedHour = _hourlySales[selectedIndex];
    final selectedSales =
        (selectedHour['total_sales'] as num?)?.toDouble() ?? 0;
    final selectedTransactions =
        (selectedHour['tickets_count'] as num?)?.toInt() ?? 0;

    return Container(
      padding: const EdgeInsets.fromLTRB(18, 20, 18, 18),
      decoration: BoxDecoration(
        color: AppColors.surface.withValues(alpha: 0.9),
        borderRadius: BorderRadius.circular(26),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.bar_chart_rounded,
                  color: AppColors.blueBright, size: 21),
              const SizedBox(width: 9),
              const Expanded(
                child: Text(
                  'Faturação ao longo do dia',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600),
                ),
              ),
              const SizedBox(width: 8),
              Text(
                'Pico ${peakHour['hour_label']}',
                maxLines: 1,
                style: const TextStyle(color: AppColors.lime, fontSize: 11),
              ),
            ],
          ),
          const SizedBox(height: 14),
          AnimatedContainer(
            duration: const Duration(milliseconds: 180),
            padding: const EdgeInsets.symmetric(horizontal: 13, vertical: 10),
            decoration: BoxDecoration(
              color: AppColors.surfaceRaised.withValues(alpha: 0.82),
              borderRadius: BorderRadius.circular(14),
              border: Border.all(
                color: AppColors.lime.withValues(alpha: 0.32),
              ),
            ),
            child: Row(
              children: [
                Text(
                  selectedHour['hour_label']?.toString() ?? '—',
                  style: const TextStyle(
                    color: AppColors.lime,
                    fontWeight: FontWeight.w800,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Text.rich(
                    TextSpan(
                      children: [
                        TextSpan(
                          text: _currency.format(selectedSales),
                          style: const TextStyle(fontWeight: FontWeight.w700),
                        ),
                        TextSpan(
                          text: ' · ${_integer.format(selectedTransactions)}',
                          style: const TextStyle(
                            color: AppColors.textMuted,
                            fontSize: 11,
                          ),
                        ),
                      ],
                    ),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    textAlign: TextAlign.right,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 18),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: _hourlySales.indexed.map((entry) {
                final index = entry.$1;
                final item = entry.$2;
                final sales = (item['total_sales'] as num?)?.toDouble() ?? 0.0;
                final ratio = maxSales > 0 ? sales / maxSales : 0.0;
                final isSelected = index == selectedIndex;

                return Tooltip(
                  message:
                      '${item['hour_label']}\n${_currency.format(sales)} · ${_transactionLabel((item['tickets_count'] as num?)?.toInt() ?? 0)}',
                  child: GestureDetector(
                    behavior: HitTestBehavior.opaque,
                    onTap: () {
                      HapticFeedback.selectionClick();
                      setState(() => _selectedHourIndex = index);
                    },
                    child: SizedBox(
                      width: 48,
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.end,
                        children: [
                          Text(
                            _compactCurrency(sales),
                            style: TextStyle(
                              color: isSelected
                                  ? AppColors.lime
                                  : AppColors.textMuted,
                              fontSize: 8,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                          const SizedBox(height: 6),
                          AnimatedContainer(
                            duration: const Duration(milliseconds: 180),
                            width: isSelected ? 29 : 25,
                            height: 14 + (ratio * 105),
                            decoration: BoxDecoration(
                              gradient: LinearGradient(
                                begin: Alignment.topCenter,
                                end: Alignment.bottomCenter,
                                colors: isSelected
                                    ? [AppColors.lime, const Color(0xFFAFCB24)]
                                    : [
                                        AppColors.blueBright,
                                        AppColors.blue.withValues(alpha: 0.75),
                                      ],
                              ),
                              borderRadius: const BorderRadius.vertical(
                                top: Radius.circular(7),
                              ),
                            ),
                          ),
                          const SizedBox(height: 8),
                          Text(
                            item['hour_label'] as String? ?? '',
                            style: TextStyle(
                              color: isSelected
                                  ? AppColors.white
                                  : AppColors.textMuted,
                              fontSize: 9,
                              fontWeight: isSelected
                                  ? FontWeight.w700
                                  : FontWeight.w400,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                );
              }).toList(),
            ),
          ),
        ],
      ),
    );
  }

  String _compactCurrency(double value) {
    if (value >= 1000) {
      return '${(value / 1000).toStringAsFixed(value >= 10000 ? 0 : 1).replaceAll('.', ',')}k €';
    }

    return '${value.toStringAsFixed(0)} €';
  }

  String _transactionLabel(int count) {
    return '${_integer.format(count)} ${count == 1 ? 'transação' : 'transações'}';
  }

  Widget _productsPanel() {
    if (_topProducts.isEmpty) {
      return _emptyPanel('Ainda não existem produtos vendidos.');
    }

    return Container(
      decoration: BoxDecoration(
        color: AppColors.surface.withValues(alpha: 0.9),
        borderRadius: BorderRadius.circular(26),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        children: List.generate(_topProducts.length, (index) {
          final product = _topProducts[index];
          final sold = (product['sold_quantity'] as num?)?.toDouble() ?? 0;
          final offered =
              (product['offered_quantity'] as num?)?.toDouble() ?? 0;
          final sales = (product['total_sales'] as num?)?.toDouble() ?? 0;

          return Container(
            padding: const EdgeInsets.fromLTRB(16, 15, 16, 14),
            decoration: BoxDecoration(
              border: index == _topProducts.length - 1
                  ? null
                  : const Border(bottom: BorderSide(color: AppColors.border)),
            ),
            child: Row(
              children: [
                Container(
                  width: 34,
                  height: 34,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(
                    color:
                        index == 0 ? AppColors.lime : AppColors.surfaceRaised,
                    borderRadius: BorderRadius.circular(11),
                  ),
                  child: Icon(
                    index == 0 ? Icons.emoji_events_rounded : Icons.inventory_2,
                    size: 17,
                    color: index == 0 ? AppColors.navy : AppColors.textSoft,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        _clientFacingLabel(product['description'],
                            fallback: 'Sem descrição'),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          fontSize: 14,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      const SizedBox(height: 3),
                      Text(
                        '${_formatQuantity(sold)} vendidas${offered > 0 ? ' · ${_formatQuantity(offered)} oferecidas' : ''}',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          color: AppColors.textMuted,
                          fontSize: 10,
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 12),
                Text(
                  _currency.format(sales),
                  style: const TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ],
            ),
          );
        }),
      ),
    );
  }

  String _formatQuantity(double value) {
    if (value == value.roundToDouble()) return _integer.format(value);
    return value.toStringAsFixed(1).replaceAll('.', ',');
  }

  String _clientFacingLabel(Object? value, {String fallback = '—'}) {
    final label = value?.toString().trim() ?? '';
    if (label.isEmpty) return fallback;

    // Keep legacy technical payment labels out of client-facing screens.
    return label.replaceAll(
      RegExp(r'\bzt\b', caseSensitive: false),
      'Top up',
    );
  }

  Widget _storesPanel(Map<String, dynamic> summary) {
    if (_topStores.isEmpty) {
      return _emptyPanel('Ainda não existem vendas por device.');
    }

    final totalSales = (summary['total_sales'] as num?)?.toDouble() ?? 0;

    return Container(
      decoration: BoxDecoration(
        color: AppColors.surface.withValues(alpha: 0.9),
        borderRadius: BorderRadius.circular(26),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        children: List.generate(_topStores.length, (index) {
          final store = _topStores[index];
          final value = (store['total_sales'] as num?)?.toDouble() ?? 0;
          final share = totalSales > 0 ? value / totalSales : 0.0;

          return Container(
            padding: const EdgeInsets.fromLTRB(16, 15, 16, 14),
            decoration: BoxDecoration(
              border: index == _topStores.length - 1
                  ? null
                  : const Border(bottom: BorderSide(color: AppColors.border)),
            ),
            child: Row(
              children: [
                Container(
                  width: 34,
                  height: 34,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(
                    color:
                        index == 0 ? AppColors.lime : AppColors.surfaceRaised,
                    borderRadius: BorderRadius.circular(11),
                  ),
                  child: Text(
                    '${index + 1}',
                    style: TextStyle(
                      color: index == 0 ? AppColors.navy : AppColors.textSoft,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        _clientFacingLabel(store['store_name'],
                            fallback: 'Sem nome'),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          fontSize: 14,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      const SizedBox(height: 7),
                      ClipRRect(
                        borderRadius: BorderRadius.circular(99),
                        child: LinearProgressIndicator(
                          value: share.clamp(0, 1),
                          minHeight: 3,
                          backgroundColor: AppColors.surfaceRaised,
                          color: index == 0
                              ? AppColors.lime
                              : AppColors.blueBright,
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 14),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    Text(
                      _currency.format(value),
                      style: const TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      '${(share * 100).toStringAsFixed(1).replaceAll('.', ',')}%',
                      style: const TextStyle(
                        color: AppColors.textMuted,
                        fontSize: 10,
                      ),
                    ),
                  ],
                ),
              ],
            ),
          );
        }),
      ),
    );
  }

  Widget _emptyPanel(String message) {
    return Container(
      padding: const EdgeInsets.all(22),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: AppColors.border),
      ),
      child: Text(message, style: const TextStyle(color: AppColors.textMuted)),
    );
  }
}

class _MetricData {
  const _MetricData({
    required this.label,
    required this.value,
    required this.caption,
    required this.icon,
    required this.accent,
    required this.onTap,
  });

  final String label;
  final String value;
  final String caption;
  final IconData icon;
  final Color accent;
  final VoidCallback onTap;
}
