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

class StudentDashboardPage extends StatefulWidget {
  const StudentDashboardPage({super.key});

  @override
  State<StudentDashboardPage> createState() => _StudentDashboardPageState();
}

class _StudentDashboardPageState extends State<StudentDashboardPage> {
  late final ApiClient _api;
  bool _isLoading = true;
  String? _error;
  Map<String, dynamic>? _profile;
  List<dynamic> _classes = [];
  List<dynamic> _todayClasses = [];
  Map<String, dynamic>? _attendanceData;
  List<dynamic> _memberships = [];

  @override
  void initState() {
    super.initState();
    _api = ApiClient(storage: SecureStorage());
    _loadData();
  }

  Future<void> _loadData() async {
    setState(() { _isLoading = true; _error = null; });
    try {
      final results = await Future.wait([
        _api.get(ApiEndpoints.studentProfile),
        _api.get(ApiEndpoints.studentClasses),
        _api.get(ApiEndpoints.studentAttendance),
        _api.get(ApiEndpoints.studentMemberships),
      ]);
      final profileResp = results[0].data;
      final classesResp = results[1].data;
      final attendResp = results[2].data;
      final memberResp = results[3].data;

      _profile = profileResp is Map<String, dynamic>
          ? (profileResp['student'] as Map<String, dynamic>? ?? profileResp) : null;
      _classes = classesResp is Map<String, dynamic>
          ? (classesResp['classes'] as List<dynamic>? ?? [])
          : (classesResp is List ? classesResp : []);
      final now = DateTime.now();
      final todayDay = _dayOfWeekName(now.weekday);
      _todayClasses = _classes.where((c) {
        final d = (c['day_of_week'] ?? '').toString().toLowerCase();
        return d == todayDay.toLowerCase();
      }).toList();
      _attendanceData = attendResp is Map<String, dynamic> ? attendResp : null;
      _memberships = memberResp is Map<String, dynamic>
          ? (memberResp['memberships'] as List<dynamic>? ?? [])
          : (memberResp is List ? memberResp : []);
      setState(() => _isLoading = false);
    } catch (e) {
      setState(() { _isLoading = false; _error = 'Failed to load dashboard. Please try again.'; });
    }
  }

  String _dayOfWeekName(int weekday) {
    const days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
    return days[weekday - 1];
  }

  UserModel _getUser() {
    final state = context.read<AuthBloc>().state;
    if (state is AuthAuthenticated) return state.user;
    return UserModel(id: 0, email: '', userType: 'student', schoolId: 1);
  }

  @override
  Widget build(BuildContext context) {
    final user = _getUser();
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(
        title: const Text('Dashboard'),
        actions: [
          IconButton(icon: const Icon(Icons.notifications_outlined),
              onPressed: () => context.go('/student/messages')),
        ],
      ),
      drawer: AppDrawer(user: user),
      body: _isLoading
          ? const AppLoadingIndicator(message: 'Loading dashboard...')
          : _error != null
              ? AppErrorWidget(message: _error!, onRetry: _loadData)
              : RefreshIndicator(
                  onRefresh: _loadData,
                  child: ListView(padding: const EdgeInsets.all(16), children: [
                    _buildWelcomeCard(user, theme),
                    const SizedBox(height: 16),
                    _buildStatsRow(theme),
                    const SizedBox(height: 24),
                    _buildSectionHeader("Today's Classes", theme),
                    const SizedBox(height: 8),
                    _buildTodayClasses(theme),
                    const SizedBox(height: 24),
                    _buildSectionHeader('Quick Actions', theme),
                    const SizedBox(height: 8),
                    _buildQuickActions(theme),
                    const SizedBox(height: 24),
                    if (_memberships.isNotEmpty) ...[
                      _buildSectionHeader('Membership', theme),
                      const SizedBox(height: 8),
                      _buildMembershipCard(theme),
                    ],
                    const SizedBox(height: 16),
                  ]),
                ),
    );
  }

  Widget _buildWelcomeCard(UserModel user, ThemeData theme) {
    final beltRank = _profile?['belt_rank'] ?? user.beltRank ?? 'N/A';
    final displayName = _profile?['full_name'] ?? _profile?['first_name'] ?? user.displayName;
    return Card(
      elevation: 2,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      child: Container(
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(16),
          gradient: LinearGradient(
            colors: [theme.colorScheme.primary, theme.colorScheme.primary.withOpacity(0.7)],
            begin: Alignment.topLeft, end: Alignment.bottomRight,
          ),
        ),
        padding: const EdgeInsets.all(20),
        child: Row(children: [
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('Welcome back,', style: theme.textTheme.bodyMedium?.copyWith(color: Colors.white.withOpacity(0.9))),
            const SizedBox(height: 4),
            Text(displayName.toString(), style: theme.textTheme.headlineSmall?.copyWith(color: Colors.white, fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
              decoration: BoxDecoration(
                color: Colors.white.withOpacity(0.2), borderRadius: BorderRadius.circular(20),
                border: Border.all(color: Colors.white.withOpacity(0.3)),
              ),
              child: Row(mainAxisSize: MainAxisSize.min, children: [
                Icon(Icons.military_tech, size: 16, color: BeltColors.fromName(beltRank.toString())),
                const SizedBox(width: 4),
                Text('$beltRank Belt', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600, fontSize: 13)),
              ]),
            ),
          ])),
          CircleAvatar(radius: 32, backgroundColor: Colors.white.withOpacity(0.2),
            child: Text(displayName.toString().isNotEmpty ? displayName.toString()[0].toUpperCase() : '?',
              style: const TextStyle(fontSize: 28, fontWeight: FontWeight.bold, color: Colors.white))),
        ]),
      ),
    );
  }

  Widget _buildStatsRow(ThemeData theme) {
    final records = _attendanceData?['records'] as List<dynamic>? ?? [];
    final total = _attendanceData?['pagination']?['total'] ?? records.length;
    final present = records.where((r) => (r['status'] ?? '').toString().toLowerCase() == 'present').length;
    final rate = total > 0 ? ((present / total) * 100).toStringAsFixed(0) : '0';
    return Row(children: [
      Expanded(child: StatCard(title: 'Attendance', value: '$rate%', icon: Icons.fact_check, color: Colors.green)),
      const SizedBox(width: 8),
      Expanded(child: StatCard(title: 'Classes', value: '${_classes.length}', icon: Icons.class_, color: Colors.blue)),
      const SizedBox(width: 8),
      Expanded(child: StatCard(title: 'Today', value: '${_todayClasses.length}', icon: Icons.today, color: Colors.orange)),
    ]);
  }

  Widget _buildSectionHeader(String title, ThemeData theme) =>
      Text(title, style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.bold));

  Widget _buildTodayClasses(ThemeData theme) {
    if (_todayClasses.isEmpty) {
      return Card(child: Padding(padding: const EdgeInsets.all(24), child: Column(children: [
        Icon(Icons.event_available, size: 48, color: Colors.grey[400]),
        const SizedBox(height: 8),
        Text('No classes scheduled for today', style: TextStyle(color: Colors.grey[600])),
      ])));
    }
    return Column(children: _todayClasses.map((cls) {
      final className = cls['class_name'] ?? cls['name'] ?? 'Unknown Class';
      final instructor = cls['instructor_name'] ?? 'TBA';
      final room = cls['room'] ?? '';
      final startTime = _formatTime((cls['start_time'] ?? '').toString());
      final endTime = _formatTime((cls['end_time'] ?? '').toString());
      final details = '$startTime - $endTime'
          '${instructor.toString().isNotEmpty ? " \u2022 $instructor" : ""}'
          '${room.toString().isNotEmpty ? " \u2022 $room" : ""}';
      return Card(margin: const EdgeInsets.only(bottom: 8), child: ListTile(
        leading: CircleAvatar(backgroundColor: theme.colorScheme.primaryContainer,
          child: Icon(Icons.class_, color: theme.colorScheme.onPrimaryContainer)),
        title: Text(className.toString(), style: const TextStyle(fontWeight: FontWeight.w600)),
        subtitle: Text(details),
        trailing: const Icon(Icons.chevron_right),
        onTap: () => context.go('/student/classes'),
      ));
    }).toList());
  }

  Widget _buildQuickActions(ThemeData theme) {
    final actions = [
      _QuickAction(icon: Icons.class_, label: 'My Classes', color: Colors.blue, route: '/student/classes'),
      _QuickAction(icon: Icons.fact_check, label: 'Attendance', color: Colors.green, route: '/student/attendance'),
      _QuickAction(icon: Icons.event, label: 'Events', color: Colors.orange, route: '/student/events'),
      _QuickAction(icon: Icons.military_tech, label: 'Belt Progress', color: Colors.purple, route: '/student/belts'),
      _QuickAction(icon: Icons.message, label: 'Messages', color: Colors.teal, route: '/student/messages'),
      _QuickAction(icon: Icons.person, label: 'Profile', color: Colors.indigo, route: '/student/profile'),
    ];
    return GridView.count(crossAxisCount: 3, shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(), mainAxisSpacing: 8, crossAxisSpacing: 8,
      children: actions.map((a) => Card(elevation: 1,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        child: InkWell(borderRadius: BorderRadius.circular(12), onTap: () => context.go(a.route),
          child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
            Container(padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(color: a.color.withOpacity(0.1), shape: BoxShape.circle),
              child: Icon(a.icon, color: a.color, size: 28)),
            const SizedBox(height: 8),
            Text(a.label, textAlign: TextAlign.center,
              style: theme.textTheme.bodySmall?.copyWith(fontWeight: FontWeight.w600)),
          ])))).toList());
  }

  Widget _buildMembershipCard(ThemeData theme) {
    final m = _memberships.isNotEmpty ? _memberships.first : null;
    if (m == null) return const SizedBox.shrink();
    final name = m['membership_name'] ?? m['name'] ?? 'N/A';
    final status = (m['status'] ?? 'unknown').toString();
    final endDate = m['end_date'] ?? '';
    return Card(shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: Padding(padding: const EdgeInsets.all(16), child: Row(children: [
        Container(padding: const EdgeInsets.all(10),
          decoration: BoxDecoration(color: theme.colorScheme.primaryContainer, borderRadius: BorderRadius.circular(12)),
          child: Icon(Icons.card_membership, color: theme.colorScheme.onPrimaryContainer, size: 28)),
        const SizedBox(width: 16),
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(name.toString(), style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.bold)),
          const SizedBox(height: 4),
          if (endDate.toString().isNotEmpty)
            Text('Expires: $endDate', style: theme.textTheme.bodySmall?.copyWith(color: Colors.grey[600])),
        ])),
        StatusBadge(label: status[0].toUpperCase() + status.substring(1),
          color: status.toLowerCase() == 'active' ? Colors.green
              : status.toLowerCase() == 'expired' ? Colors.red : Colors.orange),
      ])));
  }

  String _formatTime(String time) {
    if (time.isEmpty) return '';
    try {
      final parts = time.split(':');
      if (parts.length >= 2) {
        final hour = int.parse(parts[0]);
        final minute = parts[1];
        final period = hour >= 12 ? 'PM' : 'AM';
        final displayHour = hour > 12 ? hour - 12 : (hour == 0 ? 12 : hour);
        return '$displayHour:$minute $period';
      }
    } catch (_) {}
    return time;
  }
}

class _QuickAction {
  final IconData icon;
  final String label;
  final Color color;
  final String route;
  const _QuickAction({required this.icon, required this.label, required this.color, required this.route});
}
