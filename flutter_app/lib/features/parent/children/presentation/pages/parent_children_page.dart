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

class ParentChildrenPage extends StatefulWidget {
  const ParentChildrenPage({super.key});

  @override
  State<ParentChildrenPage> createState() => _ParentChildrenPageState();
}

class _ParentChildrenPageState extends State<ParentChildrenPage> {
  final _api = ApiClient(storage: SecureStorage());

  bool _isLoading = true;
  String? _error;
  List<Map<String, dynamic>> _children = [];

  @override
  void initState() {
    super.initState();
    _loadData();
  }

  Future<void> _loadData() async {
    setState(() {
      _isLoading = true;
      _error = null;
    });

    try {
      final response = await _api.get(ApiEndpoints.parentChildren);
      final data = response.data;

      final List<dynamic> childrenRaw =
          data is Map && data.containsKey('children')
              ? data['children'] as List<dynamic>
              : data is List
                  ? data
                  : [];

      setState(() {
        _children = childrenRaw.cast<Map<String, dynamic>>();
        _isLoading = false;
      });
    } catch (e) {
      setState(() {
        _error = 'Failed to load children. Please try again.';
        _isLoading = false;
      });
    }
  }

  Color _statusColor(String? status) {
    switch (status?.toLowerCase()) {
      case 'active':
        return Colors.green;
      case 'inactive':
        return Colors.grey;
      case 'suspended':
        return Colors.red;
      default:
        return Colors.blueGrey;
    }
  }

  @override
  Widget build(BuildContext context) {
    final authState = context.watch<AuthBloc>().state;
    final user = authState is AuthAuthenticated ? authState.user : null;
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(
        title: const Text('My Children'),
      ),
      drawer: user != null ? AppDrawer(user: user) : null,
      body: _buildBody(theme),
    );
  }

  Widget _buildBody(ThemeData theme) {
    if (_isLoading) {
      return const AppLoadingIndicator(message: 'Loading children...');
    }

    if (_error != null) {
      return AppErrorWidget(message: _error!, onRetry: _loadData);
    }

    if (_children.isEmpty) {
      return const AppEmptyState(
        icon: Icons.people_outline,
        title: 'No Children Linked',
        subtitle: 'Contact your school to link your children to your account.',
      );
    }

    return RefreshIndicator(
      onRefresh: _loadData,
      child: ListView.builder(
        padding: const EdgeInsets.all(16),
        itemCount: _children.length,
        itemBuilder: (context, index) => _buildChildCard(_children[index], theme),
      ),
    );
  }

  Widget _buildChildCard(Map<String, dynamic> child, ThemeData theme) {
    final name = child['full_name'] as String? ??
        '${child['first_name'] ?? ''} ${child['last_name'] ?? ''}'.trim();
    final beltRank = child['belt_rank'] as String?;
    final status = child['status'] as String? ?? 'active';
    final relationship = child['relationship'] as String? ?? 'Child';
    final childId = child['id'] as int? ?? child['student_id'] as int?;

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: childId != null
            ? () => context.push('/parent/child/$childId')
            : null,
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Row(
            children: [
              // Avatar
              CircleAvatar(
                radius: 28,
                backgroundColor: theme.colorScheme.primaryContainer,
                child: Text(
                  name.isNotEmpty ? name[0].toUpperCase() : '?',
                  style: TextStyle(
                    fontSize: 22,
                    fontWeight: FontWeight.bold,
                    color: theme.colorScheme.onPrimaryContainer,
                  ),
                ),
              ),
              const SizedBox(width: 16),

              // Details
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      name.isNotEmpty ? name : 'Unknown',
                      style: theme.textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                    const SizedBox(height: 4),
                    if (beltRank != null && beltRank.isNotEmpty) ...[
                      Row(
                        children: [
                          Icon(
                            Icons.military_tech,
                            size: 16,
                            color: BeltColors.fromName(beltRank),
                          ),
                          const SizedBox(width: 4),
                          Text(
                            '$beltRank Belt',
                            style: theme.textTheme.bodyMedium?.copyWith(
                              color: Colors.grey[700],
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 4),
                    ],
                    Row(
                      children: [
                        StatusBadge(
                          label: status[0].toUpperCase() + status.substring(1),
                          color: _statusColor(status),
                        ),
                        const SizedBox(width: 8),
                        Text(
                          relationship,
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: Colors.grey[500],
                            fontStyle: FontStyle.italic,
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),

              // Chevron
              Icon(
                Icons.chevron_right,
                color: Colors.grey[400],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
