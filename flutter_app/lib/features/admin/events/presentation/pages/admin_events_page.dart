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

class AdminEventsPage extends StatefulWidget {
  const AdminEventsPage({super.key});
  @override
  State<AdminEventsPage> createState() => _AdminEventsPageState();
}

class _AdminEventsPageState extends State<AdminEventsPage> {
  final _api = ApiClient(storage: SecureStorage());
  bool _isLoading = true;
  String? _error;
  List<Map<String, dynamic>> _events = [];

  @override
  void initState() { super.initState(); _loadData(); }

  Future<void> _loadData() async {
    setState(() { _isLoading = true; _error = null; });
    try {
      final response = await _api.get(ApiEndpoints.adminEvents);
      final data = response.data;
      final List<dynamic> raw = data is Map && data.containsKey('events')
          ? data['events'] as List<dynamic>
          : data is List ? data : [];
      setState(() { _events = raw.cast<Map<String, dynamic>>(); _isLoading = false; });
    } catch (e) {
      setState(() { _error = 'Failed to load events.'; _isLoading = false; });
    }
  }

  void _showEventForm({Map<String, dynamic>? event}) {
    final isEdit = event != null;
    final nameController = TextEditingController(text: event?['name'] as String? ?? '');
    final descController = TextEditingController(text: event?['description'] as String? ?? '');
    String selectedType = event?['event_type'] as String? ?? 'belt_test';
    DateTime selectedDate = DateTime.now();
    if (event != null && event['event_date'] != null) {
      try { selectedDate = DateTime.parse(event['event_date'] as String); } catch (_) {}
    }
    final maxController = TextEditingController(
        text: (event?['max_participants'] as num?)?.toString() ?? '');
    final feeController = TextEditingController(
        text: (event?['fee'] as num?)?.toString() ?? '0');

    final types = ['belt_test', 'tournament', 'seminar', 'workshop', 'demonstration', 'camp', 'other'];

    showDialog(
      context: context,
      builder: (dialogContext) => StatefulBuilder(
        builder: (context, setDialogState) => AlertDialog(
          title: Text(isEdit ? 'Edit Event' : 'Create Event'),
          content: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, children: [
            TextField(controller: nameController,
              decoration: const InputDecoration(labelText: 'Event Name', border: OutlineInputBorder())),
            const SizedBox(height: 12),
            DropdownButtonFormField<String>(
              value: selectedType,
              decoration: const InputDecoration(labelText: 'Type', border: OutlineInputBorder()),
              items: types.map((t) => DropdownMenuItem(value: t,
                  child: Text(EventTypes.label(t)))).toList(),
              onChanged: (v) { if (v != null) setDialogState(() => selectedType = v); },
            ),
            const SizedBox(height: 12),
            ListTile(
              contentPadding: EdgeInsets.zero,
              title: Text('Date: ${selectedDate.year}-${selectedDate.month.toString().padLeft(2, '0')}-${selectedDate.day.toString().padLeft(2, '0')}'),
              trailing: TextButton(
                onPressed: () async {
                  final picked = await showDatePicker(context: context,
                    initialDate: selectedDate, firstDate: DateTime.now(),
                    lastDate: DateTime.now().add(const Duration(days: 730)));
                  if (picked != null) setDialogState(() => selectedDate = picked);
                },
                child: const Text('Change')),
            ),
            const SizedBox(height: 12),
            TextField(controller: descController,
              decoration: const InputDecoration(labelText: 'Description', border: OutlineInputBorder()),
              maxLines: 3),
            const SizedBox(height: 12),
            Row(children: [
              Expanded(child: TextField(controller: maxController,
                decoration: const InputDecoration(labelText: 'Max Participants', border: OutlineInputBorder()),
                keyboardType: TextInputType.number)),
              const SizedBox(width: 12),
              Expanded(child: TextField(controller: feeController,
                decoration: const InputDecoration(labelText: 'Fee (\$)', border: OutlineInputBorder()),
                keyboardType: TextInputType.number)),
            ]),
          ])),
          actions: [
            TextButton(onPressed: () => Navigator.pop(dialogContext), child: const Text('Cancel')),
            FilledButton(
              onPressed: () async {
                final dateStr = '${selectedDate.year}-${selectedDate.month.toString().padLeft(2, '0')}-${selectedDate.day.toString().padLeft(2, '0')}';
                final payload = {
                  'name': nameController.text.trim(),
                  'event_type': selectedType,
                  'event_date': dateStr,
                  'description': descController.text.trim(),
                  'max_participants': int.tryParse(maxController.text) ?? 0,
                  'fee': double.tryParse(feeController.text) ?? 0,
                };
                try {
                  if (isEdit) {
                    await _api.put(ApiEndpoints.adminEventDetail(event['id'] as int), data: payload);
                  } else {
                    await _api.post(ApiEndpoints.adminEvents, data: payload);
                  }
                  if (dialogContext.mounted) Navigator.pop(dialogContext);
                  _loadData();
                } catch (e) {
                  if (dialogContext.mounted) {
                    ScaffoldMessenger.of(dialogContext).showSnackBar(
                      SnackBar(content: Text('Failed to ${isEdit ? "update" : "create"} event.')));
                  }
                }
              },
              child: Text(isEdit ? 'Update' : 'Create'),
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final authState = context.watch<AuthBloc>().state;
    final user = authState is AuthAuthenticated ? authState.user : null;
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(title: const Text('Events')),
      drawer: user != null ? AppDrawer(user: user) : null,
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => _showEventForm(),
        icon: const Icon(Icons.add),
        label: const Text('New Event'),
      ),
      body: _buildBody(theme),
    );
  }

  Widget _buildBody(ThemeData theme) {
    if (_isLoading) return const AppLoadingIndicator(message: 'Loading events...');
    if (_error != null) return AppErrorWidget(message: _error!, onRetry: _loadData);
    if (_events.isEmpty) {
      return const AppEmptyState(icon: Icons.event_outlined, title: 'No Events',
        subtitle: 'Tap the + button to create your first event.');
    }
    return RefreshIndicator(
      onRefresh: _loadData,
      child: ListView.builder(
        padding: const EdgeInsets.all(16),
        itemCount: _events.length,
        itemBuilder: (context, index) => _buildEventCard(_events[index], theme),
      ),
    );
  }

  Widget _buildEventCard(Map<String, dynamic> event, ThemeData theme) {
    final name = event['name'] as String? ?? event['event_name'] as String? ?? 'Unnamed Event';
    final type = event['event_type'] as String? ?? 'other';
    final date = event['event_date'] as String? ?? '';
    final registered = (event['registered_count'] as num?)?.toInt() ?? 0;
    final maxParticipants = (event['max_participants'] as num?)?.toInt() ?? 0;
    final status = event['status'] as String? ?? 'active';

    Color statusColor;
    switch (status.toLowerCase()) {
      case 'active': statusColor = Colors.green; break;
      case 'cancelled': statusColor = Colors.red; break;
      case 'completed': statusColor = Colors.blueGrey; break;
      default: statusColor = Colors.orange;
    }

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
            Expanded(child: Text(name, style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.bold))),
            IconButton(icon: const Icon(Icons.edit, size: 20),
              onPressed: () => _showEventForm(event: event)),
          ]),
          const SizedBox(height: 8),
          Row(children: [
            Icon(EventTypes.icon(type), size: 16, color: Colors.grey[600]),
            const SizedBox(width: 4),
            Text(EventTypes.label(type), style: theme.textTheme.bodySmall),
            const SizedBox(width: 16),
            Icon(Icons.calendar_today, size: 16, color: Colors.grey[600]),
            const SizedBox(width: 4),
            Text(date, style: theme.textTheme.bodySmall),
          ]),
          const SizedBox(height: 8),
          Row(children: [
            StatusBadge(label: status[0].toUpperCase() + status.substring(1), color: statusColor),
            const Spacer(),
            Icon(Icons.people, size: 16, color: Colors.grey[600]),
            const SizedBox(width: 4),
            Text(maxParticipants > 0 ? '$registered/$maxParticipants' : '$registered registered',
                style: theme.textTheme.bodySmall),
          ]),
        ]),
      ),
    );
  }
}
