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

class StudentClassesPage extends StatefulWidget {
  const StudentClassesPage({super.key});

  @override
  State<StudentClassesPage> createState() => _StudentClassesPageState();
}

class _StudentClassesPageState extends State<StudentClassesPage> {
  late final ApiClient _api;
  bool _isLoading = true;
  String? _error;
  List<dynamic> _classes = [];
  Map<String, List<dynamic>> _grouped = {};

  static const _dayOrder = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];

  @override
  void initState() {
    super.initState();
    _api = ApiClient(storage: SecureStorage());
    _loadData();
  }

  Future<void> _loadData() async {
    setState(() { _isLoading = true; _error = null; });
    try {
      final response = await _api.get(ApiEndpoints.studentClasses);
      final data = response.data;
      _classes = data is Map<String, dynamic>
          ? (data['classes'] as List<dynamic>? ?? [])
          : (data is List ? data : []);
      _grouped = {};
      for (final cls in _classes) {
        final day = (cls['day_of_week'] ?? 'Unknown').toString();
        final key = _dayOrder.firstWhere((d) => d.toLowerCase() == day.toLowerCase(), orElse: () => day);
        _grouped.putIfAbsent(key, () => []).add(cls);
      }
      setState(() => _isLoading = false);
    } catch (e) {
      setState(() { _isLoading = false; _error = 'Failed to load classes. Please try again.'; });
    }
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
      appBar: AppBar(title: const Text('My Classes')),
      drawer: AppDrawer(user: user),
      body: _isLoading
          ? const AppLoadingIndicator(message: 'Loading classes...')
          : _error != null
              ? AppErrorWidget(message: _error!, onRetry: _loadData)
              : _classes.isEmpty
                  ? const AppEmptyState(icon: Icons.class_, title: 'No Classes Enrolled',
                      subtitle: 'You are not enrolled in any classes yet.')
                  : RefreshIndicator(
                      onRefresh: _loadData,
                      child: ListView.builder(
                        padding: const EdgeInsets.all(16),
                        itemCount: _dayOrder.length,
                        itemBuilder: (ctx, i) {
                          final day = _dayOrder[i];
                          final classes = _grouped[day];
                          if (classes == null || classes.isEmpty) return const SizedBox.shrink();
                          return _buildDaySection(day, classes, theme);
                        },
                      ),
                    ),
    );
  }

  Widget _buildDaySection(String day, List<dynamic> classes, ThemeData theme) {
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Padding(padding: const EdgeInsets.symmetric(vertical: 8), child: Row(children: [
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
          decoration: BoxDecoration(color: theme.colorScheme.primary, borderRadius: BorderRadius.circular(20)),
          child: Text(day, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 13)),
        ),
        const SizedBox(width: 8),
        Text('${classes.length} class${classes.length == 1 ? "" : "es"}',
            style: TextStyle(color: Colors.grey[600], fontSize: 13)),
      ])),
      ...classes.map((cls) => _buildClassCard(cls, theme)),
      const SizedBox(height: 8),
    ]);
  }

  Widget _buildClassCard(dynamic cls, ThemeData theme) {
    final name = cls['class_name'] ?? cls['name'] ?? 'Unknown Class';
    final startTime = cls['start_time'] ?? '';
    final endTime = cls['end_time'] ?? '';
    final instructor = cls['instructor_name'] ?? 'TBA';
    final room = cls['room'] ?? '';
    final style = cls['style_name'] ?? cls['style'] ?? '';
    final skillLevel = cls['skill_level'] ?? '';

    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: Padding(padding: const EdgeInsets.all(16), child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            Expanded(child: Text(name.toString(),
                style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.bold))),
            if (skillLevel.toString().isNotEmpty)
              StatusBadge(label: skillLevel.toString(), color: theme.colorScheme.secondary),
          ]),
          const SizedBox(height: 8),
          Row(children: [
            Icon(Icons.access_time, size: 16, color: Colors.grey[600]),
            const SizedBox(width: 4),
            Text('${_formatTime(startTime.toString())} - ${_formatTime(endTime.toString())}',
                style: TextStyle(color: Colors.grey[700], fontSize: 13)),
          ]),
          const SizedBox(height: 4),
          Row(children: [
            Icon(Icons.person, size: 16, color: Colors.grey[600]),
            const SizedBox(width: 4),
            Text(instructor.toString(), style: TextStyle(color: Colors.grey[700], fontSize: 13)),
            if (room.toString().isNotEmpty) ...[
              const SizedBox(width: 16),
              Icon(Icons.room, size: 16, color: Colors.grey[600]),
              const SizedBox(width: 4),
              Text(room.toString(), style: TextStyle(color: Colors.grey[700], fontSize: 13)),
            ],
          ]),
          if (style.toString().isNotEmpty) ...[
            const SizedBox(height: 4),
            Row(children: [
              Icon(Icons.sports_martial_arts, size: 16, color: Colors.grey[600]),
              const SizedBox(width: 4),
              Text(style.toString(), style: TextStyle(color: Colors.grey[700], fontSize: 13)),
            ]),
          ],
        ],
      )),
    );
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
