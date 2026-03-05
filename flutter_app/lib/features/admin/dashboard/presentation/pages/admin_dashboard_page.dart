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

class AdminDashboardPage extends StatefulWidget {
  const AdminDashboardPage({super.key});
  @override
  State<AdminDashboardPage> createState() => _AdminDashboardPageState();
}

class _AdminDashboardPageState extends State<AdminDashboardPage> {
  final _api = ApiClient(storage: SecureStorage());
  bool _isLoading = true;
  String? _error;
  int _activeStudents = 0;
  int _activeClasses = 0;
  int _todayAttendance = 0;
  double _monthlyRevenue = 0;
  List<Map<String, dynamic>> _recentStudents = [];
  List<Map<String, dynamic>> _upcomingEvents = [];

  @override
  void initState() {
    super.initState();
    _loadData();
  }

  Future<void> _loadData() async {
    setState(() { _isLoading = true; _error = null; });
    try {
      final response = await _api.get(ApiEndpoints.adminDashboard);
      final data = response.data as Map<String, dynamic>;
      setState(() {
        _activeStudents = (data['active_students'] as num?)?.toInt() ?? 0;
        _activeClasses = (data['active_classes'] as num?)?.toInt() ?? 0;
        _todayAttendance = (data['today_attendance'] as num?)?.toInt() ?? 0;
        _monthlyRevenue = (data['monthly_revenue'] as num?)?.toDouble() ?? 0;
        _recentStudents = (data['recent_students'] as List<dynamic>? ?? []).cast<Map<String, dynamic>>();
        _upcomingEvents = (data['upcoming_events'] as List<dynamic>? ?? []).cast<Map<String, dynamic>>();
        _isLoading = false;
      });
    } catch (e) {
      setState(() { _error = 'Failed to load dashboard data.'; _isLoading = false; });
    }
  }

  @override
  Widget build(BuildContext context) {
    final authState = context.watch<AuthBloc>().state;
    final user = authState is AuthAuthenticated ? authState.user : null;
    return Scaffold(
      appBar: AppBar(title: const Text('Admin Dashboard')),
      drawer: user != null ? AppDrawer(user: user) : null,
      body: _buildBody(),
    );
  }

  Widget _buildBody() {
    if (_isLoading) return const AppLoadingIndicator(message: 'Loading dashboard...');
    if (_error != null) return AppErrorWidget(message: _error!, onRetry: _loadData);
    final theme = Theme.of(context);
    return RefreshIndicator(
      onRefresh: _loadData,
      child: ListView(padding: const EdgeInsets.all(16), children: [
        _buildStatsGrid(theme),
        const SizedBox(height: 24),
        _buildSectionHeader(theme, 'Recent Students', Icons.people,
            onViewAll: () => context.go('/admin/students')),
        const SizedBox(height: 8),
        _buildRecentStudentsList(theme),
        const SizedBox(height: 24),
        _buildSectionHeader(theme, 'Upcoming Events', Icons.event,
            onViewAll: () => context.go('/admin/events')),
        const SizedBox(height: 8),
        _buildUpcomingEventsList(theme),
        const SizedBox(height: 24),
      ]),
    );
  }

  Widget _buildStatsGrid(ThemeData theme) {
    return GridView.count(
      crossAxisCount: 2,
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      crossAxisSpacing: 12,
      mainAxisSpacing: 12,
      childAspectRatio: 1.5,
      children: [
        StatCard(title: 'Active Students', value: '$_activeStudents', icon: Icons.people, color: Colors.blue),
        StatCard(title: 'Active Classes', value: '$_activeClasses', icon: Icons.class_, color: Colors.green),
        StatCard(title: "Today's Attendance", value: '$_todayAttendance', icon: Icons.fact_check, color: Colors.orange),
        StatCard(title: 'Monthly Revenue', value: '\$${_monthlyRevenue.toStringAsFixed(0)}', icon: Icons.attach_money, color: Colors.purple),
      ],
    );
  }

  Widget _buildSectionHeader(ThemeData theme, String title, IconData icon, {VoidCallback? onViewAll}) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Row(children: [
          Icon(icon, size: 20, color: theme.colorScheme.primary),
          const SizedBox(width: 8),
          Text(title, style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.bold)),
        ]),
        if (onViewAll != null) TextButton(onPressed: onViewAll, child: const Text('View All')),
      ],
    );
  }

  Widget _buildRecentStudentsList(ThemeData theme) {
    if (_recentStudents.isEmpty) {
      return const Card(child: Padding(padding: EdgeInsets.all(24), child: Center(child: Text('No recent students.'))));
    }
    return Card(
      child: Column(
        children: _recentStudents.take(5).map((student) {
          final name = student['full_name'] as String? ??
              '${student['first_name'] ?? ''} ${student['last_name'] ?? ''}'.trim();
          final email = student['email'] as String? ?? '';
          final beltRank = student['belt_rank'] as String?;
          return ListTile(
            leading: CircleAvatar(
              backgroundColor: theme.colorScheme.primaryContainer,
              child: Text(name.isNotEmpty ? name[0].toUpperCase() : '?',
                  style: TextStyle(fontWeight: FontWeight.bold, color: theme.colorScheme.onPrimaryContainer)),
            ),
            title: Text(name.isNotEmpty ? name : 'Unknown'),
            subtitle: Text(email),
            trailing: beltRank != null && beltRank.isNotEmpty
                ? Chip(label: Text(beltRank, style: const TextStyle(fontSize: 11)),
                    avatar: Icon(Icons.military_tech, size: 16, color: BeltColors.fromName(beltRank)),
                    padding: EdgeInsets.zero, materialTapTargetSize: MaterialTapTargetSize.shrinkWrap)
                : null,
            onTap: () { final id = student['id'] as int?; if (id != null) context.push('/admin/students/$id'); },
          );
        }).toList(),
      ),
    );
  }

  Widget _buildUpcomingEventsList(ThemeData theme) {
    if (_upcomingEvents.isEmpty) {
      return const Card(child: Padding(padding: EdgeInsets.all(24), child: Center(child: Text('No upcoming events.'))));
    }
    return Card(
      child: Column(
        children: _upcomingEvents.take(5).map((event) {
          final name = event['name'] as String? ?? event['event_name'] as String? ?? 'Unnamed Event';
          final type = event['event_type'] as String? ?? 'other';
          final date = event['event_date'] as String? ?? '';
          final registered = (event['registered_count'] as num?)?.toInt() ?? 0;
          return ListTile(
            leading: CircleAvatar(
              backgroundColor: theme.colorScheme.tertiaryContainer,
              child: Icon(EventTypes.icon(type), color: theme.colorScheme.onTertiaryContainer),
            ),
            title: Text(name),
            subtitle: Text('${EventTypes.label(type)}  -  $date'),
            trailing: Chip(label: Text('$registered', style: const TextStyle(fontSize: 12)),
                avatar: const Icon(Icons.person, size: 14),
                padding: EdgeInsets.zero, materialTapTargetSize: MaterialTapTargetSize.shrinkWrap),
          );
        }).toList(),
      ),
    );
  }
}
