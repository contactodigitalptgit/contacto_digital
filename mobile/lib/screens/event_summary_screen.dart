import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/material.dart';
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
      final preserveFilters =
          selectedId == _selectedEventId && _filters.activeCount > 0;
      final responses = await Future.wait<dynamic>([
        preserveFilters
            ? widget.apiClient.fetchEventSection(
                selectedId,
                'dashboard',
                filters: _filters.toQuery(),
              )
            : widget.apiClient.fetchDashboard(selectedId),
        _fetchConfigurationSafely(selectedId),
      ]);
      final dashboard = (responses[0] as Map).cast<String, dynamic>();
      final configuration = responses[1] as Map<String, dynamic>?;

      if (!mounted || requestVersion != _requestVersion) return;
      String? sectionToLoad;
      setState(() {
        final eventChanged = _selectedEventId != selectedId;
        _events = events;
        _selectedEventId = selectedId;
        _event = selectedEvent;
        _summary = (dashboard['summary'] as Map).cast<String, dynamic>();
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
          _filters = const DashboardFilters();
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
                        event['title'] as String? ?? 'Evento',
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
                    _syncStatus(_summary?['last_synced_at'] as String?),
                    const SizedBox(height: 12),
                    _filterToolbar(),
                    if (_error != null) ...[
                      const SizedBox(height: 12),
                      _staleDataNotice(),
                    ],
                    const SizedBox(height: 18),
                    _overview(_summary!, isWide: isWide),
                    if (_sectionIsVisible('charts')) ...[
                      const SizedBox(height: 28),
                      _sectionTitle(
                        'Vendas por hora',
                        '${_hourlySales.length} HORAS',
                      ),
                      const SizedBox(height: 12),
                      _hourlyChart(),
                    ],
                    if (_sectionIsVisible('products')) ...[
                      const SizedBox(height: 28),
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
                      const SizedBox(height: 28),
                      _sectionTitle(
                        _configuredSectionHeading(
                          'zones',
                          'Desempenho por loja',
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
    final eventTitle = _event?['title'] as String? ?? 'Evento';

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
                          _event?['title'] as String? ?? 'Evento',
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

    if (!isWide) {
      return Column(
        children: [
          if (showTotal) _salesHero(summary),
          if (showTotal && showOperations) const SizedBox(height: 14),
          if (showOperations)
            _metricGrid(summary, crossAxisCount: 2, childAspectRatio: 1.18),
        ],
      );
    }

    if (!showTotal) {
      return _metricGrid(summary, crossAxisCount: 4, childAspectRatio: 1.5);
    }
    if (!showOperations) return _salesHero(summary);

    return SizedBox(
      height: 300,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Expanded(flex: 5, child: _salesHero(summary)),
          const SizedBox(width: 14),
          Expanded(
            flex: 6,
            child: _metricGrid(
              summary,
              crossAxisCount: 2,
              childAspectRatio: 2.15,
            ),
          ),
        ],
      ),
    );
  }

  Widget _salesHero(Map<String, dynamic> summary) {
    return Container(
      constraints: const BoxConstraints(minHeight: 230),
      padding: const EdgeInsets.all(24),
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
              Row(
                children: [
                  Text(
                    _configurationItemLabel(
                      'blocks',
                      'overview',
                      'FATURAÇÃO DO EVENTO',
                    ).toUpperCase(),
                    style: const TextStyle(
                      color: AppColors.textMuted,
                      fontSize: 11,
                      fontWeight: FontWeight.w700,
                      letterSpacing: 1.7,
                    ),
                  ),
                  const Spacer(),
                  const Icon(Icons.trending_up_rounded,
                      color: AppColors.lime, size: 21),
                ],
              ),
              const SizedBox(height: 34),
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
              const SizedBox(height: 9),
              const Text(
                'Total confirmado nas vendas sincronizadas',
                style: TextStyle(color: AppColors.textMuted, fontSize: 13),
              ),
              const SizedBox(height: 18),
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
    Map<String, dynamic> summary, {
    required int crossAxisCount,
    required double childAspectRatio,
  }) {
    final cards = [
      _MetricData(
        label: 'TRANSAÇÕES',
        value: _integer.format(summary['tickets_count']),
        caption: 'vendas registadas',
        icon: Icons.receipt_long_outlined,
        accent: AppColors.blueBright,
      ),
      _MetricData(
        label: _configurationItemLabel(
          'metrics',
          'average_ticket',
          'TICKET MÉDIO',
        ).toUpperCase(),
        value: _currency.format(summary['average_ticket']),
        caption: 'por transação',
        icon: Icons.payments_outlined,
        accent: AppColors.lime,
      ),
      _MetricData(
        label: 'LOJAS',
        value: _integer.format(summary['stores_count']),
        caption: 'com vendas',
        icon: Icons.storefront_outlined,
        accent: AppColors.success,
      ),
      _MetricData(
        label: _configurationItemLabel(
          'metrics',
          'devices',
          'MÁQUINAS',
        ).toUpperCase(),
        value: _integer.format(summary['machines_count']),
        caption: 'na sincronização',
        icon: Icons.point_of_sale_outlined,
        accent: AppColors.blueBright,
      ),
    ];

    return GridView.builder(
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      itemCount: cards.length,
      gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: crossAxisCount,
        crossAxisSpacing: 12,
        mainAxisSpacing: 12,
        childAspectRatio: childAspectRatio,
      ),
      itemBuilder: (context, index) => _metricCard(cards[index]),
    );
  }

  Widget _metricCard(_MetricData data) {
    return Container(
      padding: const EdgeInsets.all(17),
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
              style: const TextStyle(fontSize: 25, fontWeight: FontWeight.w600),
            ),
          ),
          const SizedBox(height: 3),
          Text(
            data.caption,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(color: AppColors.textMuted, fontSize: 11),
          ),
        ],
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
          const SizedBox(height: 22),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: _hourlySales.map((item) {
                final sales = (item['total_sales'] as num?)?.toDouble() ?? 0.0;
                final ratio = maxSales > 0 ? sales / maxSales : 0.0;
                final isPeak = identical(item, peakHour);

                return Tooltip(
                  message:
                      '${item['hour_label']}\n${_currency.format(sales)} · ${_transactionLabel((item['tickets_count'] as num?)?.toInt() ?? 0)}',
                  child: SizedBox(
                    width: 48,
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.end,
                      children: [
                        Text(
                          _compactCurrency(sales),
                          style: TextStyle(
                            color:
                                isPeak ? AppColors.lime : AppColors.textMuted,
                            fontSize: 8,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                        const SizedBox(height: 6),
                        Container(
                          width: 25,
                          height: 14 + (ratio * 105),
                          decoration: BoxDecoration(
                            gradient: LinearGradient(
                              begin: Alignment.topCenter,
                              end: Alignment.bottomCenter,
                              colors: isPeak
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
                          style: const TextStyle(
                            color: AppColors.textMuted,
                            fontSize: 9,
                          ),
                        ),
                      ],
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
                        product['description'] as String? ?? 'Sem descrição',
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

  Widget _storesPanel(Map<String, dynamic> summary) {
    if (_topStores.isEmpty) {
      return _emptyPanel('Ainda não existem vendas por loja.');
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
                        store['store_name'] as String? ?? 'Sem nome',
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
  });

  final String label;
  final String value;
  final String caption;
  final IconData icon;
  final Color accent;
}
