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

class AdminAttendancePage extends StatefulWidget {
  const AdminAttendancePage({super.key});
  @override
  State<AdminAttendancePage> createState() => _AdminAttendancePageState();
}

class _AdminAttendancePageState extends State<AdminAttendancePage> {
  final _api = ApiClient(storage: SecureStorage());

  DateTime _selectedDate = DateTime.now();
  List<Map<String, dynamic>> _classes = [];
  Map<String, dynamic>? _selectedClass;
  List<Map<String, dynamic>> _students = [];
  Map<int, String> _attendanceRecords = {};

  bool _isLoadingClasses = true;
  bool _isLoadingStudents = false;
  bool _isSubmitting = false;
  String? _error;
  String? _successMessage;

  static const _statusOptions = ['Present', 'Absent', 'Late', 'Excused'];
  static const _dayNames = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

  @override
  void initState() { super.initState(); _loadClasses(); }

  String get _selectedDayName => _dayNames[_selectedDate.weekday];

  Future<void> _loadClasses() async {
    setState(() { _isLoadingClasses = true; _error = null; });
    try {
      final response = await _api.get(ApiEndpoints.adminClasses);
      final data = response.data;
      final List<dynamic> raw = data is Map && data.containsKey('classes')
          ? data['classes'] as List<dynamic>
          : data is List ? data : [];
      final allClasses = raw.cast<Map<String, dynamic>>();
      setState(() {
        _classes = allClasses.where((c) =>
            (c['day_of_week'] as String?)?.toLowerCase() == _selectedDayName.toLowerCase()
        ).toList();
        _selectedClass = null;
        _students = [];
        _attendanceRecords = {};
        _isLoadingClasses = false;
      });
    } catch (e) {
      setState(() { _error = 'Failed to load classes.'; _isLoadingClasses = false; });
    }
  }

  Future<void> _loadStudents() async {
    if (_selectedClass == null) return;
    final classId = _selectedClass!['id'] as int;
    setState(() { _isLoadingStudents = true; _error = null; });
    try {
      final dateStr = '${_selectedDate.year}-${_selectedDate.month.toString().padLeft(2, '0')}-${_selectedDate.day.toString().padLeft(2, '0')}';
      final response = await _api.get(
        ApiEndpoints.adminClassStudents(classId),
        queryParameters: {'date': dateStr},
      );
      final data = response.data;
      final List<dynamic> raw = data is Map && data.containsKey('students')
          ? data['students'] as List<dynamic>
          : data is List ? data : [];
      setState(() {
        _students = raw.cast<Map<String, dynamic>>();
        _attendanceRecords = {};
        for (final s in _students) {
          final sid = s['id'] as int? ?? s['student_id'] as int?;
          if (sid != null) {
            _attendanceRecords[sid] = s['attendance_status'] as String? ?? 'Present';
          }
        }
        _isLoadingStudents = false;
      });
    } catch (e) {
      setState(() { _error = 'Failed to load students.'; _isLoadingStudents = false; });
    }
  }

  Future<void> _submitAttendance() async {
    if (_selectedClass == null || _students.isEmpty) return;
    setState(() { _isSubmitting = true; _error = null; _successMessage = null; });
    try {
      final dateStr = '${_selectedDate.year}-${_selectedDate.month.toString().padLeft(2, '0')}-${_selectedDate.day.toString().padLeft(2, '0')}';
      final records = _attendanceRecords.entries.map((e) =>
          {'student_id': e.key, 'status': e.value.toLowerCase()}).toList();
      await _api.post(ApiEndpoints.adminAttendance, data: {
        'class_id': _selectedClass!['id'],
        'date': dateStr,
        'records': records,
      });
      setState(() { _successMessage = 'Attendance submitted successfully!'; _isSubmitting = false; });
    } catch (e) {
      setState(() { _error = 'Failed to submit attendance.'; _isSubmitting = false; });
    }
  }

  Future<void> _selectDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _selectedDate,
      firstDate: DateTime.now().subtract(const Duration(days: 365)),
      lastDate: DateTime.now().add(const Duration(days: 30)),
    );
    if (picked != null && picked != _selectedDate) {
      setState(() { _selectedDate = picked; });
      _loadClasses();
    }
  }

  @override
  Widget build(BuildContext context) {
    final authState = context.watch<AuthBloc>().state;
    final user = authState is AuthAuthenticated ? authState.user : null;
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(title: const Text('Attendance')),
      drawer: user != null ? AppDrawer(user: user) : null,
      body: RefreshIndicator(
        onRefresh: () async { await _loadClasses(); if (_selectedClass != null) await _loadStudents(); },
        child: ListView(padding: const EdgeInsets.all(16), children: [
          // Date picker
          Card(child: ListTile(
            leading: const Icon(Icons.calendar_today),
            title: Text('${_selectedDate.year}-${_selectedDate.month.toString().padLeft(2, '0')}-${_selectedDate.day.toString().padLeft(2, '0')}'),
            subtitle: Text(_selectedDayName),
            trailing: TextButton(onPressed: _selectDate, child: const Text('Change')),
          )),
          const SizedBox(height: 12),

          // Class dropdown
          Card(child: Padding(padding: const EdgeInsets.all(16), child: Column(
            crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('Select Class', style: theme.textTheme.titleSmall),
              const SizedBox(height: 8),
              if (_isLoadingClasses)
                const Center(child: CircularProgressIndicator())
              else if (_classes.isEmpty)
                Text('No classes scheduled for $_selectedDayName.',
                    style: TextStyle(color: Colors.grey[600]))
              else
                DropdownButtonFormField<Map<String, dynamic>>(
                  value: _selectedClass,
                  hint: const Text('Choose a class...'),
                  isExpanded: true,
                  decoration: InputDecoration(
                    border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                    contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                  ),
                  items: _classes.map((cls) {
                    final name = cls['class_name'] as String? ?? cls['name'] as String? ?? 'Unnamed';
                    final time = '${cls['start_time'] ?? ''} - ${cls['end_time'] ?? ''}';
                    return DropdownMenuItem(value: cls, child: Text('$name ($time)'));
                  }).toList(),
                  onChanged: (value) { setState(() { _selectedClass = value; }); _loadStudents(); },
                ),
            ]))),
          const SizedBox(height: 12),

          // Error / success messages
          if (_error != null) Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: Card(color: Colors.red[50], child: Padding(
              padding: const EdgeInsets.all(12),
              child: Row(children: [
                const Icon(Icons.error, color: Colors.red),
                const SizedBox(width: 8),
                Expanded(child: Text(_error!, style: const TextStyle(color: Colors.red))),
              ])))),
          if (_successMessage != null) Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: Card(color: Colors.green[50], child: Padding(
              padding: const EdgeInsets.all(12),
              child: Row(children: [
                const Icon(Icons.check_circle, color: Colors.green),
                const SizedBox(width: 8),
                Expanded(child: Text(_successMessage!, style: const TextStyle(color: Colors.green))),
              ])))),

          // Student list
          if (_isLoadingStudents)
            const Padding(padding: EdgeInsets.all(32), child: Center(child: CircularProgressIndicator()))
          else if (_selectedClass != null && _students.isNotEmpty) ...[
            Text('Students (${_students.length})', style: theme.textTheme.titleSmall),
            const SizedBox(height: 8),
            ..._students.map((student) {
              final sid = student['id'] as int? ?? student['student_id'] as int?;
              final name = student['full_name'] as String? ??
                  '${student['first_name'] ?? ''} ${student['last_name'] ?? ''}'.trim();
              final currentStatus = sid != null ? (_attendanceRecords[sid] ?? 'Present') : 'Present';

              return Card(margin: const EdgeInsets.only(bottom: 6), child: ListTile(
                leading: CircleAvatar(
                  backgroundColor: AttendanceStatus.color(currentStatus.toLowerCase()).withOpacity(0.2),
                  child: Icon(AttendanceStatus.icon(currentStatus.toLowerCase()),
                      color: AttendanceStatus.color(currentStatus.toLowerCase())),
                ),
                title: Text(name.isNotEmpty ? name : 'Unknown'),
                trailing: DropdownButton<String>(
                  value: currentStatus,
                  underline: const SizedBox(),
                  items: _statusOptions.map((s) => DropdownMenuItem(
                      value: s, child: Text(s, style: TextStyle(
                          color: AttendanceStatus.color(s.toLowerCase()),
                          fontWeight: FontWeight.w600)))).toList(),
                  onChanged: (value) {
                    if (value != null && sid != null) {
                      setState(() { _attendanceRecords[sid] = value; });
                    }
                  },
                ),
              ));
            }),
            const SizedBox(height: 16),
            SizedBox(width: double.infinity, height: 48,
              child: FilledButton.icon(
                onPressed: _isSubmitting ? null : _submitAttendance,
                icon: _isSubmitting
                    ? const SizedBox(width: 20, height: 20,
                        child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                    : const Icon(Icons.save),
                label: Text(_isSubmitting ? 'Submitting...' : 'Submit Attendance'),
              )),
          ] else if (_selectedClass != null && _students.isEmpty)
            const Padding(padding: EdgeInsets.all(32),
              child: AppEmptyState(icon: Icons.people_outline, title: 'No Students',
                subtitle: 'No students enrolled in this class.')),
        ]),
      ),
    );
  }
}
