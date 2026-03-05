import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';
import 'package:martial_arts_app/core/constants/api_endpoints.dart';
import 'package:martial_arts_app/core/constants/app_constants.dart';
import 'package:martial_arts_app/core/network/api_client.dart';
import 'package:martial_arts_app/core/storage/secure_storage.dart';
import 'package:martial_arts_app/features/auth/data/models/user_model.dart';
import 'package:martial_arts_app/features/auth/presentation/bloc/auth_bloc.dart';
import 'package:martial_arts_app/shared/widgets/app_drawer.dart';
import 'package:martial_arts_app/shared/widgets/common_widgets.dart';

class StudentEventsPage extends StatefulWidget {
  const StudentEventsPage({super.key});

  @override
  State<StudentEventsPage> createState() => _StudentEventsPageState();
}

class _StudentEventsPageState extends State<StudentEventsPage> with SingleTickerProviderStateMixin {
  late final ApiClient _api;
  late final TabController _tabController;
  bool _isLoading = true;
  String? _error;
  List<dynamic> _events = [];

  static const _tabs = ['All', 'Belt Test', 'Tournament', 'Seminar', 'Workshop'];
  static const _tabTypes = ['', 'belt_test', 'tournament', 'seminar', 'workshop'];

  @override
  void initState() {
    super.initState();
    _api = ApiClient(storage: SecureStorage());
    _tabController = TabController(length: _tabs.length, vsync: this);
    _loadData();
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  Future<void> _loadData() async {
    setState(() { _isLoading = true; _error = null; });
    try {
      final response = await _api.get(ApiEndpoints.studentEvents);
      final data = response.data;
      _events = data is Map<String, dynamic>
          ? (data['events'] as List<dynamic>? ?? []) : (data is List ? data : []);
      setState(() => _isLoading = false);
    } catch (e) {
      setState(() { _isLoading = false; _error = 'Failed to load events. Please try again.'; });
    }
  }

  Future<void> _registerForEvent(int eventId) async {
    try {
      await _api.post(ApiEndpoints.studentEventRegister(eventId));
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Successfully registered for event!'), backgroundColor: Colors.green));
      _loadData();
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Failed to register. Please try again.'), backgroundColor: Colors.red));
    }
  }

  UserModel _getUser() {
    final state = context.read<AuthBloc>().state;
    if (state is AuthAuthenticated) return state.user;
    return UserModel(id: 0, email: '', userType: 'student', schoolId: 1);
  }

  List<dynamic> _filteredEvents(int tabIndex) {
    if (tabIndex == 0) return _events;
    final type = _tabTypes[tabIndex];
    return _events.where((e) => (e['event_type'] ?? e['type'] ?? '').toString().toLowerCase() == type).toList();
  }

  @override
  Widget build(BuildContext context) {
    final user = _getUser();
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(
        title: const Text('Events'),
        bottom: TabBar(controller: _tabController, isScrollable: true,
          tabs: _tabs.map((t) => Tab(text: t)).toList(),
          onTap: (_) => setState(() {})),
      ),
      drawer: AppDrawer(user: user),
      body: _isLoading
          ? const AppLoadingIndicator(message: 'Loading events...')
          : _error != null
              ? AppErrorWidget(message: _error!, onRetry: _loadData)
              : RefreshIndicator(
                  onRefresh: _loadData,
                  child: Builder(builder: (ctx) {
                    final filtered = _filteredEvents(_tabController.index);
                    if (filtered.isEmpty) {
                      return const Center(child: AppEmptyState(
                          icon: Icons.event, title: 'No Events Found',
                          subtitle: 'No events match the selected filter.'));
                    }
                    return ListView.builder(
                      padding: const EdgeInsets.all(16),
                      itemCount: filtered.length,
                      itemBuilder: (ctx, i) => _buildEventCard(filtered[i], theme),
                    );
                  }),
                ),
    );
  }

  Widget _buildEventCard(dynamic event, ThemeData theme) {
    final name = event['event_name'] ?? event['name'] ?? 'Unknown Event';
    final eventType = (event['event_type'] ?? event['type'] ?? '').toString();
    final date = event['event_date'] ?? event['date'] ?? '';
    final location = event['location'] ?? '';
    final fee = event['registration_fee'] ?? event['fee'];
    final isRegistered = event['is_registered'] == true || event['is_registered'] == 1;
    final eventId = event['id'] ?? event['event_id'];

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: Padding(padding: const EdgeInsets.all(16), child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            Container(
              padding: const EdgeInsets.all(8),
              decoration: BoxDecoration(
                color: theme.colorScheme.primaryContainer, borderRadius: BorderRadius.circular(8)),
              child: Icon(EventTypes.icon(eventType), color: theme.colorScheme.onPrimaryContainer, size: 24),
            ),
            const SizedBox(width: 12),
            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(name.toString(), style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.bold)),
              const SizedBox(height: 2),
              Text(EventTypes.label(eventType), style: TextStyle(color: Colors.grey[600], fontSize: 12)),
            ])),
            if (isRegistered)
              StatusBadge(label: 'Registered', color: Colors.green),
          ]),
          const SizedBox(height: 12),
          const Divider(height: 1),
          const SizedBox(height: 12),
          Row(children: [
            if (date.toString().isNotEmpty) ...[
              Icon(Icons.calendar_today, size: 14, color: Colors.grey[600]),
              const SizedBox(width: 4),
              Text(date.toString(), style: TextStyle(fontSize: 13, color: Colors.grey[700])),
              const SizedBox(width: 16),
            ],
            if (location.toString().isNotEmpty) ...[
              Icon(Icons.location_on, size: 14, color: Colors.grey[600]),
              const SizedBox(width: 4),
              Expanded(child: Text(location.toString(),
                  style: TextStyle(fontSize: 13, color: Colors.grey[700]), overflow: TextOverflow.ellipsis)),
            ],
          ]),
          if (fee != null) ...[
            const SizedBox(height: 8),
            Row(children: [
              Icon(Icons.attach_money, size: 14, color: Colors.grey[600]),
              const SizedBox(width: 4),
              Text('Fee: \$$fee', style: TextStyle(fontSize: 13, color: Colors.grey[700], fontWeight: FontWeight.w600)),
            ]),
          ],
          if (!isRegistered && eventId != null) ...[
            const SizedBox(height: 12),
            SizedBox(width: double.infinity, child: ElevatedButton.icon(
              onPressed: () => _registerForEvent(eventId is int ? eventId : int.tryParse(eventId.toString()) ?? 0),
              icon: const Icon(Icons.how_to_reg, size: 18),
              label: const Text('Register'),
              style: ElevatedButton.styleFrom(
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8))),
            )),
          ],
        ],
      )),
    );
  }
}
