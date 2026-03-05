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

class StudentMessagesPage extends StatefulWidget {
  const StudentMessagesPage({super.key});

  @override
  State<StudentMessagesPage> createState() => _StudentMessagesPageState();
}

class _StudentMessagesPageState extends State<StudentMessagesPage> {
  late final ApiClient _api;
  bool _isLoading = true;
  String? _error;
  List<dynamic> _messages = [];
  int? _expandedIndex;

  @override
  void initState() {
    super.initState();
    _api = ApiClient(storage: SecureStorage());
    _loadData();
  }

  Future<void> _loadData() async {
    setState(() { _isLoading = true; _error = null; });
    try {
      final response = await _api.get(ApiEndpoints.studentMessages);
      final data = response.data;
      _messages = data is Map<String, dynamic>
          ? (data['messages'] as List<dynamic>? ?? []) : (data is List ? data : []);
      _expandedIndex = null;
      setState(() => _isLoading = false);
    } catch (e) {
      setState(() { _isLoading = false; _error = 'Failed to load messages. Please try again.'; });
    }
  }

  Future<void> _markAsRead(int messageId) async {
    try {
      await _api.post(ApiEndpoints.studentMessageRead(messageId));
    } catch (_) {}
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
      appBar: AppBar(title: const Text('Messages')),
      drawer: AppDrawer(user: user),
      body: _isLoading
          ? const AppLoadingIndicator(message: 'Loading messages...')
          : _error != null
              ? AppErrorWidget(message: _error!, onRetry: _loadData)
              : _messages.isEmpty
                  ? const AppEmptyState(icon: Icons.message, title: 'No Messages',
                      subtitle: 'Your inbox is empty.')
                  : RefreshIndicator(
                      onRefresh: _loadData,
                      child: ListView.builder(
                        padding: const EdgeInsets.all(16),
                        itemCount: _messages.length,
                        itemBuilder: (ctx, i) => _buildMessageCard(i, theme),
                      ),
                    ),
    );
  }

  Widget _buildMessageCard(int index, ThemeData theme) {
    final msg = _messages[index];
    final subject = msg['subject'] ?? 'No Subject';
    final body = msg['body'] ?? msg['message'] ?? '';
    final senderType = msg['sender_type'] ?? '';
    final priority = (msg['priority'] ?? '').toString();
    final date = msg['created_at'] ?? msg['sent_at'] ?? msg['date'] ?? '';
    final isRead = msg['is_read'] == true || msg['is_read'] == 1 || msg['read_at'] != null;
    final isExpanded = _expandedIndex == index;
    final messageId = msg['id'] ?? msg['message_id'];

    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: () {
          setState(() { _expandedIndex = isExpanded ? null : index; });
          if (!isExpanded && !isRead && messageId != null) {
            final id = messageId is int ? messageId : int.tryParse(messageId.toString()) ?? 0;
            _markAsRead(id);
            msg['is_read'] = true;
          }
        },
        child: Padding(padding: const EdgeInsets.all(16), child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(children: [
              if (!isRead)
                Container(
                  width: 10, height: 10, margin: const EdgeInsets.only(right: 8),
                  decoration: const BoxDecoration(color: Colors.blue, shape: BoxShape.circle),
                ),
              Expanded(child: Text(subject.toString(),
                  style: theme.textTheme.titleSmall?.copyWith(
                      fontWeight: isRead ? FontWeight.normal : FontWeight.bold),
                  maxLines: 1, overflow: TextOverflow.ellipsis)),
              if (priority.isNotEmpty && priority.toLowerCase() != 'normal')
                Padding(padding: const EdgeInsets.only(left: 8),
                  child: StatusBadge(
                    label: priority[0].toUpperCase() + priority.substring(1),
                    color: priority.toLowerCase() == 'high' || priority.toLowerCase() == 'urgent'
                        ? Colors.red : Colors.orange,
                  )),
            ]),
            const SizedBox(height: 4),
            Row(children: [
              if (senderType.toString().isNotEmpty) ...[
                Icon(senderType.toString().toLowerCase() == 'admin' ? Icons.admin_panel_settings : Icons.person,
                    size: 14, color: Colors.grey[600]),
                const SizedBox(width: 4),
                Text(senderType.toString(), style: TextStyle(fontSize: 12, color: Colors.grey[600])),
                const SizedBox(width: 12),
              ],
              Icon(Icons.access_time, size: 14, color: Colors.grey[600]),
              const SizedBox(width: 4),
              Expanded(child: Text(_formatDate(date.toString()),
                  style: TextStyle(fontSize: 12, color: Colors.grey[600]))),
              Icon(isExpanded ? Icons.expand_less : Icons.expand_more, color: Colors.grey[600]),
            ]),
            if (isExpanded && body.toString().isNotEmpty) ...[
              const SizedBox(height: 12),
              const Divider(height: 1),
              const SizedBox(height: 12),
              Text(body.toString(), style: theme.textTheme.bodyMedium?.copyWith(height: 1.5)),
            ],
          ],
        )),
      ),
    );
  }

  String _formatDate(String dateStr) {
    if (dateStr.isEmpty) return '';
    try {
      final date = DateTime.parse(dateStr);
      final now = DateTime.now();
      final diff = now.difference(date);
      if (diff.inMinutes < 60) return '${diff.inMinutes}m ago';
      if (diff.inHours < 24) return '${diff.inHours}h ago';
      if (diff.inDays < 7) return '${diff.inDays}d ago';
      return '${date.month}/${date.day}/${date.year}';
    } catch (_) {}
    return dateStr;
  }
}
