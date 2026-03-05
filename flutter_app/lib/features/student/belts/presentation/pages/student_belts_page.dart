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

class StudentBeltsPage extends StatefulWidget {
  const StudentBeltsPage({super.key});

  @override
  State<StudentBeltsPage> createState() => _StudentBeltsPageState();
}

class _StudentBeltsPageState extends State<StudentBeltsPage> {
  late final ApiClient _api;
  bool _isLoading = true;
  String? _error;
  List<dynamic> _belts = [];

  @override
  void initState() {
    super.initState();
    _api = ApiClient(storage: SecureStorage());
    _loadData();
  }

  Future<void> _loadData() async {
    setState(() { _isLoading = true; _error = null; });
    try {
      final response = await _api.get(ApiEndpoints.studentBelts);
      final data = response.data;
      _belts = data is Map<String, dynamic>
          ? (data['belts'] as List<dynamic>? ?? []) : (data is List ? data : []);
      setState(() => _isLoading = false);
    } catch (e) {
      setState(() { _isLoading = false; _error = 'Failed to load belt history. Please try again.'; });
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
      appBar: AppBar(title: const Text('Belt Progress')),
      drawer: AppDrawer(user: user),
      body: _isLoading
          ? const AppLoadingIndicator(message: 'Loading belt history...')
          : _error != null
              ? AppErrorWidget(message: _error!, onRetry: _loadData)
              : _belts.isEmpty
                  ? const AppEmptyState(icon: Icons.military_tech, title: 'No Belt Records',
                      subtitle: 'Your belt progression will appear here.')
                  : RefreshIndicator(
                      onRefresh: _loadData,
                      child: ListView.builder(
                        padding: const EdgeInsets.all(16),
                        itemCount: _belts.length,
                        itemBuilder: (ctx, i) => _buildBeltTimeline(i, theme),
                      ),
                    ),
    );
  }

  Widget _buildBeltTimeline(int index, ThemeData theme) {
    final belt = _belts[index];
    final beltName = belt['belt_name'] ?? belt['name'] ?? 'Unknown Belt';
    final colorName = belt['belt_color'] ?? belt['color'] ?? beltName;
    final beltColor = BeltColors.fromName(colorName.toString());
    final awardedDate = belt['awarded_date'] ?? belt['date'] ?? '';
    final styleName = belt['style_name'] ?? belt['style'] ?? '';
    final instructor = belt['instructor_name'] ?? belt['awarded_by'] ?? '';
    final notes = belt['notes'] ?? '';
    final isCurrent = index == 0;
    final isLast = index == _belts.length - 1;

    return IntrinsicHeight(child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
      // Timeline indicator
      SizedBox(width: 40, child: Column(children: [
        Container(
          width: isCurrent ? 24 : 18, height: isCurrent ? 24 : 18,
          decoration: BoxDecoration(
            color: beltColor == Colors.white ? Colors.grey[200] : beltColor,
            shape: BoxShape.circle,
            border: Border.all(color: isCurrent ? theme.colorScheme.primary : Colors.grey[400]!, width: isCurrent ? 3 : 1.5),
            boxShadow: isCurrent ? [BoxShadow(color: theme.colorScheme.primary.withOpacity(0.3), blurRadius: 8)] : null,
          ),
        ),
        if (!isLast) Expanded(child: Container(width: 2, color: Colors.grey[300])),
      ])),
      const SizedBox(width: 12),
      // Belt card
      Expanded(child: Card(
        margin: const EdgeInsets.only(bottom: 16),
        elevation: isCurrent ? 3 : 1,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(12),
          side: isCurrent ? BorderSide(color: theme.colorScheme.primary, width: 2) : BorderSide.none,
        ),
        child: Padding(padding: const EdgeInsets.all(16), child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(children: [
              Container(
                width: 32, height: 16,
                decoration: BoxDecoration(
                  color: beltColor == Colors.white ? Colors.grey[200] : beltColor,
                  borderRadius: BorderRadius.circular(4),
                  border: Border.all(color: Colors.grey[400]!),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(child: Text(beltName.toString(),
                  style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.bold))),
              if (isCurrent)
                StatusBadge(label: 'Current', color: theme.colorScheme.primary),
            ]),
            const SizedBox(height: 8),
            if (awardedDate.toString().isNotEmpty)
              Row(children: [
                Icon(Icons.calendar_today, size: 14, color: Colors.grey[600]),
                const SizedBox(width: 4),
                Text('Awarded: $awardedDate', style: TextStyle(fontSize: 12, color: Colors.grey[600])),
              ]),
            if (styleName.toString().isNotEmpty) ...[
              const SizedBox(height: 4),
              Row(children: [
                Icon(Icons.sports_martial_arts, size: 14, color: Colors.grey[600]),
                const SizedBox(width: 4),
                Text(styleName.toString(), style: TextStyle(fontSize: 12, color: Colors.grey[600])),
              ]),
            ],
            if (instructor.toString().isNotEmpty) ...[
              const SizedBox(height: 4),
              Row(children: [
                Icon(Icons.person, size: 14, color: Colors.grey[600]),
                const SizedBox(width: 4),
                Text('Instructor: $instructor', style: TextStyle(fontSize: 12, color: Colors.grey[600])),
              ]),
            ],
            if (notes.toString().isNotEmpty) ...[
              const SizedBox(height: 8),
              Text(notes.toString(), style: theme.textTheme.bodySmall?.copyWith(
                  fontStyle: FontStyle.italic, color: Colors.grey[700])),
            ],
          ],
        )),
      )),
    ]));
  }
}
