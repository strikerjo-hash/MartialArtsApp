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

class ParentChildDetailPage extends StatefulWidget {
  final int childId;
  const ParentChildDetailPage({super.key, required this.childId});
  @override
  State<ParentChildDetailPage> createState() => _ParentChildDetailPageState();
}

class _ParentChildDetailPageState extends State<ParentChildDetailPage>
    with SingleTickerProviderStateMixin {
  final _api = ApiClient(storage: SecureStorage());
  late TabController _tabController;
  bool _isLoading = true;
  String? _error;
  Map<String, dynamic> _overview = {};
  List<Map<String, dynamic>> _classes = [];
  List<Map<String, dynamic>> _attendance = [];
  List<Map<String, dynamic>> _events = [];
  List<Map<String, dynamic>> _belts = [];
  static const _tabs = ['Overview', 'Classes', 'Attendance', 'Events', 'Belts'];
  static const _tabResources = ['overview', 'classes', 'attendance', 'events', 'belts'];

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: _tabs.length, vsync: this);
    _tabController.addListener(_onTabChanged);
    _loadData();
  }

  @override
  void dispose() {
    _tabController.removeListener(_onTabChanged);
    _tabController.dispose();
    super.dispose();
  }

  void _onTabChanged() {
    if (!_tabController.indexIsChanging) return;
    _loadTabData(_tabController.index);
  }

  Future<void> _loadData() async {
    setState(() { _isLoading = true; _error = null; });
    try {
      final response = await _api.get(ApiEndpoints.parentChild(widget.childId, 'overview'));
      final data = response.data;
      setState(() { _overview = data is Map<String, dynamic> ? data : {}; _isLoading = false; });
    } catch (e) {
      setState(() { _error = 'Failed to load child details.'; _isLoading = false; });
    }
  }

  Future<void> _loadTabData(int tabIndex) async {
    final resource = _tabResources[tabIndex];
    if (tabIndex == 0) return;
    try {
      final response = await _api.get(ApiEndpoints.parentChild(widget.childId, resource));
      final data = response.data;
      final List<dynamic> rawList = data is Map && data.containsKey(resource)
          ? data[resource] as List<dynamic>
          : data is List ? data : [];
      setState(() {
        switch (tabIndex) {
          case 1: _classes = rawList.cast<Map<String, dynamic>>(); break;
          case 2: _attendance = rawList.cast<Map<String, dynamic>>(); break;
          case 3: _events = rawList.cast<Map<String, dynamic>>(); break;
          case 4: _belts = rawList.cast<Map<String, dynamic>>(); break;
        }
      });
    } catch (e) { /* pull to refresh */ }
  }

  Future<void> _refreshCurrentTab() async {
    final index = _tabController.index;
    if (index == 0) { await _loadData(); } else { await _loadTabData(index); }
  }

  @override
  Widget build(BuildContext context) {
    final authState = context.watch<AuthBloc>().state;
    final user = authState is AuthAuthenticated ? authState.user : null;
    final firstName = _overview['first_name'] ?? '';
    final lastName = _overview['last_name'] ?? '';
    final childName = _overview['full_name'] as String? ?? '$firstName $lastName'.trim();

    return Scaffold(
      appBar: AppBar(
        title: Text(childName.isNotEmpty ? childName : 'Child Details'),
        bottom: TabBar(
          controller: _tabController, isScrollable: true,
          tabs: _tabs.map((t) => Tab(text: t)).toList(),
        ),
      ),
      drawer: user != null ? AppDrawer(user: user) : null,
      body: _isLoading
          ? const AppLoadingIndicator(message: 'Loading child details...')
          : _error != null
              ? AppErrorWidget(message: _error!, onRetry: _loadData)
              : TabBarView(controller: _tabController, children: [
                  _buildOverviewTab(), _buildClassesTab(),
                  _buildAttendanceTab(), _buildEventsTab(), _buildBeltsTab(),
                ]),
    );
  }

  Widget _buildOverviewTab() {
    final theme = Theme.of(context);
    final beltRank = _overview['belt_rank'] as String?;
    final status = _overview['status'] as String? ?? 'active';
    final email = _overview['email'] as String?;
    final phone = _overview['phone'] as String?;

    return RefreshIndicator(
      onRefresh: _refreshCurrentTab,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Card(
            child: Padding(
              padding: const EdgeInsets.all(20),
              child: Column(
                children: [
                  CircleAvatar(
                    radius: 40,
                    backgroundColor: theme.colorScheme.primaryContainer,
                    child: Icon(Icons.person, size: 40, color: theme.colorScheme.onPrimaryContainer),
                  ),
                  const SizedBox(height: 12),
                  if (beltRank != null && beltRank.isNotEmpty) ...[
                    Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Icon(Icons.military_tech, color: BeltColors.fromName(beltRank)),
                        const SizedBox(width: 4),
                        Text('$beltRank Belt', style: theme.textTheme.titleMedium),
                      ],
                    ),
                    const SizedBox(height: 8),
                  ],
                  StatusBadge(
                    label: status[0].toUpperCase() + status.substring(1),
                    color: status.toLowerCase() == 'active' ? Colors.green : Colors.grey,
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 12),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('Contact Information', style: theme.textTheme.titleSmall),
                  const Divider(),
                  if (email != null && email.isNotEmpty) _infoRow(Icons.email, 'Email', email),
                  if (phone != null && phone.isNotEmpty) _infoRow(Icons.phone, 'Phone', phone),
                  if ((email == null || email.isEmpty) && (phone == null || phone.isEmpty))
                    const Text('No contact information available.'),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _infoRow(IconData icon, String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        children: [
          Icon(icon, size: 18, color: Colors.grey[600]),
          const SizedBox(width: 12),
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(label, style: TextStyle(fontSize: 12, color: Colors.grey[500])),
              Text(value, style: const TextStyle(fontSize: 14)),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildClassesTab() {
    final theme = Theme.of(context);
    return RefreshIndicator(
      onRefresh: _refreshCurrentTab,
      child: _classes.isEmpty
          ? ListView(children: const [
              SizedBox(height: 100),
              AppEmptyState(icon: Icons.class_outlined, title: 'No Classes',
                subtitle: 'This child is not enrolled in any classes.'),
            ])
          : ListView.builder(
              padding: const EdgeInsets.all(16),
              itemCount: _classes.length,
              itemBuilder: (context, index) {
                final cls = _classes[index];
                final name = cls['class_name'] as String? ?? 'Unnamed Class';
                final dayOfWeek = cls['day_of_week'] as String? ?? '';
                final startTime = cls['start_time'] as String? ?? '';
                final endTime = cls['end_time'] as String? ?? '';
                final instructor = cls['instructor_name'] as String? ?? '';
                return Card(
                  margin: const EdgeInsets.only(bottom: 8),
                  child: ListTile(
                    leading: CircleAvatar(
                      backgroundColor: theme.colorScheme.secondaryContainer,
                      child: Icon(Icons.class_, color: theme.colorScheme.onSecondaryContainer),
                    ),
                    title: Text(name),
                    subtitle: Text(
                      '$dayOfWeek  $startTime - $endTime'
                      '${instructor.isNotEmpty ? "\nInstructor: $instructor" : ""}',
                    ),
                    isThreeLine: instructor.isNotEmpty,
                  ),
                );
              },
            ),
    );
  }

  Widget _buildAttendanceTab() {
    return RefreshIndicator(
      onRefresh: _refreshCurrentTab,
      child: _attendance.isEmpty
          ? ListView(children: const [
              SizedBox(height: 100),
              AppEmptyState(icon: Icons.fact_check_outlined, title: 'No Attendance Records'),
            ])
          : ListView.builder(
              padding: const EdgeInsets.all(16),
              itemCount: _attendance.length,
              itemBuilder: (context, index) {
                final record = _attendance[index];
                final date = record['date'] as String? ?? '';
                final status = record['status'] as String? ?? 'unknown';
                final className = record['class_name'] as String? ?? '';
                return Card(
                  margin: const EdgeInsets.only(bottom: 8),
                  child: ListTile(
                    leading: Icon(AttendanceStatus.icon(status),
                        color: AttendanceStatus.color(status), size: 28),
                    title: Text(className.isNotEmpty ? className : date),
                    subtitle: className.isNotEmpty ? Text(date) : null,
                    trailing: StatusBadge(
                      label: status[0].toUpperCase() + status.substring(1),
                      color: AttendanceStatus.color(status),
                    ),
                  ),
                );
              },
            ),
    );
  }

  Widget _buildEventsTab() {
    final theme = Theme.of(context);
    return RefreshIndicator(
      onRefresh: _refreshCurrentTab,
      child: _events.isEmpty
          ? ListView(children: const [
              SizedBox(height: 100),
              AppEmptyState(icon: Icons.event_outlined, title: 'No Events'),
            ])
          : ListView.builder(
              padding: const EdgeInsets.all(16),
              itemCount: _events.length,
              itemBuilder: (context, index) {
                final event = _events[index];
                final name = event['name'] as String? ??
                    event['event_name'] as String? ?? 'Unnamed Event';
                final type = event['event_type'] as String? ?? 'other';
                final date = event['event_date'] as String? ?? '';
                final regStatus = event['registration_status'] as String? ?? '';
                return Card(
                  margin: const EdgeInsets.only(bottom: 8),
                  child: ListTile(
                    leading: CircleAvatar(
                      backgroundColor: theme.colorScheme.tertiaryContainer,
                      child: Icon(EventTypes.icon(type),
                          color: theme.colorScheme.onTertiaryContainer),
                    ),
                    title: Text(name),
                    subtitle: Text('${EventTypes.label(type)}  -  $date'),
                    trailing: regStatus.isNotEmpty
                        ? StatusBadge(label: regStatus,
                            color: regStatus.toLowerCase() == 'registered'
                                ? Colors.green : Colors.orange)
                        : null,
                  ),
                );
              },
            ),
    );
  }

  Widget _buildBeltsTab() {
    final theme = Theme.of(context);
    return RefreshIndicator(
      onRefresh: _refreshCurrentTab,
      child: _belts.isEmpty
          ? ListView(children: const [
              SizedBox(height: 100),
              AppEmptyState(icon: Icons.military_tech_outlined, title: 'No Belt History'),
            ])
          : ListView.builder(
              padding: const EdgeInsets.all(16),
              itemCount: _belts.length,
              itemBuilder: (context, index) {
                final belt = _belts[index];
                final rank = belt['belt_rank'] as String? ??
                    belt['rank'] as String? ?? 'Unknown';
                final awardedDate = belt['awarded_date'] as String? ??
                    belt['date_awarded'] as String? ?? '';
                final awardedBy = belt['awarded_by'] as String? ?? '';
                return Card(
                  margin: const EdgeInsets.only(bottom: 8),
                  child: ListTile(
                    leading: CircleAvatar(
                      backgroundColor: BeltColors.fromName(rank).withOpacity(0.2),
                      child: Icon(Icons.military_tech,
                          color: BeltColors.fromName(rank) == Colors.white
                              ? Colors.grey : BeltColors.fromName(rank)),
                    ),
                    title: Text('$rank Belt'),
                    subtitle: Text(
                        awardedDate.isNotEmpty ? 'Awarded: $awardedDate' : ''),
                    trailing: awardedBy.isNotEmpty
                        ? Text('By: $awardedBy', style: theme.textTheme.bodySmall)
                        : null,
                  ),
                );
              },
            ),
    );
  }
}
