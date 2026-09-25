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

  DashboardFilters get dateRangeOnly => DashboardFilters(
        dateFrom: dateFrom,
        dateTo: dateTo,
      );

  /// `clearX: true` resets that field to null; passing a new value for it
  /// at the same time is not supported (clear wins) since callers only ever
  /// need one or the other.
  DashboardFilters copyWith({
    List<String>? zones,
    String? store,
    bool clearStore = false,
    String? product,
    bool clearProduct = false,
    DateTime? dateFrom,
    bool clearDateFrom = false,
    DateTime? dateTo,
    bool clearDateTo = false,
    int? hourFrom,
    bool clearHourFrom = false,
    int? hourTo,
    bool clearHourTo = false,
  }) {
    return DashboardFilters(
      zones: zones ?? this.zones,
      store: clearStore ? null : (store ?? this.store),
      product: clearProduct ? null : (product ?? this.product),
      dateFrom: clearDateFrom ? null : (dateFrom ?? this.dateFrom),
      dateTo: clearDateTo ? null : (dateTo ?? this.dateTo),
      hourFrom: clearHourFrom ? null : (hourFrom ?? this.hourFrom),
      hourTo: clearHourTo ? null : (hourTo ?? this.hourTo),
    );
  }

  static String _date(DateTime value) =>
      '${value.year.toString().padLeft(4, '0')}-${value.month.toString().padLeft(2, '0')}-${value.day.toString().padLeft(2, '0')}';
}

extension _EventPortalSections on _EventSummaryScreenState {
  static const _sectionLabels = {
    'summary': 'Resumo',
    'products': 'Produtos',
    'payments': 'Pagamentos',
    'zones': 'Zonas',
    'performance': 'Ranking',
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

    final sections =
        _primarySections.where((section) => section != 'more').toList();
    final selectedIndex = sections.indexOf(_activeSection);
    final bottomInset = MediaQuery.paddingOf(context).bottom;
    final navBottomGap = math.max(10.0, bottomInset - 18);

    return AnimatedContainer(
      duration: const Duration(milliseconds: 420),
      curve: Curves.easeOutBack,
      height: (_quickMenuOpen ? 342 : 72) + navBottomGap,
      padding: EdgeInsets.fromLTRB(14, 0, 14, navBottomGap),
      child: Stack(
        alignment: Alignment.bottomCenter,
        children: [
          Positioned(
            left: 0,
            right: 0,
            bottom: 74,
            child: AnimatedSwitcher(
              duration: const Duration(milliseconds: 280),
              switchInCurve: Curves.easeOutBack,
              switchOutCurve: Curves.easeInCubic,
              transitionBuilder: (child, animation) => FadeTransition(
                opacity: animation,
                child: ScaleTransition(
                  scale: Tween<double>(begin: 0.82, end: 1).animate(animation),
                  alignment: Alignment.bottomRight,
                  child: child,
                ),
              ),
              child: _quickMenuOpen
                  ? _quickActionsPanel(key: const ValueKey('quick-actions'))
                  : const SizedBox.shrink(
                      key: ValueKey('quick-actions-hidden')),
            ),
          ),
          Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Expanded(
                child: _liquidTabBar(
                  sections: sections,
                  selectedIndex: selectedIndex,
                ),
              ),
              const SizedBox(width: 10),
              Semantics(
                button: true,
                label: _quickMenuOpen ? 'Fechar atalhos' : 'Abrir atalhos',
                child: Material(
                  color: Colors.transparent,
                  child: InkWell(
                    key: const ValueKey('quick-menu-button'),
                    onTap: () {
                      HapticFeedback.mediumImpact();
                      setState(() => _quickMenuOpen = !_quickMenuOpen);
                    },
                    customBorder: const CircleBorder(),
                    child: AnimatedContainer(
                      duration: const Duration(milliseconds: 300),
                      width: 64,
                      height: 64,
                      decoration: BoxDecoration(
                        shape: BoxShape.circle,
                        color: _quickMenuOpen
                            ? AppColors.lime
                            : AppColors.surfaceRaised.withValues(alpha: 0.92),
                        border: Border.all(
                          color: _quickMenuOpen
                              ? AppColors.lime
                              : Colors.white.withValues(alpha: 0.16),
                        ),
                        boxShadow: const [
                          BoxShadow(
                            color: Color(0x55000000),
                            blurRadius: 24,
                            offset: Offset(0, 10),
                          ),
                        ],
                      ),
                      child: AnimatedRotation(
                        turns: _quickMenuOpen ? 0.125 : 0,
                        duration: const Duration(milliseconds: 300),
                        curve: Curves.easeOutBack,
                        child: Icon(
                          Icons.add_rounded,
                          size: 30,
                          color:
                              _quickMenuOpen ? AppColors.navy : AppColors.white,
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _liquidTabBar({
    required List<String> sections,
    required int selectedIndex,
  }) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(34),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 18, sigmaY: 18),
        child: Container(
          height: 64,
          decoration: BoxDecoration(
            color: const Color(0xDC09253D),
            borderRadius: BorderRadius.circular(34),
            border: Border.all(color: Colors.white.withValues(alpha: 0.13)),
            boxShadow: const [
              BoxShadow(
                color: Color(0x55000000),
                blurRadius: 24,
                offset: Offset(0, 10),
              ),
            ],
          ),
          child: LayoutBuilder(
            builder: (context, constraints) {
              final itemWidth = constraints.maxWidth / sections.length;
              return Stack(
                children: [
                  if (selectedIndex >= 0)
                    AnimatedPositioned(
                      duration: const Duration(milliseconds: 430),
                      curve: Curves.easeOutBack,
                      left: selectedIndex * itemWidth + 4,
                      top: 4,
                      width: itemWidth - 8,
                      height: 56,
                      child: ClipRRect(
                        borderRadius: BorderRadius.circular(28),
                        child: BackdropFilter(
                          filter: ImageFilter.blur(sigmaX: 12, sigmaY: 12),
                          child: DecoratedBox(
                            decoration: BoxDecoration(
                              gradient: LinearGradient(
                                begin: Alignment.topLeft,
                                end: Alignment.bottomRight,
                                colors: [
                                  Colors.white.withValues(alpha: 0.2),
                                  AppColors.blueBright.withValues(alpha: 0.16),
                                ],
                              ),
                              borderRadius: BorderRadius.circular(28),
                              border: Border.all(
                                color: Colors.white.withValues(alpha: 0.2),
                              ),
                            ),
                          ),
                        ),
                      ),
                    ),
                  Row(
                    children: sections.indexed.map((entry) {
                      final index = entry.$1;
                      final section = entry.$2;
                      final selected = index == selectedIndex;
                      return Expanded(
                        child: InkWell(
                          borderRadius: BorderRadius.circular(28),
                          onTap: () {
                            HapticFeedback.selectionClick();
                            _openSection(section);
                          },
                          child: Column(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              AnimatedScale(
                                scale: selected ? 1.08 : 1,
                                duration: const Duration(milliseconds: 260),
                                curve: Curves.easeOutBack,
                                child: Icon(
                                  _sectionIcon(section),
                                  size: 22,
                                  color: selected
                                      ? AppColors.lime
                                      : AppColors.textSoft,
                                ),
                              ),
                              const SizedBox(height: 3),
                              Text(
                                _configuredSectionLabel(section),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: TextStyle(
                                  color: selected
                                      ? AppColors.white
                                      : AppColors.textMuted,
                                  fontSize: 8,
                                  fontWeight: selected
                                      ? FontWeight.w700
                                      : FontWeight.w500,
                                ),
                              ),
                            ],
                          ),
                        ),
                      );
                    }).toList(),
                  ),
                ],
              );
            },
          ),
        ),
      ),
    );
  }

  Widget _quickActionsPanel({Key? key}) {
    final actions = _moreActions;
    return ClipRRect(
      key: key,
      borderRadius: BorderRadius.circular(28),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 22, sigmaY: 22),
        child: Container(
          padding: const EdgeInsets.fromLTRB(14, 16, 14, 14),
          decoration: BoxDecoration(
            color: const Color(0xEE09253D),
            borderRadius: BorderRadius.circular(28),
            border: Border.all(color: Colors.white.withValues(alpha: 0.14)),
            boxShadow: const [
              BoxShadow(
                color: Color(0x66000000),
                blurRadius: 34,
                offset: Offset(0, 16),
              ),
            ],
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Padding(
                padding: EdgeInsets.symmetric(horizontal: 4),
                child: Text(
                  'ATALHOS',
                  style: TextStyle(
                    color: AppColors.textMuted,
                    fontSize: 10,
                    fontWeight: FontWeight.w700,
                    letterSpacing: 1.5,
                  ),
                ),
              ),
              const SizedBox(height: 10),
              LayoutBuilder(
                builder: (context, constraints) {
                  const gap = 10.0;
                  final width = (constraints.maxWidth - gap) / 2;
                  return Wrap(
                    spacing: gap,
                    runSpacing: gap,
                    children: actions.map((action) {
                      final color = action.destructive
                          ? Colors.redAccent
                          : AppColors.lime;
                      return SizedBox(
                        width: width,
                        height: 62,
                        child: Material(
                          color:
                              AppColors.surfaceRaised.withValues(alpha: 0.72),
                          borderRadius: BorderRadius.circular(18),
                          child: InkWell(
                            borderRadius: BorderRadius.circular(18),
                            onTap: () {
                              setState(() => _quickMenuOpen = false);
                              action.onTap();
                            },
                            child: Padding(
                              padding:
                                  const EdgeInsets.symmetric(horizontal: 12),
                              child: Row(
                                children: [
                                  Icon(action.icon, color: color, size: 21),
                                  const SizedBox(width: 9),
                                  Expanded(
                                    child: Text(
                                      action.title,
                                      maxLines: 2,
                                      overflow: TextOverflow.ellipsis,
                                      style: const TextStyle(
                                        fontSize: 11,
                                        fontWeight: FontWeight.w700,
                                      ),
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ),
                        ),
                      );
                    }).toList(),
                  );
                },
              ),
            ],
          ),
        ),
      ),
    );
  }

  List<String> get _primarySections {
    // Keep the four daily destinations comfortable on compact phones.
    // Zone analysis remains one tap away inside the expandable shortcut menu.
    return [
      if (_sectionIsVisible('summary')) 'summary',
      if (_sectionIsVisible('products')) 'products',
      if (_sectionIsVisible('reconciliation')) 'payments',
      if (_sectionIsVisible('highlights')) 'performance',
      'more',
    ];
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
    if (section == 'payments' || section == 'performance') {
      return _sectionLabels[section]!;
    }
    if (section == 'more') return _sectionLabels[section]!;
    final sections = _configuration?['sections'];
    if (sections is List) {
      final key = _configurationKeyForSection(section);
      for (final item in sections.whereType<Map>()) {
        if (item['key'] == key && item['label'] is String) {
          final label = (item['label'] as String).trim();
          if (label.isNotEmpty) return _clientFacingLabel(label);
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
    return _clientFacingLabel(fallback);
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
      _quickMenuOpen = false;
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
                    const SizedBox(height: 12),
                    _filterToolbar(),
                    const SizedBox(height: 22),
                    _featureHeading(),
                    const SizedBox(height: 18),
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

  /// Human-readable label for a product code, resolved from the loaded
  /// filter options. Falls back to the code itself if the options haven't
  /// been fetched yet (should not normally happen: a product filter can
  /// only be set from the sheet below, by which point options are cached).
  String _productLabel(String code) {
    final products = _maps(_filterOptions?['products']);
    for (final item in products) {
      if (item['value'] == code) {
        return _clientFacingLabel(item['label'], fallback: code);
      }
    }
    return _clientFacingLabel(code, fallback: code);
  }

  String _formatDateRange(DateTime? from, DateTime? to) {
    final fmt = DateFormat('dd/MM', 'pt_PT');
    if (from != null && to != null) {
      return '${fmt.format(from)} – ${fmt.format(to)}';
    }
    if (from != null) return 'Desde ${fmt.format(from)}';
    if (to != null) return 'Até ${fmt.format(to)}';
    return 'Período';
  }

  String _formatHourRange(int? from, int? to) {
    String h(int value) => '${value.toString().padLeft(2, '0')}h';
    if (from != null && to != null) return '${h(from)} – ${h(to)}';
    if (from != null) return 'Desde ${h(from)}';
    if (to != null) return 'Até ${h(to)}';
    return 'Horário';
  }

  /// Every active filter as its own removable pill: label plus the mutation
  /// that clears just that one filter, so a client can back out of a single
  /// choice without reopening the whole sheet.
  List<({String label, VoidCallback onRemove})> _activeFilterChips() {
    final chips = <({String label, VoidCallback onRemove})>[];

    for (final zone in _filters.zones) {
      chips.add((
        label: zone,
        onRemove: () => _applyFilters(_filters.copyWith(
            zones: _filters.zones.where((z) => z != zone).toList())),
      ));
    }
    if (_filters.store != null) {
      chips.add((
        label: _filters.store!,
        onRemove: () => _applyFilters(_filters.copyWith(clearStore: true)),
      ));
    }
    if (_filters.product != null) {
      chips.add((
        label: _productLabel(_filters.product!),
        onRemove: () => _applyFilters(_filters.copyWith(clearProduct: true)),
      ));
    }
    if (_filters.hourFrom != null || _filters.hourTo != null) {
      chips.add((
        label: _formatHourRange(_filters.hourFrom, _filters.hourTo),
        onRemove: () => _applyFilters(
            _filters.copyWith(clearHourFrom: true, clearHourTo: true)),
      ));
    }
    return chips;
  }

  Future<void> _applyFilters(DashboardFilters next) async {
    if (next.signature == _filters.signature) return;
    setState(() {
      _filters = next;
      _sectionSearch = '';
      _sectionSearchController.clear();
    });
    if (_activeSection == 'summary') {
      await _loadFilteredDashboard();
    } else {
      await _loadSection(_activeSection);
    }
  }

  Widget _filterToolbar() {
    final chips = _activeFilterChips();
    final hasDateRange = _filters.dateFrom != null || _filters.dateTo != null;
    final dateFilterCount =
        (_filters.dateFrom == null ? 0 : 1) + (_filters.dateTo == null ? 0 : 1);
    final additionalFilterCount = _filters.activeCount - dateFilterCount;

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: AppColors.surface.withValues(alpha: 0.88),
        border: Border.all(color: AppColors.border),
        borderRadius: BorderRadius.circular(22),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: Semantics(
                  button: true,
                  label: hasDateRange
                      ? 'Período selecionado: ${_formatDateRange(_filters.dateFrom, _filters.dateTo)}'
                      : 'Selecionar período. Todo o evento.',
                  child: InkWell(
                    key: const ValueKey('dashboard-date-range'),
                    onTap: _showDateRangePicker,
                    borderRadius: BorderRadius.circular(16),
                    child: Padding(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 10, vertical: 7),
                      child: Row(
                        children: [
                          Container(
                            width: 34,
                            height: 34,
                            decoration: BoxDecoration(
                              color: AppColors.blue.withValues(alpha: 0.18),
                              borderRadius: BorderRadius.circular(11),
                            ),
                            child: const Icon(
                              Icons.date_range_rounded,
                              size: 19,
                              color: AppColors.textSoft,
                            ),
                          ),
                          const SizedBox(width: 10),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                const Text(
                                  'PERÍODO',
                                  style: TextStyle(
                                    color: AppColors.textMuted,
                                    fontSize: 9,
                                    fontWeight: FontWeight.w700,
                                    letterSpacing: 1.2,
                                  ),
                                ),
                                const SizedBox(height: 2),
                                Text(
                                  hasDateRange
                                      ? _formatDateRange(
                                          _filters.dateFrom, _filters.dateTo)
                                      : 'Todo o evento',
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: const TextStyle(
                                    color: AppColors.white,
                                    fontSize: 13,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                              ],
                            ),
                          ),
                          if (hasDateRange)
                            IconButton(
                              tooltip: 'Limpar período',
                              visualDensity: VisualDensity.compact,
                              onPressed: _clearDateRange,
                              icon: const Icon(
                                Icons.close_rounded,
                                size: 18,
                                color: AppColors.textMuted,
                              ),
                            )
                          else
                            const Icon(
                              Icons.keyboard_arrow_down_rounded,
                              color: AppColors.textMuted,
                            ),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
              const SizedBox(width: 8),
              Badge(
                isLabelVisible: additionalFilterCount > 0,
                label: Text('$additionalFilterCount'),
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
          if (chips.isNotEmpty) ...[
            const SizedBox(height: 10),
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(
                children: [
                  for (final chip in chips)
                    Padding(
                      padding: const EdgeInsets.only(right: 7),
                      child: _ActiveFilterPill(
                          label: chip.label, onRemove: chip.onRemove),
                    ),
                  if (chips.length > 1)
                    Padding(
                      padding: const EdgeInsets.only(left: 3),
                      child: TextButton(
                        onPressed: () => _applyFilters(
                          DashboardFilters(
                            dateFrom: _filters.dateFrom,
                            dateTo: _filters.dateTo,
                          ),
                        ),
                        style: TextButton.styleFrom(
                          foregroundColor: AppColors.textMuted,
                          visualDensity: VisualDensity.compact,
                          padding: const EdgeInsets.symmetric(horizontal: 8),
                        ),
                        child: const Text('Limpar tudo',
                            style: TextStyle(fontSize: 12)),
                      ),
                    ),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }

  Future<void> _showDateRangePicker() async {
    var dateFrom = _filters.dateFrom;
    var dateTo = _filters.dateTo;
    final selected = await showModalBottomSheet<DashboardFilters>(
      context: context,
      backgroundColor: AppColors.surface,
      showDragHandle: true,
      useSafeArea: true,
      builder: (context) => StatefulBuilder(
        builder: (context, setModalState) => Padding(
          padding: const EdgeInsets.fromLTRB(20, 2, 20, 24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Text(
                'Selecionar período',
                style: TextStyle(fontSize: 22, fontWeight: FontWeight.w700),
              ),
              const SizedBox(height: 5),
              const Text(
                'Este período será usado em todas as áreas do evento.',
                style: TextStyle(color: AppColors.textMuted),
              ),
              const SizedBox(height: 20),
              Row(
                children: [
                  Expanded(
                    child: _DateFilterButton(
                      label: 'Início',
                      value: dateFrom,
                      onTap: () async {
                        final picked = await _showEventDayPicker(
                          initialDate: dateFrom ?? dateTo,
                          title: 'Data inicial',
                        );
                        if (picked != null) {
                          setModalState(() {
                            dateFrom = picked;
                            if (dateTo == null || dateTo!.isBefore(picked)) {
                              dateTo = picked;
                            }
                          });
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
                        final picked = await _showEventDayPicker(
                          initialDate: dateTo ?? dateFrom,
                          firstAllowedDate: dateFrom,
                          title: 'Data final',
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
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton(
                      onPressed: () => Navigator.of(context).pop(
                        _filters.copyWith(
                          clearDateFrom: true,
                          clearDateTo: true,
                        ),
                      ),
                      child: const Text('Todo o evento'),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: FilledButton(
                      onPressed: () => Navigator.of(context).pop(
                        _filters.copyWith(
                          dateFrom: dateFrom,
                          clearDateFrom: dateFrom == null,
                          dateTo: dateTo,
                          clearDateTo: dateTo == null,
                        ),
                      ),
                      child: const Text('Aplicar período'),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );

    if (selected != null) {
      await _applyFilters(selected);
    }
  }

  Future<void> _clearDateRange() => _applyFilters(
        _filters.copyWith(clearDateFrom: true, clearDateTo: true),
      );

  DateTime? _plainDate(dynamic value) {
    final match =
        RegExp(r'^(\d{4})-(\d{2})-(\d{2})').firstMatch(value?.toString() ?? '');
    if (match == null) return null;

    return DateTime(
      int.parse(match.group(1)!),
      int.parse(match.group(2)!),
      int.parse(match.group(3)!),
    );
  }

  DateTimeRange get _eventDateRange {
    final start = _plainDate(_event?['report_starts_at']) ??
        _plainDate(_event?['event_date']) ??
        DateUtils.dateOnly(DateTime.now());
    final configuredEnd = _plainDate(_event?['report_ends_at']) ?? start;

    return DateTimeRange(
      start: start,
      end: configuredEnd.isBefore(start) ? start : configuredEnd,
    );
  }

  DateTime _clampToEvent(DateTime? value, DateTimeRange range) {
    final date = DateUtils.dateOnly(value ?? DateTime.now());
    if (date.isBefore(range.start)) return range.start;
    if (date.isAfter(range.end)) return range.end;
    return date;
  }

  Future<DateTime?> _showEventDayPicker({
    required String title,
    DateTime? initialDate,
    DateTime? firstAllowedDate,
  }) {
    final range = _eventDateRange;
    final firstDate = firstAllowedDate == null
        ? range.start
        : _clampToEvent(firstAllowedDate, range);
    final days = <DateTime>[];
    for (var day = firstDate;
        !day.isAfter(range.end);
        day = day.add(const Duration(days: 1))) {
      days.add(day);
    }
    final selected = _clampToEvent(initialDate, range);

    return showModalBottomSheet<DateTime>(
      context: context,
      isScrollControlled: true,
      backgroundColor: AppColors.surface,
      showDragHandle: true,
      useSafeArea: true,
      builder: (context) => ConstrainedBox(
        constraints: BoxConstraints(
          maxWidth: 680,
          maxHeight: MediaQuery.sizeOf(context).height * 0.78,
        ),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 2, 20, 24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                title,
                style:
                    const TextStyle(fontSize: 22, fontWeight: FontWeight.w700),
              ),
              const SizedBox(height: 5),
              Text(
                'Escolha um dos ${days.length} dias do evento.',
                style: const TextStyle(color: AppColors.textMuted),
              ),
              const SizedBox(height: 18),
              Flexible(
                child: SingleChildScrollView(
                  child: Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: days.map((day) {
                      final isSelected = DateUtils.isSameDay(day, selected);

                      return SizedBox(
                        width: 62,
                        height: 62,
                        child: isSelected
                            ? FilledButton(
                                style: FilledButton.styleFrom(
                                  padding: EdgeInsets.zero,
                                ),
                                onPressed: () => Navigator.of(context).pop(day),
                                child: _eventDayLabel(day, selected: true),
                              )
                            : OutlinedButton(
                                style: OutlinedButton.styleFrom(
                                  padding: EdgeInsets.zero,
                                ),
                                onPressed: () => Navigator.of(context).pop(day),
                                child: _eventDayLabel(day),
                              ),
                      );
                    }).toList(),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _eventDayLabel(DateTime day, {bool selected = false}) => Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Text(
            const [
              'SEG',
              'TER',
              'QUA',
              'QUI',
              'SEX',
              'SÁB',
              'DOM'
            ][day.weekday - 1],
            style: TextStyle(
              color: selected ? AppColors.navy : AppColors.textMuted,
              fontSize: 10,
              fontWeight: FontWeight.w700,
            ),
          ),
          const SizedBox(height: 3),
          Text(
            DateFormat('dd/MM').format(day),
            style: TextStyle(
              color: selected ? AppColors.navy : AppColors.white,
              fontWeight: FontWeight.w700,
            ),
          ),
        ],
      );

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
                      label: Text(_clientFacingLabel(zone['label'])),
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
                  decoration: const InputDecoration(labelText: 'Device'),
                  items: [
                    const DropdownMenuItem(value: null, child: Text('Todos')),
                    ...storeOptions.map(
                      (item) => DropdownMenuItem(
                        value: item['value'] as String,
                        child: Text(
                          _clientFacingLabel(item['label']),
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
                            _clientFacingLabel(item['label']),
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
                          final picked = await _showEventDayPicker(
                            initialDate: dateFrom ?? dateTo,
                            title: 'Data inicial',
                          );
                          if (picked != null) {
                            setModalState(() {
                              dateFrom = picked;
                              if (dateTo == null || dateTo!.isBefore(picked)) {
                                dateTo = picked;
                              }
                            });
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
                          final picked = await _showEventDayPicker(
                            initialDate: dateTo ?? dateFrom,
                            firstAllowedDate: dateFrom,
                            title: 'Data final',
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

    if (selected != null) {
      await _applyFilters(selected);
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
    final items = [
      ..._filteredItems(
        _maps(data['items']),
        ['description', 'product_code'],
      ),
    ]..sort((left, right) {
        final field = _productSortBySales ? 'total_sales' : 'served_quantity';
        final leftValue = (left[field] as num?)?.toDouble() ?? 0;
        final rightValue = (right[field] as num?)?.toDouble() ?? 0;
        return rightValue.compareTo(leftValue);
      });
    final eventSalesTotal = (_summary?['total_sales'] as num?)?.toDouble() ?? 0;

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
          _PortalMetric(
            'PESO DAS OFERTAS',
            '${_decimal(summary['offer_share'])}%',
            'do total servido',
            Icons.percent_rounded,
            accent: true,
          ),
        ], compact: true, interactive: true),
        const SizedBox(height: 24),
        _searchField('Pesquisar produto'),
        const SizedBox(height: 12),
        _rankedPanel(
          title: 'RANKING DE PRODUTOS',
          helper: '${items.length} referências apresentadas',
          action: TextButton.icon(
            onPressed: () => setState(
              () => _productSortBySales = !_productSortBySales,
            ),
            icon: const Icon(Icons.swap_vert_rounded, size: 17),
            label: Text(
              _productSortBySales ? 'Por quantidade' : 'Por faturação',
            ),
          ),
          empty: 'Não existem produtos neste filtro.',
          items: items,
          titleOf: (item) =>
              _clientFacingLabel(item['description'], fallback: 'Produto'),
          subtitleOf: (item) {
            final reference =
                _clientFacingLabel(item['product_code'], fallback: '—');
            return 'Ref. $reference · ${_quantity(item['served_quantity'])} servidos';
          },
          valueOf: (item) => _money(item['total_sales']),
          onTap: (item) => _showProductRankingDetails(
            item,
            eventSalesTotal: eventSalesTotal,
          ),
        ),
      ],
    );
  }

  Widget _paymentsPage(Map<String, dynamic> data) {
    final summary = _map(data['summary']);
    final reconciliation = _map(data['reconciliation']);
    final totals = _map(reconciliation['totals']);
    final topUpLoaded = (summary['top_up_loaded'] as num?)?.toDouble() ?? 0;
    final topUpSpent = (summary['top_up_spent'] as num?)?.toDouble() ?? 0;
    final topUpDocuments =
        (summary['top_up_documents_count'] as num?)?.toInt() ?? 0;
    final hasTopUp = topUpLoaded.abs() > 0.001 || topUpSpent.abs() > 0.001;
    final averageTopUp =
        topUpDocuments > 0 ? topUpLoaded / topUpDocuments : 0.0;
    final paymentMethodsTotal = [
      summary['multibanco'],
      summary['cash'],
      summary['zticket'],
      summary['other'],
    ].fold<double>(
      0,
      (total, value) => total + ((value as num?)?.toDouble() ?? 0),
    );
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
        if (hasTopUp) ...[
          _heroValue(
            'CARREGAMENTOS TOP UP',
            _money(topUpLoaded),
            '$topUpDocuments carregamentos registados',
          ),
          const SizedBox(height: 24),
          _metricWrap([
            _PortalMetric('CARREGAMENTO MÉDIO', _money(averageTopUp),
                'por cartão carregado', Icons.add_card_rounded),
            _PortalMetric(
                'SALDO POR CONSUMIR',
                _money(summary['top_up_remaining']),
                'carregado menos gasto',
                Icons.savings_outlined,
                accent: true),
            _PortalMetric('VALOR GASTO', _money(topUpSpent), 'consumo Top up',
                Icons.shopping_cart_checkout_rounded),
            _PortalMetric(
                'TOTAL COM TOP UP',
                _money(summary['total_with_zt']),
                'vendas + carregamentos',
                Icons.account_balance_wallet_outlined),
          ]),
        ] else ...[
          _heroValue(
            'PAGAMENTOS DAS VENDAS',
            _money(paymentMethodsTotal),
            '${summary['documents_count'] ?? 0} documentos',
          ),
        ],
        const SizedBox(height: 24),
        _sectionTitle('Formas de pagamento', 'POR MÉTODO'),
        const SizedBox(height: 10),
        _metricWrap([
          _PortalMetric(
              'MULTIBANCO',
              _money(summary['multibanco']),
              _paymentShare(summary['multibanco'], paymentMethodsTotal),
              Icons.credit_card_rounded,
              accent: true),
          if (hasTopUp)
            _PortalMetric(
                'TOP UP',
                _money(summary['zticket']),
                _paymentShare(summary['zticket'], paymentMethodsTotal),
                Icons.nfc_rounded),
          _PortalMetric(
              'DINHEIRO',
              _money(summary['cash']),
              _paymentShare(summary['cash'], paymentMethodsTotal),
              Icons.payments_outlined),
          _PortalMetric(
              'OUTROS',
              _money(summary['other']),
              _paymentShare(summary['other'], paymentMethodsTotal),
              Icons.more_horiz_rounded),
          if (!hasTopUp)
            _PortalMetric('TOTAL FATURADO', _money(totals['sales_total']),
                'vendas sincronizadas', Icons.euro_rounded),
        ]),
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
          titleOf: (item) =>
              _clientFacingLabel(item['store_name'], fallback: 'Sem device'),
          subtitleOf: (item) =>
              'Pagamentos ${_money(item['payments_total'])} · Vendas ${_money(item['sales_total'])}',
          valueOf: (item) => _differenceLabel(item['difference']),
          valueColor: (item) =>
              ((item['difference'] as num?)?.abs() ?? 0) < 0.01
                  ? AppColors.success
                  : AppColors.warning,
          onTap: _showReconciliationDetails,
        ),
      ],
    );
  }

  String _paymentShare(dynamic value, double total) {
    if (total <= 0) return '0,0% dos pagamentos';
    final amount = (value as num?)?.toDouble() ?? 0;
    final share = (amount / total) * 100;
    return '${share.toStringAsFixed(1).replaceAll('.', ',')}% dos pagamentos';
  }

  Widget _zonesPage(Map<String, dynamic> data) {
    final summary = _map(data['summary']);
    final zones = _filteredItems(_maps(data['items']), ['label']);
    final leader = _map(summary['leading_zone']);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _metricWrap([
          _PortalMetric(
              'FATURAÇÃO',
              _money(summary['total_sales']),
              '${summary['zones_count'] ?? 0} zonas selecionadas',
              Icons.euro_rounded,
              accent: true),
          _PortalMetric('ZONA LÍDER', _clientFacingLabel(leader['label']),
              _money(leader['total_sales']), Icons.emoji_events_outlined),
          _PortalMetric('TRANSAÇÕES', _quantity(summary['tickets_count']),
              'documentos de venda', Icons.receipt_long_outlined),
          _PortalMetric('DEVICES', _quantity(summary['devices_count']),
              'pontos com dados', Icons.storefront_outlined),
        ], compact: true, interactive: true),
        const SizedBox(height: 24),
        _searchField('Pesquisar zona'),
        const SizedBox(height: 12),
        ...zones.map(_zoneCard),
      ],
    );
  }

  Widget _zoneCard(Map<String, dynamic> zone) {
    final products = _maps(zone['products']);
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      child: InkWell(
        borderRadius: BorderRadius.circular(22),
        onTap: () => _showZoneProducts(zone, products),
        child: Container(
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            color: AppColors.surface.withValues(alpha: 0.92),
            borderRadius: BorderRadius.circular(22),
            border: Border.all(color: AppColors.border),
          ),
          child: Row(
            children: [
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(
                  color: AppColors.surfaceRaised,
                  borderRadius: BorderRadius.circular(12),
                ),
                child: const Icon(Icons.layers_outlined, color: AppColors.lime),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      _clientFacingLabel(zone['label'], fallback: 'Zona'),
                      style: const TextStyle(fontWeight: FontWeight.w700),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      '${_decimal(zone['share'])}% do evento · ${zone['devices_count']} devices',
                      style: const TextStyle(color: AppColors.textMuted),
                    ),
                  ],
                ),
              ),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(
                    _money(zone['total_sales']),
                    style: const TextStyle(
                        color: AppColors.lime, fontWeight: FontWeight.w700),
                  ),
                  const SizedBox(height: 4),
                  const Text('Ver produtos',
                      style:
                          TextStyle(color: AppColors.textMuted, fontSize: 11)),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> _showZoneProducts(
    Map<String, dynamic> zone,
    List<Map<String, dynamic>> products,
  ) {
    final zoneName = _clientFacingLabel(zone['label'], fallback: 'Zona');
    return showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (context) => DraggableScrollableSheet(
        initialChildSize: 0.7,
        minChildSize: 0.45,
        maxChildSize: 0.9,
        builder: (context, controller) => Container(
          decoration: const BoxDecoration(
            color: AppColors.navy,
            borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
          ),
          child: ListView(
            controller: controller,
            padding: const EdgeInsets.fromLTRB(20, 12, 20, 32),
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
              const SizedBox(height: 20),
              const Text('PRODUTOS VENDIDOS',
                  style: TextStyle(
                    color: AppColors.textMuted,
                    fontWeight: FontWeight.w700,
                    fontSize: 11,
                    letterSpacing: 1.5,
                  )),
              const SizedBox(height: 5),
              Text(zoneName,
                  style: const TextStyle(
                      fontSize: 26, fontWeight: FontWeight.w800)),
              const SizedBox(height: 4),
              Text(
                  '${products.length} produtos · ${_money(zone['total_sales'])}',
                  style: const TextStyle(color: AppColors.textMuted)),
              const SizedBox(height: 18),
              if (products.isEmpty)
                _emptyPanel('Ainda não existem produtos vendidos nesta zona.')
              else
                ...products.map(
                  (product) => ListTile(
                    contentPadding: const EdgeInsets.symmetric(vertical: 4),
                    leading: Container(
                      width: 34,
                      height: 34,
                      alignment: Alignment.center,
                      decoration: BoxDecoration(
                        color: AppColors.surfaceRaised,
                        borderRadius: BorderRadius.circular(10),
                      ),
                      child: Text(
                        _quantity(product['sold_quantity']),
                        style: const TextStyle(
                            color: AppColors.lime, fontWeight: FontWeight.w800),
                      ),
                    ),
                    title: Text(_clientFacingLabel(product['description'],
                        fallback: 'Produto')),
                    subtitle: Text(
                        '${_quantity(product['served_quantity'])} unidades servidas'),
                    trailing: Text(_money(product['total_sales']),
                        style: const TextStyle(fontWeight: FontWeight.w700)),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _performancePage(Map<String, dynamic> data) {
    final summary = _map(data['summary']);
    final best = _map(summary['best_product']);
    final served = _map(summary['most_served_product']);
    final peak = _map(summary['peak_hour']);
    final leader = _map(summary['leading_zone']);
    final zones = _maps(data['zones']);
    final devices = _maps(data['devices']);
    final products = _maps(data['products']);
    final rankingItems = switch (_rankingScope) {
      'devices' => _filteredItems(devices, ['store_name', 'zone']),
      'products' => _filteredItems(products, ['description', 'product_code']),
      _ => _filteredItems(zones, ['label']),
    };
    final rankingTitle = switch (_rankingScope) {
      'devices' => 'RANKING DE DEVICES',
      'products' => 'RANKING DE PRODUTOS',
      _ => 'RANKING DE ZONAS',
    };
    final rankingEmpty = switch (_rankingScope) {
      'devices' => 'Não existem devices neste filtro.',
      'products' => 'Não existem produtos neste filtro.',
      _ => 'Não existem zonas neste filtro.',
    };

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _metricWrap([
          _PortalMetric(
              'MELHOR PRODUTO',
              _clientFacingLabel(best['description']),
              _money(best['total_sales']),
              Icons.workspace_premium_outlined,
              accent: true),
          _PortalMetric(
              'MAIS SERVIDO',
              _clientFacingLabel(served['description']),
              '${_quantity(served['served_quantity'])} unidades',
              Icons.inventory_2_outlined),
          _PortalMetric('PICO HORÁRIO', peak['hour_label']?.toString() ?? '—',
              _money(peak['total_sales']), Icons.schedule_rounded),
          _PortalMetric('ZONA LÍDER', _clientFacingLabel(leader['label']),
              _money(leader['total_sales']), Icons.layers_outlined),
        ], compact: true, interactive: true),
        const SizedBox(height: 24),
        _sectionTitle('Destaques', 'RANKING POR'),
        const SizedBox(height: 12),
        _rankingScopeSelector(),
        const SizedBox(height: 12),
        _searchField(switch (_rankingScope) {
          'devices' => 'Pesquisar device',
          'products' => 'Pesquisar produto',
          _ => 'Pesquisar zona',
        }),
        const SizedBox(height: 12),
        _rankedPanel(
          title: rankingTitle,
          empty: rankingEmpty,
          items: rankingItems,
          titleOf: (item) => _rankingItemTitle(_rankingScope, item),
          subtitleOf: (item) => _rankingItemSubtitle(_rankingScope, item),
          valueOf: (item) => _money(item['total_sales']),
          onTap: (item) => _showRankingDetails(_rankingScope, item),
        ),
      ],
    );
  }

  Widget _rankingScopeSelector() {
    const options = [
      ('zones', 'Zonas', Icons.layers_outlined),
      ('devices', 'Devices', Icons.storefront_outlined),
      ('products', 'Produtos', Icons.inventory_2_outlined),
    ];

    return Container(
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(
        color: AppColors.surface.withValues(alpha: 0.92),
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: AppColors.border),
      ),
      child: Row(
        children: options.map((option) {
          final selected = _rankingScope == option.$1;
          return Expanded(
            child: InkWell(
              borderRadius: BorderRadius.circular(14),
              onTap: () {
                HapticFeedback.selectionClick();
                _sectionSearchController.clear();
                setState(() {
                  _rankingScope = option.$1;
                  _sectionSearch = '';
                });
              },
              child: AnimatedContainer(
                duration: const Duration(milliseconds: 220),
                padding: const EdgeInsets.symmetric(vertical: 7),
                decoration: BoxDecoration(
                  color: selected ? AppColors.lime : Colors.transparent,
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Icon(option.$3,
                        size: 16,
                        color: selected ? AppColors.navy : AppColors.textMuted),
                    const SizedBox(height: 2),
                    Text(option.$2,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          color: selected ? AppColors.navy : AppColors.textSoft,
                          fontSize: 10.5,
                          fontWeight: FontWeight.w700,
                        )),
                  ],
                ),
              ),
            ),
          );
        }).toList(),
      ),
    );
  }

  String _rankingItemTitle(String scope, Map<String, dynamic> item) =>
      switch (scope) {
        'devices' => _clientFacingLabel(item['store_name'], fallback: 'Device'),
        'products' =>
          _clientFacingLabel(item['description'], fallback: 'Produto'),
        _ => _clientFacingLabel(item['label'], fallback: 'Zona'),
      };

  String _rankingItemSubtitle(String scope, Map<String, dynamic> item) =>
      switch (scope) {
        'devices' =>
          '${_clientFacingLabel(item['zone'], fallback: 'Sem zona')} · ${_quantity(item['tickets_count'])} transações',
        'products' =>
          '${_quantity(item['served_quantity'])} servidos · ${_quantity(item['offered_quantity'])} oferecidos',
        _ =>
          '${_quantity(item['devices_count'])} devices · ${_quantity(item['tickets_count'])} transações',
      };

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
        const SizedBox(height: 24),
        _rankedPanel(
          title: 'INDICADORES OPERACIONAIS',
          empty: 'Sem indicadores.',
          items: metrics,
          titleOf: (item) => _clientFacingLabel(item['label']),
          subtitleOf: (item) =>
              'Anterior: ${_comparisonValue(item, item['previous'])}',
          valueOf: (item) =>
              '${_comparisonValue(item, item['current'])}  ${_variationLabel(item['variation'])}',
          valueColor: (item) => _variationColor(item['variation']),
        ),
        const SizedBox(height: 24),
        _rankedPanel(
          title: 'FORMAS DE PAGAMENTO',
          empty: 'Sem pagamentos.',
          items: payments,
          titleOf: (item) => _clientFacingLabel(item['label']),
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
                _clientFacingLabel(event['title'], fallback: 'Evento'),
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

  List<_MoreAction> get _moreActions => <_MoreAction>[
        if (_sectionIsVisible('zones'))
          _MoreAction(
            _configuredSectionLabel('zones'),
            'Ver faturação, devices e produtos por zona',
            Icons.layers_outlined,
            () => _openSection('zones'),
          ),
        if (_sectionIsVisible('comparison'))
          _MoreAction(
              'Comparar edições',
              'Compare o evento com a edição anterior',
              Icons.compare_arrows_rounded,
              () => _openSection('comparison')),
        _MoreAction(
            'Equipa Contacto Digital',
            'Falar com o suporte pelo WhatsApp',
            Icons.support_agent_rounded,
            _openSupport),
        _MoreAction('Terminar sessão', 'Sair com segurança deste dispositivo',
            Icons.logout_rounded, _logout,
            destructive: true),
      ];

  Widget _morePage() {
    final items = _moreActions;

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

  Widget _metricWrap(
    List<_PortalMetric> metrics, {
    bool compact = false,
    bool interactive = false,
  }) {
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
              .map((metric) => SizedBox(
                    width: width,
                    child: _PortalMetricCard(
                      metric,
                      compact: compact,
                      onTap: interactive
                          ? () => _showPortalMetricDetails(metric)
                          : null,
                    ),
                  ))
              .toList(),
        );
      },
    );
  }

  Future<void> _showPortalMetricDetails(_PortalMetric metric) =>
      _showMetricDetails(
        title: metric.label,
        value: metric.value,
        description: metric.caption,
        icon: metric.icon,
        accent: metric.accent ? AppColors.lime : AppColors.blueBright,
        details: [
          MapEntry('Indicador', metric.label),
          MapEntry('Valor atual', metric.value),
        ],
      );

  Future<void> _showProductRankingDetails(
    Map<String, dynamic> item, {
    required double eventSalesTotal,
  }) =>
      _showMetricDetails(
        title: 'Produto',
        value: _clientFacingLabel(item['description'], fallback: 'Produto'),
        description: 'Desempenho do produto na seleção atual.',
        icon: Icons.inventory_2_outlined,
        accent: AppColors.lime,
        details: [
          MapEntry(
            'Referência',
            _clientFacingLabel(item['product_code'], fallback: '—'),
          ),
          MapEntry('Vendido', '${_quantity(item['sold_quantity'])} un'),
          MapEntry('Oferecido', '${_quantity(item['offered_quantity'])} un'),
          MapEntry('Total servido', '${_quantity(item['served_quantity'])} un'),
          MapEntry('Valor faturado', _money(item['total_sales'])),
          MapEntry(
            '% das vendas',
            eventSalesTotal > 0
                ? '${_decimal(((item['total_sales'] as num?)?.toDouble() ?? 0) / eventSalesTotal * 100)}%'
                : '0,0%',
          ),
        ],
      );

  Future<void> _showReconciliationDetails(Map<String, dynamic> item) {
    final difference = (item['difference'] as num?)?.toDouble() ?? 0;
    final deviceCode = item['store_code']?.toString().trim() ?? '';

    return _showMetricDetails(
      title: 'Device',
      value: _clientFacingLabel(item['store_name'], fallback: 'Sem device'),
      description: difference.abs() < 0.01
          ? 'Pagamentos e vendas estão conciliados.'
          : 'Foi detetada uma diferença entre pagamentos e vendas.',
      icon: Icons.point_of_sale_outlined,
      accent: difference.abs() < 0.01 ? AppColors.success : AppColors.warning,
      details: [
        if (deviceCode.isNotEmpty) MapEntry('Código', deviceCode),
        MapEntry('Pagamentos', _money(item['payments_total'])),
        MapEntry('Vendas', _money(item['sales_total'])),
        MapEntry('Diferença', _differenceLabel(difference)),
        MapEntry(
          'Estado',
          difference.abs() < 0.01 ? 'Conciliado' : 'Diferença detetada',
        ),
      ],
    );
  }

  Future<void> _showRankingDetails(
    String scope,
    Map<String, dynamic> item,
  ) =>
      _showMetricDetails(
        title: switch (scope) {
          'devices' => 'Device',
          'products' => 'Produto',
          _ => 'Zona',
        },
        value: _rankingItemTitle(scope, item),
        description: 'Detalhes do ranking para a seleção atual.',
        icon: switch (scope) {
          'devices' => Icons.storefront_outlined,
          'products' => Icons.inventory_2_outlined,
          _ => Icons.layers_outlined,
        },
        accent: AppColors.lime,
        details: switch (scope) {
          'devices' => [
              MapEntry('Zona',
                  _clientFacingLabel(item['zone'], fallback: 'Sem zona')),
              MapEntry('Transações', _quantity(item['tickets_count'])),
              MapEntry('Faturação', _money(item['total_sales'])),
            ],
          'products' => [
              MapEntry('Vendido', '${_quantity(item['sold_quantity'])} un'),
              MapEntry(
                  'Oferecido', '${_quantity(item['offered_quantity'])} un'),
              MapEntry(
                  'Total servido', '${_quantity(item['served_quantity'])} un'),
              MapEntry('Faturação', _money(item['total_sales'])),
            ],
          _ => [
              MapEntry('Devices', _quantity(item['devices_count'])),
              MapEntry('Transações', _quantity(item['tickets_count'])),
              MapEntry('Participação', '${_decimal(item['share'])}%'),
              MapEntry('Faturação', _money(item['total_sales'])),
            ],
        },
      );

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
    String? helper,
    Widget? action,
    required String empty,
    required List<Map<String, dynamic>> items,
    required String Function(Map<String, dynamic>) titleOf,
    required String Function(Map<String, dynamic>) subtitleOf,
    required String Function(Map<String, dynamic>) valueOf,
    Color Function(Map<String, dynamic>)? valueColor,
    ValueChanged<Map<String, dynamic>>? onTap,
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
            child: Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(title,
                          style: const TextStyle(
                              color: AppColors.textMuted,
                              fontSize: 10,
                              fontWeight: FontWeight.w700,
                              letterSpacing: 1.7)),
                      if (helper != null) ...[
                        const SizedBox(height: 3),
                        Text(
                          helper,
                          style: const TextStyle(
                            color: AppColors.textMuted,
                            fontSize: 10,
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
                if (action != null) action,
              ],
            ),
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
              return Material(
                color: Colors.transparent,
                child: InkWell(
                  onTap: onTap == null
                      ? null
                      : () {
                          HapticFeedback.selectionClick();
                          onTap(item);
                        },
                  child: Container(
                    padding: const EdgeInsets.fromLTRB(16, 13, 16, 13),
                    decoration: BoxDecoration(
                      border: entry.key == items.take(50).length - 1
                          ? null
                          : const Border(
                              top: BorderSide(color: AppColors.border)),
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
                                  style: const TextStyle(
                                      fontWeight: FontWeight.w700)),
                              const SizedBox(height: 2),
                              Text(subtitleOf(item),
                                  maxLines: 2,
                                  overflow: TextOverflow.ellipsis,
                                  style: const TextStyle(
                                      color: AppColors.textMuted,
                                      fontSize: 11)),
                            ],
                          ),
                        ),
                        const SizedBox(width: 10),
                        Text(valueOf(item),
                            textAlign: TextAlign.end,
                            style: TextStyle(
                                color:
                                    valueColor?.call(item) ?? AppColors.white,
                                fontWeight: FontWeight.w700)),
                        if (onTap != null) ...[
                          const SizedBox(width: 5),
                          const Icon(Icons.chevron_right_rounded,
                              size: 17, color: AppColors.textMuted),
                        ],
                      ],
                    ),
                  ),
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
  const _PortalMetricCard(
    this.metric, {
    this.compact = false,
    this.onTap,
  });
  final _PortalMetric metric;
  final bool compact;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(22),
        child: Ink(
          height: compact ? 126 : 180,
          padding: EdgeInsets.all(compact ? 14 : 17),
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
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                              color: AppColors.textMuted,
                              fontSize: 9,
                              fontWeight: FontWeight.w700,
                              letterSpacing: 1.3))),
                  Icon(metric.icon,
                      size: 18,
                      color:
                          metric.accent ? AppColors.lime : AppColors.textMuted),
                ],
              ),
              const Spacer(),
              Text(metric.value,
                  maxLines: compact ? 1 : 2,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                      color: metric.accent ? AppColors.lime : AppColors.white,
                      fontSize: compact ? 19 : 23,
                      fontWeight: FontWeight.w800,
                      letterSpacing: -0.5)),
              const SizedBox(height: 3),
              Row(
                children: [
                  Expanded(
                    child: Text(metric.caption,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                            color: AppColors.textMuted, fontSize: 10)),
                  ),
                  if (onTap != null) const SizedBox(width: 4),
                  if (onTap != null)
                    const Icon(Icons.touch_app_outlined,
                        size: 14, color: AppColors.textMuted),
                ],
              ),
            ],
          ),
        ),
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

/// Mirrors the app's InputDecorationTheme (filled surfaceRaised box, floating
/// label) rather than an OutlinedButton so the label ("Início"/"Fim") stays
/// visible once a date is picked — a plain OutlinedButton.icon swaps its
/// whole label for the date, so two filled-in buttons side by side read as
/// two unlabelled dates with no way to tell which is the start.
class _DateFilterButton extends StatelessWidget {
  const _DateFilterButton(
      {required this.label, required this.value, required this.onTap});
  final String label;
  final DateTime? value;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final text = value == null
        ? 'Selecionar'
        : '${value!.day.toString().padLeft(2, '0')}/${value!.month.toString().padLeft(2, '0')}/${value!.year}';
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(18),
      child: Container(
        height: 58,
        padding: const EdgeInsets.symmetric(horizontal: 16),
        decoration: BoxDecoration(
          color: AppColors.surfaceRaised,
          border: Border.all(color: AppColors.border),
          borderRadius: BorderRadius.circular(18),
        ),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Text(label,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                          color: AppColors.textMuted, fontSize: 11)),
                  const SizedBox(height: 2),
                  Text(text,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                          color: value == null
                              ? AppColors.textMuted
                              : AppColors.white,
                          fontSize: 15,
                          fontWeight: FontWeight.w600)),
                ],
              ),
            ),
            const Icon(Icons.calendar_today_outlined,
                size: 17, color: AppColors.textMuted),
          ],
        ),
      ),
    );
  }
}

/// A removable "you filtered by X" pill for the toolbar — the same rounded,
/// bordered pill language as the section kicker badges, with its own close
/// affordance so one filter can be dropped without opening the sheet.
class _ActiveFilterPill extends StatelessWidget {
  const _ActiveFilterPill({required this.label, required this.onRemove});

  final String label;
  final VoidCallback onRemove;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.only(left: 12, right: 6),
      height: 32,
      decoration: BoxDecoration(
        color: AppColors.surfaceRaised,
        border: Border.all(color: AppColors.border),
        borderRadius: BorderRadius.circular(99),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 140),
            child: Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(
                  color: AppColors.textSoft,
                  fontSize: 12,
                  fontWeight: FontWeight.w600),
            ),
          ),
          const SizedBox(width: 4),
          InkWell(
            onTap: onRemove,
            borderRadius: BorderRadius.circular(99),
            child: const Padding(
              padding: EdgeInsets.all(4),
              child: Icon(Icons.close_rounded,
                  size: 15, color: AppColors.textMuted),
            ),
          ),
        ],
      ),
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
