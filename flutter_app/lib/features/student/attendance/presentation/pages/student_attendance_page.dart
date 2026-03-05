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

class StudentAttendancePage extends StatefulWidget {
  const StudentAttendancePage({super.key});

  @override
  State<StudentAttendancePage> createState() => _StudentAttendancePageState();
}

class _StudentAttendancePageState extends State<StudentAttendancePage> {
  late final ApiClient _api;
  bool _isLoading = true;
  String? _error;
  List<dynamic> _records = [];
  int _totalRecords = 0;
  int _presentCount = 0;
  int _currentPage = 1;
  int _totalPages = 1;
  bool _isLoadingMore = false;

  @override
  void initState() {
    super.initState();
    _api = ApiClient(storage: SecureStorage());
    _loadData();
  }

  Future<void> _loadData({bool loadMore = false}) async {
    if (loadMore) {
      setState(() => _isLoadingMore = true);
    } else {
      setState(() { _isLoading = true; _error = null; _currentPage = 1; _records = []; });
    }
    try {
      final response = await _api.get(ApiEndpoints.studentAttendance,
          queryParameters: {'page': _currentPage, 'per_page': AppConstants.defaultPageSize});
      final data = response.data;
      final newRecords = data is Map<String, dynamic>
          ? (data['records'] as List<dynamic>? ?? []) : (data is List ? data : []);

      if (data is Map<String, dynamic>) {
        final pagination = data['pagination'] as Map<String, dynamic>?;
        _totalRecords = pagination?['total'] ?? newRecords.length;
        _totalPages = pagination?['total_pages'] ?? 1;
        final stats = data['stats'] as Map<String, dynamic>?;
        _presentCount = stats?['present'] ?? stats?['present_count'] ?? 0;
      }

      if (loadMore) {
        _records.addAll(newRecords);
      } else {
        _records = newRecords;
        if (_presentCount == 0) {
          _presentCount = _records.where((r) =>
              (r['status'] ?? '').toString().toLowerCase() == 'present').length;
        }
      }
      setState(() { _isLoading = false; _isLoadingMore = false; });
    } catch (e) {
      setState(() { _isLoading = false; _isLoadingMore = false;
        _error = 'Failed to load attendance records. Please try again.'; });
    }
  }

  void _loadMore() {
    if (_currentPage < _totalPages && !_isLoadingMore) {
      _currentPage++;
      _loadData(loadMore: true);
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
      appBar: AppBar(title: const Text('Attendance')),
      drawer: AppDrawer(user: user),
      body: _isLoading
          ? const AppLoadingIndicator(message: 'Loading attendance...')
          : _error != null
              ? AppErrorWidget(message: _error!, onRetry: _loadData)
              : RefreshIndicator(
                  onRefresh: _loadData,
                  child: CustomScrollView(slivers: [
                    SliverToBoxAdapter(child: _buildStatsHeader(theme)),
                    _records.isEmpty
                        ? const SliverFillRemaining(
                            child: AppEmptyState(icon: Icons.fact_check, title: 'No Attendance Records'))
                        : SliverList(delegate: SliverChildBuilderDelegate((ctx, i) {
                            if (i == _records.length) {
                              if (_currentPage < _totalPages) {
                                _loadMore();
                                return const Padding(padding: EdgeInsets.all(16),
                                    child: Center(child: CircularProgressIndicator()));
                              }
                              return null;
                            }
                            return _buildRecordTile(_records[i], theme);
                          }, childCount: _records.length + (_currentPage < _totalPages ? 1 : 0))),
                  ]),
                ),
    );
  }

  Widget _buildStatsHeader(ThemeData theme) {
    final rate = _totalRecords > 0
        ? ((_presentCount / _totalRecords) * 100).toStringAsFixed(1) : '0.0';
    return Container(
      margin: const EdgeInsets.all(16),
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        gradient: LinearGradient(colors: [theme.colorScheme.primary, theme.colorScheme.primary.withOpacity(0.7)]),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Row(mainAxisAlignment: MainAxisAlignment.spaceAround, children: [
        _statColumn('Total', '$_totalRecords', theme),
        Container(width: 1, height: 40, color: Colors.white.withOpacity(0.3)),
        _statColumn('Present', '$_presentCount', theme),
        Container(width: 1, height: 40, color: Colors.white.withOpacity(0.3)),
        _statColumn('Rate', '$rate%', theme),
      ]),
    );
  }

  Widget _statColumn(String label, String value, ThemeData theme) {
    return Column(children: [
      Text(value, style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.bold)),
      const SizedBox(height: 4),
      Text(label, style: TextStyle(color: Colors.white.withOpacity(0.8), fontSize: 13)),
    ]);
  }

  Widget _buildRecordTile(dynamic record, ThemeData theme) {
    final date = record['date'] ?? record['attendance_date'] ?? '';
    final className = record['class_name'] ?? 'Unknown Class';
    final status = (record['status'] ?? 'unknown').toString();
    final checkIn = record['check_in_time'] ?? '';
    final subtitle = checkIn.toString().isNotEmpty ? '$date \u2022 Check-in: $checkIn' : '$date';

    return Card(
      margin: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
      child: ListTile(
        leading: CircleAvatar(
          backgroundColor: AttendanceStatus.color(status).withOpacity(0.15),
          child: Icon(AttendanceStatus.icon(status), color: AttendanceStatus.color(status), size: 22),
        ),
        title: Text(className.toString(), style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14)),
        subtitle: Text(subtitle, style: TextStyle(fontSize: 12, color: Colors.grey[600])),
        trailing: StatusBadge(
          label: status[0].toUpperCase() + status.substring(1),
          color: AttendanceStatus.color(status),
        ),
      ),
    );
  }
}
