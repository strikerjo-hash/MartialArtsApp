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

class AdminClassesPage extends StatefulWidget {
  const AdminClassesPage({super.key});
  @override
  State<AdminClassesPage> createState() => _AdminClassesPageState();
}

class _AdminClassesPageState extends State<AdminClassesPage> {
  final _api = ApiClient(storage: SecureStorage());
  bool _isLoading = true;
  String? _error;
  List<Map<String, dynamic>> _classes = [];

  static const _dayOrder = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

  @override
  void initState() { super.initState(); _loadData(); }

  Future<void> _loadData() async {
    setState(() { _isLoading = true; _error = null; });
    try {
      final response = await _api.get(ApiEndpoints.adminClasses);
      final data = response.data;
      final List<dynamic> raw = data is Map && data.containsKey('classes')
          ? data['classes'] as List<dynamic>
          : data is List ? data : [];
      setState(() { _classes = raw.cast<Map<String, dynamic>>(); _isLoading = false; });
    } catch (e) {
      setState(() { _error = 'Failed to load classes.'; _isLoading = false; });
    }
  }

  Map<String, List<Map<String, dynamic>>> _groupByDay() {
    final grouped = <String, List<Map<String, dynamic>>>{};
    for (final cls in _classes) {
      final day = cls['day_of_week'] as String? ?? 'Unknown';
      grouped.putIfAbsent(day, () => []).add(cls);
    }
    final sorted = <String, List<Map<String, dynamic>>>{};
    for (final day in _dayOrder) {
      if (grouped.containsKey(day)) sorted[day] = grouped[day]!;
    }
    for (final key in grouped.keys) {
      if (!sorted.containsKey(key)) sorted[key] = grouped[key]!;
    }
    return sorted;
  }

  @override
  Widget build(BuildContext context) {
    final authState = context.watch<AuthBloc>().state;
    final user = authState is AuthAuthenticated ? authState.user : null;
    return Scaffold(
      appBar: AppBar(title: const Text('Classes')),
      drawer: user != null ? AppDrawer(user: user) : null,
      body: _buildBody(),
    );
  }

  Widget _buildBody() {
    if (_isLoading) return const AppLoadingIndicator(message: 'Loading classes...');
    if (_error != null) return AppErrorWidget(message: _error!, onRetry: _loadData);
    if (_classes.isEmpty) {
      return const AppEmptyState(icon: Icons.class_outlined, title: 'No Classes',
        subtitle: 'No active classes found.');
    }
    final grouped = _groupByDay();
    final theme = Theme.of(context);
    return RefreshIndicator(
      onRefresh: _loadData,
      child: ListView.builder(
        padding: const EdgeInsets.all(16),
        itemCount: grouped.length,
        itemBuilder: (context, index) {
          final day = grouped.keys.elementAt(index);
          final dayClasses = grouped[day]!;
          return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            if (index > 0) const SizedBox(height: 16),
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Text(day, style: theme.textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.bold, color: theme.colorScheme.primary)),
            ),
            ...dayClasses.map((cls) => _buildClassCard(cls, theme)),
          ]);
        },
      ),
    );
  }

  Widget _buildClassCard(Map<String, dynamic> cls, ThemeData theme) {
    final name = cls['class_name'] as String? ?? cls['name'] as String? ?? 'Unnamed Class';
    final startTime = cls['start_time'] as String? ?? '';
    final endTime = cls['end_time'] as String? ?? '';
    final instructor = cls['instructor_name'] as String? ?? '';
    final room = cls['room'] as String? ?? '';
    final style = cls['style'] as String? ?? cls['martial_art_style'] as String? ?? '';
    final enrolled = (cls['enrolled_count'] as num?)?.toInt() ?? 0;
    final maxStudents = (cls['max_students'] as num?)?.toInt() ?? 0;
    final isFull = maxStudents > 0 && enrolled >= maxStudents;

    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
            Expanded(child: Text(name, style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.bold))),
            if (isFull) StatusBadge(label: 'Full', color: Colors.red)
            else StatusBadge(label: '$enrolled/$maxStudents', color: Colors.blue),
          ]),
          const SizedBox(height: 8),
          Row(children: [
            Icon(Icons.access_time, size: 16, color: Colors.grey[600]),
            const SizedBox(width: 4),
            Text('$startTime - $endTime', style: theme.textTheme.bodySmall),
            if (instructor.isNotEmpty) ...[
              const SizedBox(width: 16),
              Icon(Icons.person, size: 16, color: Colors.grey[600]),
              const SizedBox(width: 4),
              Expanded(child: Text(instructor, style: theme.textTheme.bodySmall, overflow: TextOverflow.ellipsis)),
            ],
          ]),
          if (room.isNotEmpty || style.isNotEmpty) ...[
            const SizedBox(height: 4),
            Row(children: [
              if (room.isNotEmpty) ...[
                Icon(Icons.room, size: 16, color: Colors.grey[600]),
                const SizedBox(width: 4),
                Text(room, style: theme.textTheme.bodySmall),
              ],
              if (room.isNotEmpty && style.isNotEmpty) const SizedBox(width: 16),
              if (style.isNotEmpty) ...[
                Icon(Icons.sports_martial_arts, size: 16, color: Colors.grey[600]),
                const SizedBox(width: 4),
                Text(style, style: theme.textTheme.bodySmall),
              ],
            ]),
          ],
        ]),
      ),
    );
  }
}
