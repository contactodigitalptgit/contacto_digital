// The extension is a private part of the owning State implementation. Flutter's
// protected-member lint cannot infer that both files belong to the same widget.
// ignore_for_file: invalid_use_of_protected_member, deprecated_member_use

part of 'event_summary_screen.dart';

class DashboardFilters {
  const DashboardFilters({
    this.zones = const [],
    this.store,
    this.product,
    this.dateFrom,
    this.dateTo,
    this.hourFrom,
    this.hourTo,
  });

  final List<String> zones;
  final String? store;
  final String? product;
  final DateTime? dateFrom;
  final DateTime? dateTo;
  final int? hourFrom;
  final int? hourTo;

  int get activeCount =>
      zones.length +
      (store == null ? 0 : 1) +
      (product == null ? 0 : 1) +
      (dateFrom == null ? 0 : 1) +
      (dateTo == null ? 0 : 1) +
      (hourFrom == null ? 0 : 1) +
      (hourTo == null ? 0 : 1);

  Map<String, dynamic> toQuery() => {
        'bar_groups': zones,
        if (store != null) 'store': store,
        if (product != null) 'product': product,
        if (dateFrom != null) 'date_from': _date(dateFrom!),
        if (dateTo != null) 'date_to': _date(dateTo!),
        if (hourFrom != null) 'hour_from': hourFrom,
        if (hourTo != null) 'hour_to': hourTo,
      };

  String get signature => toQuery().toString();

  static String _date(DateTime value) =>
      '${value.year.toString().padLeft(4, '0')}-${value.month.toString().padLeft(2, '0')}-${value.day.toString().padLeft(2, '0')}';
}

extension _EventPortalSections on _EventSummaryScreenState {
  static const _sectionLabels = {
    'summary': 'Resumo',
    'products': 'Produtos',
    'payments': 'Pagamentos',
    'zones': 'Zonas',
    'performance': 'Performance',
    'comparison': 'Comparar edições',
    'more': 'Mais',
  };

  String _initialSection(Map<String, dynamic>? configuration) {
    final sections = configuration?['sections'];
    if (sections is List) {
      for (final item in sections.whereType<Map>()) {
        if (item['visible'] == false || item['available'] == false) continue;
        final section = _apiSectionForConfiguration(item['key']?.toString());
        if (section != null) return section;
      }
    }
    return 'summary';
  }

  Widget _portalNavigation() {
    if (_loading || _event == null) return const SizedBox.shrink();

    final sections = _primarySections;
    final selected = sections.contains(_activeSection)
        ? sections.indexOf(_activeSection)
        : sections.length - 1;

    return Container(
      decoration: const BoxDecoration(
        color: Color(0xF503182B),
        border: Border(top: BorderSide(color: AppColors.border)),
      ),
      child: SafeArea(
        top: false,
        child: NavigationBar(
          height: 72,
          selectedIndex: selected,
          backgroundColor: Colors.transparent,
          indicatorColor: AppColors.lime,
          labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
          onDestinationSelected: (index) => _openSection(sections[index]),
          destinations: sections
              .map(
                (section) => NavigationDestination(
                  icon: Icon(_sectionIcon(section)),
                  selectedIcon: Icon(
                    _sectionIcon(section),
                    color: AppColors.navy,
                  ),
                  label: _configuredSectionLabel(section),
                ),
              )
              .toList(),
        ),
      ),
    );
  }

  List<String> get _primarySections {
    final configured = _configuration?['sections'];
    if (configured is List) {
      final sections = configured
          .whereType<Map>()
          .where(
              (item) => item['visible'] != false && item['available'] != false)
          .map((item) => _apiSectionForConfiguration(item['key']?.toString()))
          .whereType<String>()
          .toList();
      if (sections.isNotEmpty) return [...sections, 'more'];
    }

    return const ['summary', 'products', 'payments', 'zones', 'more'];
  }

  String? _apiSectionForConfiguration(String? key) => switch (key) {
        'summary' => 'summary',
        'products' => 'products',
        'reconciliation' => 'payments',
        'zones' => 'zones',
        _ => null,
      };

  String _configurationKeyForSection(String section) => switch (section) {
        'payments' => 'reconciliation',
        'performance' => 'highlights',
        _ => section,
      };

  String _configuredSectionLabel(String section) {
    if (section == 'more') return _sectionLabels[section]!;
    final sections = _configuration?['sections'];
    if (sections is List) {
      final key = _configurationKeyForSection(section);
      for (final item in sections.whereType<Map>()) {
        if (item['key'] == key && item['label'] is String) {
          final label = (item['label'] as String).trim();
          if (label.isNotEmpty) return label;
        }
      }
    }
    return _sectionLabels[section]!;
  }

  String _configuredSectionHeading(String section, String fallback) {
    return _configuration?['customized'] == true
        ? _configuredSectionLabel(section)
        : fallback;
  }

  bool _sectionIsVisible(String configurationKey) {
    final sections = _configuration?['sections'];
    if (sections is! List) return true;

    for (final item in sections.whereType<Map>()) {
      if (item['key'] == configurationKey) {
        return item['visible'] != false && item['available'] != false;
      }
    }
    return true;
  }

  bool _configurationItemVisible(String group, String key) {
    final items = _configuration?[group];
    if (items is! List) return true;
    for (final item in items.whereType<Map>()) {
      if (item['key'] == key) {
        return item['visible'] != false && item['available'] != false;
      }
    }
    return true;
  }

  String _configurationItemLabel(
    String group,
    String key,
    String fallback,
  ) {
    final items = _configuration?[group];
    if (items is List) {
      for (final item in items.whereType<Map>()) {
        if (item['key'] == key && item['label'] is String) {
          final label = (item['label'] as String).trim();
          if (label.isNotEmpty) return label;
        }
      }
    }
    return fallback;
  }

  IconData _sectionIcon(String section) => switch (section) {
        'summary' => Icons.space_dashboard_outlined,
        'products' => Icons.inventory_2_outlined,
        'payments' => Icons.account_balance_wallet_outlined,
        'zones' => Icons.layers_outlined,
        'performance' => Icons.query_stats_rounded,
        'comparison' => Icons.compare_arrows_rounded,
        _ => Icons.grid_view_rounded,
      };

  Future<void> _openSection(String section) async {
    setState(() {
      _activeSection = section;
      _sectionError = null;
      _sectionSearch = '';
      _sectionSearchController.clear();
    });
    if (section != 'summary' && section != 'more') {
      await _loadSection(section);
    }
  }

  Future<void> _loadSection(String section, {bool force = false}) async {
    final eventId = _selectedEventId;
    if (eventId == null || section == 'summary' || section == 'more') return;

    final cacheKey = '$eventId:$section:${_filters.signature}';
    if (!force && _sectionData.containsKey(cacheKey)) return;

    setState(() {
      _sectionLoading = true;
      _sectionError = null;
    });

    try {
      _filterOptions ??= await widget.apiClient.fetchFilterOptions(eventId);
      final data = await widget.apiClient.fetchEventSection(
        eventId,
        section,
        filters: _filters.toQuery(),
      );
      if (!mounted) return;
      setState(() {
        _sectionData[cacheKey] = data;
        _sectionLoading = false;
      });
    } on ApiException catch (exception) {
      if (!mounted) return;
      if (exception.statusCode == 401 || exception.statusCode == 403) {
        _goToLogin();
        return;
      }
      setState(() {
        _sectionLoading = false;
        _sectionError = exception.message;
      });
    }
  }

  Map<String, dynamic>? get _activeSectionData {
    final eventId = _selectedEventId;
    if (eventId == null) return null;
    return _sectionData['$eventId:$_activeSection:${_filters.signature}'];
  }

  Widget _featureBody() {
    return LayoutBuilder(
      builder: (context, constraints) {
        final wide = constraints.maxWidth >= 760;
        final padding = wide ? 32.0 : 20.0;

        return ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: EdgeInsets.fromLTRB(padding, 18, padding, 122),
          children: [
            Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 1180),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    _dashboardHeader(),
                    const SizedBox(height: 20),
                    _eventSelector(),
                    const SizedBox(height: 22),
                    _featureHeading(),
                    if (_activeSection != 'more') ...[
                      const SizedBox(height: 14),
                      _filterToolbar(),
                      const SizedBox(height: 18),
                    ],
                    if (_activeSection == 'more')
                      _morePage()
                    else if (_sectionLoading && _activeSectionData == null)
                      _sectionLoadingPanel()
                    else if (_sectionError != null &&
                        _activeSectionData == null)
                      _sectionErrorPanel()
                    else
                      _sectionContent(_activeSectionData ?? const {}),
                  ],
                ),
              ),
            ),
          ],
        );
      },
    );
  }

  Widget _featureHeading() {
    return Row(
      children: [
        Container(
          width: 45,
          height: 45,
          decoration: BoxDecoration(
            color: AppColors.lime.withValues(alpha: 0.1),
            borderRadius: BorderRadius.circular(15),
          ),
          child: Icon(_sectionIcon(_activeSection), color: AppColors.lime),
        ),
        const SizedBox(width: 13),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text(
                'ANÁLISE DO EVENTO',
                style: TextStyle(
                  color: AppColors.textMuted,
                  fontSize: 9,
                  fontWeight: FontWeight.w700,
                  letterSpacing: 1.6,
                ),
              ),
              const SizedBox(height: 3),
              Text(
                _configuredSectionLabel(_activeSection),
                style: const TextStyle(
                  fontSize: 25,
                  fontWeight: FontWeight.w700,
                  letterSpacing: -0.5,
                ),
              ),
            ],
          ),
        ),
        if (_activeSection != 'more')
          IconButton.filledTonal(
            tooltip: 'Atualizar esta página',
            onPressed: _sectionLoading
                ? null
                : () => _loadSection(_activeSection, force: true),
            icon: _sectionLoading
                ? const SizedBox(
                    width: 17,
                    height: 17,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Icon(Icons.refresh_rounded),
          ),
      ],
    );
  }

  Widget _filterToolbar() {
    final labels = <String>[
      ..._filters.zones,
      if (_filters.store != null) _filters.store!,
      if (_filters.product != null) 'Produto selecionado',
      if (_filters.dateFrom != null || _filters.dateTo != null) 'Período',
      if (_filters.hourFrom != null || _filters.hourTo != null) 'Horário',
    ];

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: AppColors.surface.withValues(alpha: 0.88),
        border: Border.all(color: AppColors.border),
        borderRadius: BorderRadius.circular(22),
      ),
      child: Row(
        children: [
          Expanded(
            child: labels.isEmpty
                ? const Text(
                    'Todo o evento',
                    style: TextStyle(color: AppColors.textMuted),
                  )
                : SingleChildScrollView(
                    scrollDirection: Axis.horizontal,
                    child: Row(
                      children: labels
                          .map(
                            (label) => Padding(
                              padding: const EdgeInsets.only(right: 7),
                              child: Chip(
                                label: Text(label),
                                visualDensity: VisualDensity.compact,
                                side: const BorderSide(color: AppColors.border),
                                backgroundColor: AppColors.surfaceRaised,
                              ),
                            ),
                          )
                          .toList(),
                    ),
                  ),
          ),
          const SizedBox(width: 8),
          Badge(
            isLabelVisible: _filters.activeCount > 0,
            label: Text('${_filters.activeCount}'),
            backgroundColor: AppColors.lime,
            textColor: AppColors.navy,
            child: IconButton.filled(
              tooltip: 'Ajustar filtros',
              onPressed: _showFilters,
              style: IconButton.styleFrom(
                backgroundColor: AppColors.blue,
                foregroundColor: AppColors.white,
              ),
              icon: const Icon(Icons.tune_rounded),
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _showFilters() async {
    final eventId = _selectedEventId;
    if (eventId == null) return;

    if (_filterOptions == null) {
      setState(() => _sectionLoading = true);
      try {
        _filterOptions = await widget.apiClient.fetchFilterOptions(eventId);
      } on ApiException catch (exception) {
        if (!mounted) return;
        setState(() {
          _sectionLoading = false;
          _sectionError = exception.message;
        });
        return;
      }
      if (mounted) setState(() => _sectionLoading = false);
    }
    if (!mounted) return;

    final options = _filterOptions!;
    final zoneOptions = _maps(options['zones']);
    final storeOptions = _maps(options['stores']);
    final productOptions = _maps(options['products']);
    var zones = [..._filters.zones];
    var store = _filters.store;
    var product = _filters.product;
    var dateFrom = _filters.dateFrom;
    var dateTo = _filters.dateTo;
    var hourFrom = _filters.hourFrom;
    var hourTo = _filters.hourTo;

    final selected = await showModalBottomSheet<DashboardFilters>(
      context: context,
      isScrollControlled: true,
      backgroundColor: AppColors.surface,
      showDragHandle: true,
      useSafeArea: true,
      builder: (context) => StatefulBuilder(
        builder: (context, setModalState) => Padding(
          padding: EdgeInsets.fromLTRB(
            20,
            2,
            20,
            20 + MediaQuery.viewInsetsOf(context).bottom,
          ),
          child: SingleChildScrollView(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const Text(
                  'Ajustar filtros',
                  style: TextStyle(fontSize: 24, fontWeight: FontWeight.w700),
                ),
                const SizedBox(height: 5),
                const Text(
                  'Pode combinar várias zonas, período, horário e device.',
                  style: TextStyle(color: AppColors.textMuted),
                ),
                const SizedBox(height: 20),
                const _PortalFieldLabel('ZONAS · SELEÇÃO MÚLTIPLA'),
                const SizedBox(height: 9),
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: zoneOptions.map((zone) {
                    final value = zone['value'] as String;
                    return FilterChip(
                      selected: zones.contains(value),
                      label: Text(zone['label'] as String),
                      onSelected: (checked) => setModalState(() {
                        checked ? zones.add(value) : zones.remove(value);
                      }),
                      selectedColor: AppColors.lime,
                      checkmarkColor: AppColors.navy,
                      labelStyle: TextStyle(
                        color: zones.contains(value)
                            ? AppColors.navy
                            : AppColors.textSoft,
                      ),
                      side: const BorderSide(color: AppColors.border),
                    );
                  }).toList(),
                ),
                const SizedBox(height: 20),
                DropdownButtonFormField<String?>(
                  value: store,
                  decoration: const InputDecoration(labelText: 'Device / loja'),
                  items: [
                    const DropdownMenuItem(value: null, child: Text('Todos')),
                    ...storeOptions.map(
                      (item) => DropdownMenuItem(
                        value: item['value'] as String,
                        child: Text(
                          item['label'] as String,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                    ),
                  ],
                  onChanged: (value) => setModalState(() => store = value),
                ),
                if (_activeSection == 'products' ||
                    _activeSection == 'performance') ...[
                  const SizedBox(height: 12),
                  DropdownButtonFormField<String?>(
                    value: product,
                    decoration: const InputDecoration(labelText: 'Produto'),
                    items: [
                      const DropdownMenuItem(value: null, child: Text('Todos')),
                      ...productOptions.map(
                        (item) => DropdownMenuItem(
                          value: item['value'] as String,
                          child: Text(
                            item['label'] as String,
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                      ),
                    ],
                    onChanged: (value) => setModalState(() => product = value),
                  ),
                ],
                const SizedBox(height: 20),
                const _PortalFieldLabel('PERÍODO'),
                const SizedBox(height: 9),
                Row(
                  children: [
                    Expanded(
                      child: _DateFilterButton(
                        label: 'Início',
                        value: dateFrom,
                        onTap: () async {
                          final picked = await showDatePicker(
                            context: context,
                            firstDate: DateTime(2020),
                            lastDate: DateTime(2035),
                            initialDate: dateFrom ?? DateTime.now(),
                          );
                          if (picked != null) {
                            setModalState(() => dateFrom = picked);
                          }
                        },
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: _DateFilterButton(
                        label: 'Fim',
                        value: dateTo,
                        onTap: () async {
                          final picked = await showDatePicker(
                            context: context,
                            firstDate: dateFrom ?? DateTime(2020),
                            lastDate: DateTime(2035),
                            initialDate: dateTo ?? dateFrom ?? DateTime.now(),
                          );
                          if (picked != null) {
                            setModalState(() => dateTo = picked);
                          }
                        },
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 20),
                const _PortalFieldLabel('HORÁRIO'),
                const SizedBox(height: 9),
                Row(
                  children: [
                    Expanded(
                      child: _HourFilter(
                        label: 'Início',
                        value: hourFrom,
                        onChanged: (value) =>
                            setModalState(() => hourFrom = value),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: _HourFilter(
                        label: 'Fim',
                        value: hourTo,
                        onChanged: (value) =>
                            setModalState(() => hourTo = value),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 24),
                Row(
                  children: [
                    Expanded(
                      child: OutlinedButton(
                        onPressed: () => Navigator.of(context).pop(
                          const DashboardFilters(),
                        ),
                        child: const Text('Limpar'),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      flex: 2,
                      child: FilledButton(
                        onPressed: () => Navigator.of(context).pop(
                          DashboardFilters(
                            zones: zones,
                            store: store,
                            product: product,
                            dateFrom: dateFrom,
                            dateTo: dateTo,
                            hourFrom: hourFrom,
                            hourTo: hourTo,
                          ),
                        ),
                        child: const Text('Aplicar filtros'),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );

    if (selected != null && selected.signature != _filters.signature) {
      setState(() {
        _filters = selected;
        _sectionSearch = '';
        _sectionSearchController.clear();
      });
      if (_activeSection == 'summary') {
        await _loadFilteredDashboard();
      } else {
        await _loadSection(_activeSection);
      }
    }
  }

  Future<void> _loadFilteredDashboard() async {
    final eventId = _selectedEventId;
    if (eventId == null) return;
    setState(() {
      _refreshing = true;
      _error = null;
    });
    try {
      final dashboard = await widget.apiClient.fetchEventSection(
        eventId,
        'dashboard',
        filters: _filters.toQuery(),
      );
      if (!mounted) return;
      setState(() {
        _summary = _map(dashboard['summary']);
        _topStores = _maps(dashboard['top_stores']);
        _topProducts = _maps(dashboard['top_products']);
        _hourlySales = _maps(dashboard['hourly_sales']);
        _refreshing = false;
      });
    } on ApiException catch (exception) {
      if (!mounted) return;
      setState(() {
        _error = exception.message;
        _refreshing = false;
      });
    }
  }

  Widget _sectionContent(Map<String, dynamic> data) => switch (_activeSection) {
        'products' => _productsPage(data),
        'payments' => _paymentsPage(data),
        'zones' => _zonesPage(data),
        'performance' => _performancePage(data),
        'comparison' => _comparisonPage(data),
        _ => const SizedBox.shrink(),
      };

  Widget _productsPage(Map<String, dynamic> data) {
    final summary = _map(data['summary']);
    final items = _filteredItems(_maps(data['items']), ['description']);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _metricWrap([
          _PortalMetric('VENDIDO', _quantity(summary['sold_quantity']),
              'unidades pagas', Icons.shopping_bag_outlined),
          _PortalMetric(
              'OFERECIDO',
              _quantity(summary['offered_quantity']),
              '${_decimal(summary['offer_share'])}% do servido',
              Icons.card_giftcard_rounded),
          _PortalMetric('TOTAL SERVIDO', _quantity(summary['served_quantity']),
              'vendido + oferecido', Icons.inventory_2_outlined),
          _PortalMetric('FATURAÇÃO', _money(summary['total_sales']),
              '${summary['products_count'] ?? 0} produtos', Icons.euro_rounded,
              accent: true),
        ]),
        const SizedBox(height: 18),
        _searchField('Pesquisar produto'),
        const SizedBox(height: 12),
        _rankedPanel(
          title: 'RANKING DE PRODUTOS',
          empty: 'Não existem produtos neste filtro.',
          items: items,
          titleOf: (item) => item['description'] as String? ?? 'Produto',
          subtitleOf: (item) =>
              '${_quantity(item['sold_quantity'])} vendidas · ${_quantity(item['offered_quantity'])} oferecidas',
          valueOf: (item) => _money(item['total_sales']),
        ),
      ],
    );
  }

  Widget _paymentsPage(Map<String, dynamic> data) {
    final summary = _map(data['summary']);
    final reconciliation = _map(data['reconciliation']);
    final totals = _map(reconciliation['totals']);
    final items = _filteredItems(
      _maps(reconciliation['items']),
      ['store_name', 'store_code'],
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (summary['available'] == false)
          _noticePanel(
            'Pagamentos ainda indisponíveis',
            'A sincronização deste evento ainda não guardou documentos de pagamento.',
          ),
        _metricWrap([
          _PortalMetric('MULTIBANCO', _money(summary['multibanco']),
              'pagamentos com cartão', Icons.credit_card_rounded,
              accent: true),
          _PortalMetric('DINHEIRO', _money(summary['cash']),
              'pagamentos em numerário', Icons.payments_outlined),
          _PortalMetric('ZT · CARD', _money(summary['zticket']),
              'consumo cashless', Icons.nfc_rounded),
          _PortalMetric('OUTROS', _money(summary['other']), 'outros pagamentos',
              Icons.more_horiz_rounded),
        ]),
        const SizedBox(height: 18),
        _heroValue(
          'TOTAL DAS VENDAS',
          _money(summary['sales_total']),
          '${summary['documents_count'] ?? 0} documentos de venda',
        ),
        if ((summary['top_up_loaded'] as num?)?.toDouble() != 0) ...[
          const SizedBox(height: 18),
          _metricWrap([
            _PortalMetric('CARREGADO', _money(summary['top_up_loaded']),
                'carregamentos ZT', Icons.add_card_rounded),
            _PortalMetric('CONSUMIDO', _money(summary['top_up_spent']),
                'saldo utilizado', Icons.shopping_cart_checkout_rounded),
            _PortalMetric('REMANESCENTE', _money(summary['top_up_remaining']),
                'saldo não utilizado', Icons.savings_outlined,
                accent: true),
          ]),
        ],
        const SizedBox(height: 24),
        _sectionTitle(
            'Conciliação por device', _differenceLabel(totals['difference'])),
        const SizedBox(height: 10),
        _searchField('Pesquisar device'),
        const SizedBox(height: 12),
        _rankedPanel(
          title: 'PAGAMENTOS × VENDAS',
          empty: 'Não existem devices neste filtro.',
          items: items,
          titleOf: (item) => item['store_name'] as String? ?? 'Sem device',
          subtitleOf: (item) =>
              'Pagamentos ${_money(item['payments_total'])} · Vendas ${_money(item['sales_total'])}',
          valueOf: (item) => _differenceLabel(item['difference']),
          valueColor: (item) =>
              ((item['difference'] as num?)?.abs() ?? 0) < 0.01
                  ? AppColors.success
                  : AppColors.warning,
        ),
      ],
    );
  }

  Widget _zonesPage(Map<String, dynamic> data) {
    final summary = _map(data['summary']);
    final zones = _filteredItems(_maps(data['items']), ['label']);
    final leader = _map(summary['leading_zone']);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _heroValue(
          'FATURAÇÃO DA SELEÇÃO',
          _money(summary['total_sales']),
          '${summary['zones_count'] ?? 0} zonas · ${summary['devices_count'] ?? 0} devices',
        ),
        const SizedBox(height: 18),
        _metricWrap([
          _PortalMetric('ZONA LÍDER', leader['label']?.toString() ?? '—',
              _money(leader['total_sales']), Icons.emoji_events_outlined,
              accent: true),
          _PortalMetric('TRANSAÇÕES', _quantity(summary['tickets_count']),
              'documentos de venda', Icons.receipt_long_outlined),
          _PortalMetric('DEVICES', _quantity(summary['devices_count']),
              'equipamentos com dados', Icons.point_of_sale_outlined),
        ]),
        const SizedBox(height: 22),
        _searchField('Pesquisar zona'),
        const SizedBox(height: 12),
        ...zones.map(_zoneCard),
      ],
    );
  }

  Widget _zoneCard(Map<String, dynamic> zone) {
    final devices = _maps(zone['items']);
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      decoration: BoxDecoration(
        color: AppColors.surface.withValues(alpha: 0.92),
        borderRadius: BorderRadius.circular(22),
        border: Border.all(color: AppColors.border),
      ),
      child: ExpansionTile(
        shape: const Border(),
        collapsedShape: const Border(),
        iconColor: AppColors.lime,
        collapsedIconColor: AppColors.textMuted,
        title: Text(
          zone['label'] as String? ?? 'Zona',
          style: const TextStyle(fontWeight: FontWeight.w700),
        ),
        subtitle: Text(
          '${_decimal(zone['share'])}% do evento · ${zone['devices_count']} devices',
          style: const TextStyle(color: AppColors.textMuted),
        ),
        trailing: Text(
          _money(zone['total_sales']),
          style: const TextStyle(
              color: AppColors.lime, fontWeight: FontWeight.w700),
        ),
        children: devices
            .map(
              (device) => ListTile(
                dense: true,
                leading: const Icon(Icons.point_of_sale_outlined, size: 18),
                title: Text(device['store_name'] as String? ?? 'Device'),
                subtitle: Text('${device['tickets_count']} transações'),
                trailing: Text(_money(device['total_sales'])),
              ),
            )
            .toList(),
      ),
    );
  }

  Widget _performancePage(Map<String, dynamic> data) {
    final summary = _map(data['summary']);
    final best = _map(summary['best_product']);
    final served = _map(summary['most_served_product']);
    final peak = _map(summary['peak_hour']);
    final leader = _map(summary['leading_zone']);
    final devices = _filteredItems(
      _maps(data['devices']),
      ['store_name', 'zone'],
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _metricWrap([
          _PortalMetric(
              'MELHOR PRODUTO',
              best['description']?.toString() ?? '—',
              _money(best['total_sales']),
              Icons.workspace_premium_outlined,
              accent: true),
          _PortalMetric(
              'MAIS SERVIDO',
              served['description']?.toString() ?? '—',
              '${_quantity(served['served_quantity'])} unidades',
              Icons.inventory_2_outlined),
          _PortalMetric('PICO HORÁRIO', peak['hour_label']?.toString() ?? '—',
              _money(peak['total_sales']), Icons.schedule_rounded),
          _PortalMetric('ZONA LÍDER', leader['label']?.toString() ?? '—',
              _money(leader['total_sales']), Icons.layers_outlined),
        ]),
        const SizedBox(height: 22),
        _searchField('Pesquisar no ranking de devices'),
        const SizedBox(height: 12),
        _rankedPanel(
          title: 'DESEMPENHO DOS DEVICES',
          empty: 'Não existem devices neste filtro.',
          items: devices,
          titleOf: (item) => item['store_name'] as String? ?? 'Device',
          subtitleOf: (item) =>
              '${item['zone']} · ${item['tickets_count']} transações',
          valueOf: (item) => _money(item['total_sales']),
        ),
      ],
    );
  }

  Widget _comparisonPage(Map<String, dynamic> data) {
    if (data['available'] == false) {
      return _noticePanel(
        'Comparação indisponível',
        data['message'] as String? ??
            'Ainda não existe outra edição sincronizada.',
      );
    }

    final current = _map(data['current']);
    final previous = _map(data['previous']);
    final metrics = _maps(data['metrics']);
    final payments = _maps(data['payments']);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Container(
          padding: const EdgeInsets.all(22),
          decoration: BoxDecoration(
            color: AppColors.surface,
            borderRadius: BorderRadius.circular(26),
            border: Border.all(color: AppColors.border),
          ),
          child: Column(
            children: [
              _comparisonEvent(current, current: true),
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 13),
                child: Icon(Icons.swap_vert_rounded, color: AppColors.lime),
              ),
              _comparisonEvent(previous, current: false),
            ],
          ),
        ),
        const SizedBox(height: 18),
        _rankedPanel(
          title: 'INDICADORES OPERACIONAIS',
          empty: 'Sem indicadores.',
          items: metrics,
          titleOf: (item) => item['label'] as String,
          subtitleOf: (item) =>
              'Anterior: ${_comparisonValue(item, item['previous'])}',
          valueOf: (item) =>
              '${_comparisonValue(item, item['current'])}  ${_variationLabel(item['variation'])}',
          valueColor: (item) => _variationColor(item['variation']),
        ),
        const SizedBox(height: 18),
        _rankedPanel(
          title: 'FORMAS DE PAGAMENTO',
          empty: 'Sem pagamentos.',
          items: payments,
          titleOf: (item) => item['label'] as String,
          subtitleOf: (item) => 'Anterior: ${_money(item['previous'])}',
          valueOf: (item) =>
              '${_money(item['current'])}  ${_variationLabel(item['variation'])}',
          valueColor: (item) => _variationColor(item['variation']),
        ),
      ],
    );
  }

  Widget _comparisonEvent(Map<String, dynamic> event, {required bool current}) {
    return Row(
      children: [
        Container(
          width: 38,
          height: 38,
          decoration: BoxDecoration(
            color: current ? AppColors.lime : AppColors.surfaceRaised,
            borderRadius: BorderRadius.circular(12),
          ),
          child: Icon(
            current ? Icons.trending_up_rounded : Icons.history_rounded,
            color: current ? AppColors.navy : AppColors.textSoft,
          ),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                current ? 'EDIÇÃO ATUAL' : 'EDIÇÃO ANTERIOR',
                style: const TextStyle(
                  color: AppColors.textMuted,
                  fontSize: 9,
                  fontWeight: FontWeight.w700,
                  letterSpacing: 1.4,
                ),
              ),
              Text(
                event['title'] as String? ?? 'Evento',
                style: const TextStyle(fontWeight: FontWeight.w700),
              ),
            ],
          ),
        ),
        Text(
          _money(event['total_sales']),
          style: TextStyle(
            color: current ? AppColors.lime : AppColors.white,
            fontWeight: FontWeight.w800,
          ),
        ),
      ],
    );
  }

  Widget _morePage() {
    final items = <_MoreAction>[
      if (_sectionIsVisible('highlights'))
        _MoreAction('Performance', 'Produtos, horários, zonas e devices',
            Icons.query_stats_rounded, () => _openSection('performance')),
      if (_sectionIsVisible('comparison'))
        _MoreAction(
            'Comparar edições',
            'Compare o evento com a edição anterior',
            Icons.compare_arrows_rounded,
            () => _openSection('comparison')),
      _MoreAction(
          'Exportar relatório',
          'Abrir o dashboard completo para imprimir ou guardar PDF',
          Icons.download_outlined,
          _openPortalReport),
      _MoreAction(
          'Equipa Contacto Digital',
          'Falar com o suporte pelo WhatsApp',
          Icons.support_agent_rounded,
          _openSupport),
      _MoreAction('Terminar sessão', 'Sair com segurança deste dispositivo',
          Icons.logout_rounded, _logout,
          destructive: true),
    ];

    return Column(
      children: items
          .map(
            (item) => Container(
              margin: const EdgeInsets.only(bottom: 10),
              child: Material(
                color: AppColors.surface.withValues(alpha: 0.92),
                clipBehavior: Clip.antiAlias,
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(22),
                  side: const BorderSide(color: AppColors.border),
                ),
                child: ListTile(
                  contentPadding:
                      const EdgeInsets.symmetric(horizontal: 16, vertical: 7),
                  onTap: item.onTap,
                  leading: Container(
                    width: 42,
                    height: 42,
                    decoration: BoxDecoration(
                      color:
                          (item.destructive ? Colors.redAccent : AppColors.lime)
                              .withValues(alpha: 0.1),
                      borderRadius: BorderRadius.circular(13),
                    ),
                    child: Icon(
                      item.icon,
                      color:
                          item.destructive ? Colors.redAccent : AppColors.lime,
                    ),
                  ),
                  title: Text(item.title,
                      style: const TextStyle(fontWeight: FontWeight.w700)),
                  subtitle: Text(item.subtitle,
                      style: const TextStyle(color: AppColors.textMuted)),
                  trailing: const Icon(Icons.chevron_right_rounded),
                ),
              ),
            ),
          )
          .toList(),
    );
  }

  Future<void> _openSupport() async {
    final uri = Uri.parse(
      'https://api.whatsapp.com/send/?phone=351910918377&text=Ol%C3%A1%2C+preciso+de+ajuda+com+o+app+Contacto+Digital.',
    );
    if (!await launchUrl(uri, mode: LaunchMode.externalApplication) &&
        mounted) {
      _showLaunchError('Não foi possível abrir o WhatsApp.');
    }
  }

  Future<void> _openPortalReport() async {
    final uri = Uri.parse(
      'https://portal.contactodigital.pt/events/$_selectedEventId/dashboard',
    );
    if (!await launchUrl(uri, mode: LaunchMode.externalApplication) &&
        mounted) {
      _showLaunchError('Não foi possível abrir o relatório no navegador.');
    }
  }

  void _showLaunchError(String message) {
    ScaffoldMessenger.of(context)
        .showSnackBar(SnackBar(content: Text(message)));
  }

  Widget _sectionLoadingPanel() => const Padding(
        padding: EdgeInsets.symmetric(vertical: 90),
        child: Column(
          children: [
            CircularProgressIndicator(),
            SizedBox(height: 14),
            Text('A preparar esta análise...',
                style: TextStyle(color: AppColors.textMuted)),
          ],
        ),
      );

  Widget _sectionErrorPanel() => _noticePanel(
        'Não foi possível carregar',
        _sectionError!,
        action: FilledButton(
          onPressed: () => _loadSection(_activeSection, force: true),
          child: const Text('Tentar novamente'),
        ),
      );

  Widget _noticePanel(String title, String message, {Widget? action}) {
    return Container(
      padding: const EdgeInsets.all(22),
      decoration: BoxDecoration(
        color: AppColors.warning.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: AppColors.warning.withValues(alpha: 0.45)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(title,
              style:
                  const TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
          const SizedBox(height: 6),
          Text(message, style: const TextStyle(color: AppColors.textMuted)),
          if (action != null) ...[const SizedBox(height: 16), action],
        ],
      ),
    );
  }

  Widget _metricWrap(List<_PortalMetric> metrics) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final columns = constraints.maxWidth >= 900
            ? 4
            : constraints.maxWidth >= 560
                ? 2
                : 2;
        final width = (constraints.maxWidth - (columns - 1) * 10) / columns;
        return Wrap(
          spacing: 10,
          runSpacing: 10,
          children: metrics
              .map((metric) =>
                  SizedBox(width: width, child: _PortalMetricCard(metric)))
              .toList(),
        );
      },
    );
  }

  Widget _heroValue(String label, String value, String caption) {
    return Container(
      padding: const EdgeInsets.all(24),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
            colors: [Color(0xFF06304A), Color(0xFF082A55)]),
        borderRadius: BorderRadius.circular(28),
        border: Border.all(color: AppColors.blueBright.withValues(alpha: 0.55)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label,
              style: const TextStyle(
                  color: AppColors.textMuted,
                  fontSize: 10,
                  fontWeight: FontWeight.w700,
                  letterSpacing: 1.7)),
          const SizedBox(height: 10),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            child: Text(value,
                style: const TextStyle(
                    color: AppColors.lime,
                    fontSize: 38,
                    fontWeight: FontWeight.w800,
                    letterSpacing: -1.3)),
          ),
          const SizedBox(height: 6),
          Text(caption, style: const TextStyle(color: AppColors.textMuted)),
        ],
      ),
    );
  }

  Widget _searchField(String hint) {
    return TextField(
      controller: _sectionSearchController,
      onChanged: (value) =>
          setState(() => _sectionSearch = value.trim().toLowerCase()),
      decoration: InputDecoration(
        hintText: hint,
        prefixIcon: const Icon(Icons.search_rounded),
        suffixIcon: _sectionSearch.isEmpty
            ? null
            : IconButton(
                tooltip: 'Limpar pesquisa',
                onPressed: () {
                  _sectionSearchController.clear();
                  setState(() => _sectionSearch = '');
                },
                icon: const Icon(Icons.close_rounded),
              ),
      ),
    );
  }

  Widget _rankedPanel({
    required String title,
    required String empty,
    required List<Map<String, dynamic>> items,
    required String Function(Map<String, dynamic>) titleOf,
    required String Function(Map<String, dynamic>) subtitleOf,
    required String Function(Map<String, dynamic>) valueOf,
    Color Function(Map<String, dynamic>)? valueColor,
  }) {
    return Container(
      decoration: BoxDecoration(
        color: AppColors.surface.withValues(alpha: 0.92),
        borderRadius: BorderRadius.circular(26),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(18, 18, 18, 12),
            child: Text(title,
                style: const TextStyle(
                    color: AppColors.textMuted,
                    fontSize: 10,
                    fontWeight: FontWeight.w700,
                    letterSpacing: 1.7)),
          ),
          if (items.isEmpty)
            Padding(
              padding: const EdgeInsets.all(20),
              child: Text(empty,
                  style: const TextStyle(color: AppColors.textMuted)),
            )
          else
            ...items.take(50).toList().asMap().entries.map((entry) {
              final item = entry.value;
              return Container(
                padding: const EdgeInsets.fromLTRB(16, 13, 16, 13),
                decoration: BoxDecoration(
                  border: entry.key == items.take(50).length - 1
                      ? null
                      : const Border(top: BorderSide(color: AppColors.border)),
                ),
                child: Row(
                  children: [
                    Container(
                      width: 32,
                      height: 32,
                      alignment: Alignment.center,
                      decoration: BoxDecoration(
                        color: entry.key == 0
                            ? AppColors.lime
                            : AppColors.surfaceRaised,
                        borderRadius: BorderRadius.circular(10),
                      ),
                      child: Text('${entry.key + 1}',
                          style: TextStyle(
                              color: entry.key == 0
                                  ? AppColors.navy
                                  : AppColors.textSoft,
                              fontWeight: FontWeight.w800)),
                    ),
                    const SizedBox(width: 11),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(titleOf(item),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style:
                                  const TextStyle(fontWeight: FontWeight.w700)),
                          const SizedBox(height: 2),
                          Text(subtitleOf(item),
                              maxLines: 2,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(
                                  color: AppColors.textMuted, fontSize: 11)),
                        ],
                      ),
                    ),
                    const SizedBox(width: 10),
                    Text(valueOf(item),
                        textAlign: TextAlign.end,
                        style: TextStyle(
                            color: valueColor?.call(item) ?? AppColors.white,
                            fontWeight: FontWeight.w700)),
                  ],
                ),
              );
            }),
        ],
      ),
    );
  }

  List<Map<String, dynamic>> _filteredItems(
      List<Map<String, dynamic>> items, List<String> fields) {
    if (_sectionSearch.isEmpty) return items;
    return items
        .where((item) => fields.any((field) =>
            item[field].toString().toLowerCase().contains(_sectionSearch)))
        .toList();
  }

  List<Map<String, dynamic>> _maps(dynamic value) {
    if (value is! List) return [];
    return value
        .whereType<Map>()
        .map((item) => item.cast<String, dynamic>())
        .toList();
  }

  Map<String, dynamic> _map(dynamic value) =>
      value is Map ? value.cast<String, dynamic>() : <String, dynamic>{};

  String _money(dynamic value) =>
      _currency.format((value as num?)?.toDouble() ?? 0);
  String _quantity(dynamic value) =>
      _formatQuantity((value as num?)?.toDouble() ?? 0);
  String _decimal(dynamic value) => ((value as num?)?.toDouble() ?? 0)
      .toStringAsFixed(1)
      .replaceAll('.', ',');
  String _differenceLabel(dynamic value) {
    final difference = (value as num?)?.toDouble() ?? 0;
    return '${difference > 0 ? '+' : ''}${_currency.format(difference)}';
  }

  String _variationLabel(dynamic value) {
    if (value == null) return '—';
    final variation = (value as num).toDouble();
    return '${variation > 0 ? '+' : ''}${variation.toStringAsFixed(1).replaceAll('.', ',')}%';
  }

  Color _variationColor(dynamic value) {
    final variation = (value as num?)?.toDouble();
    if (variation == null || variation == 0) return AppColors.textSoft;
    return variation > 0 ? AppColors.success : AppColors.warning;
  }

  String _comparisonValue(Map<String, dynamic> item, dynamic value) =>
      item['format'] == 'currency' ? _money(value) : _quantity(value);
}

class _PortalMetric {
  const _PortalMetric(this.label, this.value, this.caption, this.icon,
      {this.accent = false});
  final String label;
  final String value;
  final String caption;
  final IconData icon;
  final bool accent;
}

class _PortalMetricCard extends StatelessWidget {
  const _PortalMetricCard(this.metric);
  final _PortalMetric metric;

  @override
  Widget build(BuildContext context) {
    return Container(
      height: 180,
      padding: const EdgeInsets.all(17),
      decoration: BoxDecoration(
        color: metric.accent ? const Color(0xFF082D59) : AppColors.surface,
        borderRadius: BorderRadius.circular(22),
        border: Border.all(
            color: metric.accent ? AppColors.blueBright : AppColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                  child: Text(metric.label,
                      style: const TextStyle(
                          color: AppColors.textMuted,
                          fontSize: 9,
                          fontWeight: FontWeight.w700,
                          letterSpacing: 1.3))),
              Icon(metric.icon,
                  size: 18,
                  color: metric.accent ? AppColors.lime : AppColors.textMuted),
            ],
          ),
          const Spacer(),
          Text(metric.value,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                  color: metric.accent ? AppColors.lime : AppColors.white,
                  fontSize: 23,
                  fontWeight: FontWeight.w800,
                  letterSpacing: -0.5)),
          const SizedBox(height: 4),
          Text(metric.caption,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(color: AppColors.textMuted, fontSize: 11)),
        ],
      ),
    );
  }
}

class _PortalFieldLabel extends StatelessWidget {
  const _PortalFieldLabel(this.label);
  final String label;

  @override
  Widget build(BuildContext context) => Text(
        label,
        style: const TextStyle(
            color: AppColors.textMuted,
            fontSize: 9,
            fontWeight: FontWeight.w700,
            letterSpacing: 1.5),
      );
}

class _DateFilterButton extends StatelessWidget {
  const _DateFilterButton(
      {required this.label, required this.value, required this.onTap});
  final String label;
  final DateTime? value;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final text = value == null
        ? label
        : '${value!.day.toString().padLeft(2, '0')}/${value!.month.toString().padLeft(2, '0')}/${value!.year}';
    return OutlinedButton.icon(
      onPressed: onTap,
      icon: const Icon(Icons.calendar_today_outlined, size: 17),
      label: Text(text),
      style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(54)),
    );
  }
}

class _HourFilter extends StatelessWidget {
  const _HourFilter(
      {required this.label, required this.value, required this.onChanged});
  final String label;
  final int? value;
  final ValueChanged<int?> onChanged;

  @override
  Widget build(BuildContext context) {
    return DropdownButtonFormField<int?>(
      value: value,
      decoration: InputDecoration(labelText: label),
      items: [
        const DropdownMenuItem(value: null, child: Text('Todos')),
        ...List.generate(
            24,
            (hour) => DropdownMenuItem(
                value: hour,
                child: Text('${hour.toString().padLeft(2, '0')}:00'))),
      ],
      onChanged: onChanged,
    );
  }
}

class _MoreAction {
  const _MoreAction(this.title, this.subtitle, this.icon, this.onTap,
      {this.destructive = false});
  final String title;
  final String subtitle;
  final IconData icon;
  final VoidCallback onTap;
  final bool destructive;
}
