import 'dart:async';

import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../api_client.dart';
import 'login_screen.dart';

/// v1 screen: login already happened, this shows the client's most recent
/// active event — summary cards (mirrors
/// EventDashboardController::buildSummary() on the web dashboard) plus top
/// stores. Auto-refreshes every 60s: production syncs every 2 minutes
/// (PERF-502), so anything faster would just be extra load for no new data.
class EventSummaryScreen extends StatefulWidget {
  const EventSummaryScreen({super.key, required this.apiClient});

  final ApiClient apiClient;

  @override
  State<EventSummaryScreen> createState() => _EventSummaryScreenState();
}

class _EventSummaryScreenState extends State<EventSummaryScreen> {
  static const _refreshInterval = Duration(seconds: 60);

  Map<String, dynamic>? _event;
  Map<String, dynamic>? _summary;
  List<Map<String, dynamic>> _topStores = [];

  bool _loading = true;
  String? _error;
  Timer? _timer;

  final _currency = NumberFormat.currency(locale: 'pt_PT', symbol: '€');
  final _integer = NumberFormat.decimalPattern('pt_PT');

  @override
  void initState() {
    super.initState();
    _load();
    _timer = Timer.periodic(_refreshInterval, (_) => _load(silent: true));
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _load({bool silent = false}) async {
    if (!silent) setState(() => _loading = true);

    try {
      final events = await widget.apiClient.fetchEvents();

      if (events.isEmpty) {
        setState(() {
          _event = null;
          _error = null;
          _loading = false;
        });
        return;
      }

      final event = events.first;
      final eventId = event['id'] as int;

      final results = await Future.wait([
        widget.apiClient.fetchSummary(eventId),
        widget.apiClient.fetchTopStores(eventId),
      ]);

      if (!mounted) return;
      setState(() {
        _event = event;
        _summary = results[0] as Map<String, dynamic>;
        _topStores = results[1] as List<Map<String, dynamic>>;
        _error = null;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;

      if (e.statusCode == 401 || e.statusCode == 403) {
        Navigator.of(context).pushAndRemoveUntil(
          MaterialPageRoute(
            builder: (_) => LoginScreen(apiClient: widget.apiClient),
          ),
          (route) => false,
        );
        return;
      }

      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  Future<void> _logout() async {
    await widget.apiClient.logout();
    if (!mounted) return;

    Navigator.of(context).pushAndRemoveUntil(
      MaterialPageRoute(builder: (_) => LoginScreen(apiClient: widget.apiClient)),
      (route) => false,
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(_event?['title'] as String? ?? 'O meu evento'),
        actions: [
          IconButton(
            icon: const Icon(Icons.logout),
            tooltip: 'Sair',
            onPressed: _logout,
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: _buildBody(),
      ),
    );
  }

  Widget _buildBody() {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null) {
      return ListView(
        children: [
          const SizedBox(height: 80),
          Icon(Icons.error_outline, size: 48, color: Theme.of(context).colorScheme.error),
          const SizedBox(height: 16),
          Text(_error!, textAlign: TextAlign.center),
          const SizedBox(height: 16),
          Center(
            child: OutlinedButton(onPressed: _load, child: const Text('Tentar novamente')),
          ),
        ],
      );
    }

    if (_event == null) {
      return ListView(
        children: const [
          SizedBox(height: 80),
          Icon(Icons.event_busy, size: 48),
          SizedBox(height: 16),
          Text('Ainda não tens nenhum evento ativo.', textAlign: TextAlign.center),
        ],
      );
    }

    final summary = _summary!;

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        _lastSyncedBanner(summary['last_synced_at'] as String?),
        const SizedBox(height: 16),
        GridView.count(
          crossAxisCount: 2,
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          crossAxisSpacing: 12,
          mainAxisSpacing: 12,
          childAspectRatio: 1.5,
          children: [
            _statCard('Vendas totais', _currency.format(summary['total_sales'])),
            _statCard('Bilhetes', _integer.format(summary['tickets_count'])),
            _statCard('Ticket médio', _currency.format(summary['average_ticket'])),
            _statCard('Lojas', _integer.format(summary['stores_count'])),
          ],
        ),
        const SizedBox(height: 24),
        Text('Top lojas', style: Theme.of(context).textTheme.titleMedium),
        const SizedBox(height: 8),
        if (_topStores.isEmpty)
          const Padding(
            padding: EdgeInsets.symmetric(vertical: 16),
            child: Text('Sem dados de vendas por loja ainda.'),
          )
        else
          Card(
            child: Column(
              children: _topStores.map((store) {
                return ListTile(
                  title: Text(store['store_name'] as String? ?? '—'),
                  trailing: Text(_currency.format(store['total_sales'])),
                );
              }).toList(),
            ),
          ),
      ],
    );
  }

  Widget _lastSyncedBanner(String? lastSyncedAt) {
    if (lastSyncedAt == null) {
      return const Text('Ainda sem dados sincronizados.');
    }

    final parsed = DateTime.tryParse(lastSyncedAt)?.toLocal();
    final formatted = parsed != null
        ? DateFormat('HH:mm:ss', 'pt_PT').format(parsed)
        : lastSyncedAt;

    return Text(
      'Última sincronização: $formatted',
      style: Theme.of(context).textTheme.bodySmall,
    );
  }

  Widget _statCard(String label, String value) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label, style: Theme.of(context).textTheme.bodySmall),
            const SizedBox(height: 4),
            FittedBox(
              fit: BoxFit.scaleDown,
              child: Text(value, style: Theme.of(context).textTheme.headlineSmall),
            ),
          ],
        ),
      ),
    );
  }
}
