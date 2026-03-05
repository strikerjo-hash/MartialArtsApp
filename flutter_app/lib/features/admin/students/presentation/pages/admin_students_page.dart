import 'dart:async';

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

class AdminStudentsPage extends StatefulWidget {
  const AdminStudentsPage({super.key});
  @override
  State<AdminStudentsPage> createState() => _AdminStudentsPageState();
}

class _AdminStudentsPageState extends State<AdminStudentsPage> {
  final _api = ApiClient(storage: SecureStorage());
  final _searchController = TextEditingController();
  Timer? _debounce;

  bool _isLoading = true;
  String? _error;
  List<Map<String, dynamic>> _students = [];
  String _searchQuery = '';
  String _statusFilter = 'all';
  int _currentPage = 1;
  int _totalPages = 1;
  int _totalStudents = 0;

  static const _statusOptions = ['all', 'active', 'inactive', 'suspended'];

  @override
  void initState() { super.initState(); _loadData(); }

  @override
  void dispose() { _searchController.dispose(); _debounce?.cancel(); super.dispose(); }

  void _onSearchChanged(String value) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 500), () {
      setState(() { _searchQuery = value.trim(); _currentPage = 1; });
      _loadData();
    });
  }

  Future<void> _loadData() async {
    setState(() { _isLoading = true; _error = null; });
    try {
      final qp = <String, dynamic>{'page': _currentPage, 'per_page': AppConstants.defaultPageSize};
      if (_searchQuery.isNotEmpty) qp['search'] = _searchQuery;
      if (_statusFilter != 'all') qp['status'] = _statusFilter;
      final response = await _api.get(ApiEndpoints.adminStudents, queryParameters: qp);
      final data = response.data;
      final List<dynamic> raw = data is Map && data.containsKey('students')
          ? data['students'] as List<dynamic>
          : data is List ? data : [];
      setState(() {
        _students = raw.cast<Map<String, dynamic>>();
        if (data is Map) {
          _totalPages = (data['total_pages'] as num?)?.toInt() ?? 1;
          _totalStudents = (data['total'] as num?)?.toInt() ?? _students.length;
        }
        _isLoading = false;
      });
    } catch (e) {
      setState(() { _error = 'Failed to load students.'; _isLoading = false; });
    }
  }

  Color _statusColor(String? status) {
    switch (status?.toLowerCase()) {
      case 'active': return Colors.green;
      case 'inactive': return Colors.grey;
      case 'suspended': return Colors.red;
      default: return Colors.blueGrey;
    }
  }

  void _showStudentDetail(Map<String, dynamic> student) {
    final theme = Theme.of(context);
    final name = student['full_name'] as String? ??
        '${student['first_name'] ?? ''} ${student['last_name'] ?? ''}'.trim();
    final email = student['email'] as String? ?? '';
    final phone = student['phone'] as String? ?? '';
    final beltRank = student['belt_rank'] as String?;
    final status = student['status'] as String? ?? 'active';
    final joinedDate = student['created_at'] as String? ?? '';
    final id = student['id'] as int?;

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (context) => DraggableScrollableSheet(
        initialChildSize: 0.5, minChildSize: 0.3, maxChildSize: 0.85, expand: false,
        builder: (context, scrollController) => SingleChildScrollView(
          controller: scrollController,
          padding: const EdgeInsets.all(24),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Center(child: Container(width: 40, height: 4,
              decoration: BoxDecoration(color: Colors.grey[300], borderRadius: BorderRadius.circular(2)))),
            const SizedBox(height: 20),
            Row(children: [
              CircleAvatar(radius: 32, backgroundColor: theme.colorScheme.primaryContainer,
                child: Text(name.isNotEmpty ? name[0].toUpperCase() : '?',
                    style: TextStyle(fontSize: 24, fontWeight: FontWeight.bold,
                        color: theme.colorScheme.onPrimaryContainer))),
              const SizedBox(width: 16),
              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(name.isNotEmpty ? name : 'Unknown',
                    style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.bold)),
                const SizedBox(height: 4),
                StatusBadge(label: status[0].toUpperCase() + status.substring(1), color: _statusColor(status)),
              ])),
            ]),
            const SizedBox(height: 20), const Divider(), const SizedBox(height: 12),
            if (email.isNotEmpty) _detailRow(Icons.email, 'Email', email),
            if (phone.isNotEmpty) _detailRow(Icons.phone, 'Phone', phone),
            if (beltRank != null && beltRank.isNotEmpty) _detailRow(Icons.military_tech, 'Belt Rank', beltRank),
            if (joinedDate.isNotEmpty) _detailRow(Icons.calendar_today, 'Joined', joinedDate),
            const SizedBox(height: 20),
            if (id != null) SizedBox(width: double.infinity,
              child: FilledButton.icon(
                onPressed: () { Navigator.pop(context); context.push('/admin/students/$id'); },
                icon: const Icon(Icons.open_in_new), label: const Text('View Full Profile'))),
          ]),
        ),
      ),
    );
  }

  Widget _detailRow(IconData icon, String label, String value) {
    return Padding(padding: const EdgeInsets.only(bottom: 12),
      child: Row(children: [
        Icon(icon, size: 20, color: Colors.grey[600]),
        const SizedBox(width: 12),
        Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(label, style: TextStyle(fontSize: 12, color: Colors.grey[500])),
          Text(value, style: const TextStyle(fontSize: 15)),
        ]),
      ]));
  }

  @override
  Widget build(BuildContext context) {
    final authState = context.watch<AuthBloc>().state;
    final user = authState is AuthAuthenticated ? authState.user : null;
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Students'),
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(64),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            child: Row(children: [
              Expanded(child: SizedBox(height: 44, child: TextField(
                controller: _searchController,
                onChanged: _onSearchChanged,
                decoration: InputDecoration(
                  hintText: 'Search students...',
                  prefixIcon: const Icon(Icons.search, size: 20),
                  suffixIcon: _searchQuery.isNotEmpty
                      ? IconButton(icon: const Icon(Icons.clear, size: 18),
                          onPressed: () { _searchController.clear(); _onSearchChanged(''); })
                      : null,
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                  contentPadding: EdgeInsets.zero,
                  filled: true, fillColor: theme.colorScheme.surface,
                ),
              ))),
              const SizedBox(width: 8),
              SizedBox(height: 44, child: DropdownButtonHideUnderline(
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 12),
                  decoration: BoxDecoration(
                    border: Border.all(color: Colors.grey[400]!),
                    borderRadius: BorderRadius.circular(12),
                    color: theme.colorScheme.surface,
                  ),
                  child: DropdownButton<String>(
                    value: _statusFilter,
                    items: _statusOptions.map((s) => DropdownMenuItem(
                        value: s, child: Text(s[0].toUpperCase() + s.substring(1),
                            style: const TextStyle(fontSize: 14)))).toList(),
                    onChanged: (value) {
                      if (value != null) { setState(() { _statusFilter = value; _currentPage = 1; }); _loadData(); }
                    },
                  ),
                ),
              )),
            ]),
          ),
        ),
      ),
      drawer: user != null ? AppDrawer(user: user) : null,
      body: _buildBody(theme),
    );
  }

  Widget _buildBody(ThemeData theme) {
    if (_isLoading) return const AppLoadingIndicator(message: 'Loading students...');
    if (_error != null) return AppErrorWidget(message: _error!, onRetry: _loadData);
    if (_students.isEmpty) {
      return AppEmptyState(icon: Icons.people_outline, title: 'No Students Found',
        subtitle: _searchQuery.isNotEmpty || _statusFilter != 'all'
            ? 'Try adjusting your search or filter.' : 'No students have been registered yet.');
    }
    return Column(children: [
      Padding(padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
          Text('$_totalStudents student${_totalStudents != 1 ? 's' : ''} found',
              style: theme.textTheme.bodySmall?.copyWith(color: Colors.grey[600])),
          Text('Page $_currentPage of $_totalPages',
              style: theme.textTheme.bodySmall?.copyWith(color: Colors.grey[600])),
        ])),
      Expanded(child: RefreshIndicator(onRefresh: _loadData,
        child: ListView.builder(padding: const EdgeInsets.symmetric(horizontal: 16),
          itemCount: _students.length,
          itemBuilder: (context, index) => _buildStudentRow(_students[index], theme)))),
      if (_totalPages > 1) _buildPagination(theme),
    ]);
  }

  Widget _buildStudentRow(Map<String, dynamic> student, ThemeData theme) {
    final name = student['full_name'] as String? ??
        '${student['first_name'] ?? ''} ${student['last_name'] ?? ''}'.trim();
    final email = student['email'] as String? ?? '';
    final beltRank = student['belt_rank'] as String?;
    final status = student['status'] as String? ?? 'active';
    return Card(margin: const EdgeInsets.only(bottom: 8),
      child: ListTile(
        onTap: () => _showStudentDetail(student),
        leading: CircleAvatar(backgroundColor: theme.colorScheme.primaryContainer,
          child: Text(name.isNotEmpty ? name[0].toUpperCase() : '?',
              style: TextStyle(fontWeight: FontWeight.bold, color: theme.colorScheme.onPrimaryContainer))),
        title: Text(name.isNotEmpty ? name : 'Unknown', style: const TextStyle(fontWeight: FontWeight.w600)),
        subtitle: Text(email),
        trailing: Row(mainAxisSize: MainAxisSize.min, children: [
          if (beltRank != null && beltRank.isNotEmpty) ...[
            Icon(Icons.military_tech, size: 18, color: BeltColors.fromName(beltRank)),
            const SizedBox(width: 8),
          ],
          StatusBadge(label: status[0].toUpperCase() + status.substring(1), color: _statusColor(status)),
        ]),
      ));
  }

  Widget _buildPagination(ThemeData theme) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 16),
      decoration: BoxDecoration(color: theme.colorScheme.surface,
        border: Border(top: BorderSide(color: Colors.grey[300]!))),
      child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
        IconButton(
          onPressed: _currentPage > 1 ? () { setState(() => _currentPage--); _loadData(); } : null,
          icon: const Icon(Icons.chevron_left)),
        const SizedBox(width: 16),
        Text('Page $_currentPage of $_totalPages', style: theme.textTheme.bodyMedium),
        const SizedBox(width: 16),
        IconButton(
          onPressed: _currentPage < _totalPages ? () { setState(() => _currentPage++); _loadData(); } : null,
          icon: const Icon(Icons.chevron_right)),
      ]),
    );
  }
}
