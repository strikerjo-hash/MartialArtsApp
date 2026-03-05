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

class AdminMessagesPage extends StatefulWidget {
  const AdminMessagesPage({super.key});
  @override
  State<AdminMessagesPage> createState() => _AdminMessagesPageState();
}

class _AdminMessagesPageState extends State<AdminMessagesPage> {
  final _api = ApiClient(storage: SecureStorage());
  final _subjectController = TextEditingController();
  final _bodyController = TextEditingController();
  final _formKey = GlobalKey<FormState>();

  bool _isLoading = true;
  bool _isSending = false;
  String? _error;
  String? _sendSuccess;
  List<Map<String, dynamic>> _sentMessages = [];

  String _priority = 'normal';
  String _audienceType = 'all_students';

  static const _priorityOptions = ['low', 'normal', 'high', 'urgent'];
  static const _audienceOptions = [
    {'value': 'all_students', 'label': 'All Students'},
    {'value': 'all_parents', 'label': 'All Parents'},
    {'value': 'all_active', 'label': 'All Active Members'},
    {'value': 'all', 'label': 'Everyone'},
  ];

  @override
  void initState() { super.initState(); _loadData(); }

  @override
  void dispose() { _subjectController.dispose(); _bodyController.dispose(); super.dispose(); }

  Future<void> _loadData() async {
    setState(() { _isLoading = true; _error = null; });
    try {
      final response = await _api.get(ApiEndpoints.adminMessages);
      final data = response.data;
      final List<dynamic> raw = data is Map && data.containsKey('messages')
          ? data['messages'] as List<dynamic>
          : data is List ? data : [];
      setState(() { _sentMessages = raw.cast<Map<String, dynamic>>(); _isLoading = false; });
    } catch (e) {
      setState(() { _error = 'Failed to load messages.'; _isLoading = false; });
    }
  }

  Future<void> _sendMessage() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() { _isSending = true; _error = null; _sendSuccess = null; });
    try {
      await _api.post(ApiEndpoints.adminMessages, data: {
        'subject': _subjectController.text.trim(),
        'body': _bodyController.text.trim(),
        'priority': _priority,
        'audience_type': _audienceType,
      });
      setState(() {
        _sendSuccess = 'Message sent successfully!';
        _isSending = false;
        _subjectController.clear();
        _bodyController.clear();
        _priority = 'normal';
      });
      _loadData();
    } catch (e) {
      setState(() { _error = 'Failed to send message.'; _isSending = false; });
    }
  }

  Color _priorityColor(String priority) {
    switch (priority.toLowerCase()) {
      case 'urgent': return Colors.red;
      case 'high': return Colors.orange;
      case 'normal': return Colors.blue;
      case 'low': return Colors.grey;
      default: return Colors.blue;
    }
  }

  @override
  Widget build(BuildContext context) {
    final authState = context.watch<AuthBloc>().state;
    final user = authState is AuthAuthenticated ? authState.user : null;
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(title: const Text('Messages')),
      drawer: user != null ? AppDrawer(user: user) : null,
      body: RefreshIndicator(
        onRefresh: _loadData,
        child: ListView(padding: const EdgeInsets.all(16), children: [
          // Compose section
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Form(
                key: _formKey,
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text('Compose Message', style: theme.textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.bold)),
                  const SizedBox(height: 16),
                  TextFormField(
                    controller: _subjectController,
                    decoration: const InputDecoration(labelText: 'Subject', border: OutlineInputBorder(),
                      prefixIcon: Icon(Icons.subject)),
                    validator: (v) => v == null || v.trim().isEmpty ? 'Subject is required' : null,
                  ),
                  const SizedBox(height: 12),
                  TextFormField(
                    controller: _bodyController,
                    decoration: const InputDecoration(labelText: 'Message Body', border: OutlineInputBorder(),
                      alignLabelWithHint: true),
                    maxLines: 5,
                    validator: (v) => v == null || v.trim().isEmpty ? 'Message body is required' : null,
                  ),
                  const SizedBox(height: 12),
                  Row(children: [
                    Expanded(child: DropdownButtonFormField<String>(
                      value: _priority,
                      decoration: const InputDecoration(labelText: 'Priority', border: OutlineInputBorder()),
                      items: _priorityOptions.map((p) => DropdownMenuItem(value: p,
                          child: Row(children: [
                            Icon(Icons.flag, size: 16, color: _priorityColor(p)),
                            const SizedBox(width: 8),
                            Text(p[0].toUpperCase() + p.substring(1)),
                          ]))).toList(),
                      onChanged: (v) { if (v != null) setState(() => _priority = v); },
                    )),
                    const SizedBox(width: 12),
                    Expanded(child: DropdownButtonFormField<String>(
                      value: _audienceType,
                      decoration: const InputDecoration(labelText: 'Audience', border: OutlineInputBorder()),
                      items: _audienceOptions.map((a) => DropdownMenuItem(
                          value: a['value'] as String,
                          child: Text(a['label'] as String, style: const TextStyle(fontSize: 13)))).toList(),
                      onChanged: (v) { if (v != null) setState(() => _audienceType = v); },
                    )),
                  ]),
                  const SizedBox(height: 16),

                  // Success / error
                  if (_sendSuccess != null) Padding(
                    padding: const EdgeInsets.only(bottom: 12),
                    child: Text(_sendSuccess!, style: const TextStyle(color: Colors.green, fontWeight: FontWeight.w600))),
                  if (_error != null && _error!.contains('send')) Padding(
                    padding: const EdgeInsets.only(bottom: 12),
                    child: Text(_error!, style: const TextStyle(color: Colors.red))),

                  SizedBox(width: double.infinity, height: 48,
                    child: FilledButton.icon(
                      onPressed: _isSending ? null : _sendMessage,
                      icon: _isSending
                          ? const SizedBox(width: 20, height: 20,
                              child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                          : const Icon(Icons.send),
                      label: Text(_isSending ? 'Sending...' : 'Send Message'),
                    )),
                ]),
              ),
            ),
          ),
          const SizedBox(height: 24),

          // Sent messages header
          Text('Sent Messages', style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.bold)),
          const SizedBox(height: 8),

          // Sent messages list
          if (_isLoading)
            const Padding(padding: EdgeInsets.all(32), child: Center(child: CircularProgressIndicator()))
          else if (_sentMessages.isEmpty)
            const Padding(padding: EdgeInsets.all(32),
              child: AppEmptyState(icon: Icons.mail_outline, title: 'No Sent Messages',
                subtitle: 'Messages you send will appear here.'))
          else
            ..._sentMessages.map((msg) => _buildMessageCard(msg, theme)),
          const SizedBox(height: 80),
        ]),
      ),
    );
  }

  Widget _buildMessageCard(Map<String, dynamic> msg, ThemeData theme) {
    final subject = msg['subject'] as String? ?? 'No Subject';
    final date = msg['created_at'] as String? ?? msg['sent_at'] as String? ?? '';
    final priority = msg['priority'] as String? ?? 'normal';
    final status = msg['status'] as String? ?? 'sent';
    final recipientCount = (msg['recipient_count'] as num?)?.toInt() ?? 0;
    final audienceType = msg['audience_type'] as String? ?? '';

    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Icon(Icons.flag, size: 16, color: _priorityColor(priority)),
            const SizedBox(width: 8),
            Expanded(child: Text(subject, style: theme.textTheme.titleSmall?.copyWith(
                fontWeight: FontWeight.bold))),
          ]),
          const SizedBox(height: 8),
          Row(children: [
            Icon(Icons.access_time, size: 14, color: Colors.grey[500]),
            const SizedBox(width: 4),
            Text(date, style: theme.textTheme.bodySmall?.copyWith(color: Colors.grey[600])),
            const Spacer(),
            StatusBadge(label: status[0].toUpperCase() + status.substring(1),
              color: status == 'sent' ? Colors.green : Colors.orange),
          ]),
          const SizedBox(height: 4),
          Row(children: [
            Icon(Icons.people, size: 14, color: Colors.grey[500]),
            const SizedBox(width: 4),
            Text('$recipientCount recipients', style: theme.textTheme.bodySmall?.copyWith(color: Colors.grey[600])),
            if (audienceType.isNotEmpty) ...[
              const SizedBox(width: 8),
              Text('($audienceType)', style: theme.textTheme.bodySmall?.copyWith(
                  color: Colors.grey[500], fontStyle: FontStyle.italic)),
            ],
          ]),
        ]),
      ),
    );
  }
}
