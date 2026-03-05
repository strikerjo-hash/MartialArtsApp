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

class StudentProfilePage extends StatefulWidget {
  const StudentProfilePage({super.key});

  @override
  State<StudentProfilePage> createState() => _StudentProfilePageState();
}

class _StudentProfilePageState extends State<StudentProfilePage> {
  late final ApiClient _api;
  bool _isLoading = true;
  bool _isSaving = false;
  bool _isEditing = false;
  String? _error;
  Map<String, dynamic>? _profile;
  List<dynamic> _memberships = [];

  // Editable controllers
  final _phoneController = TextEditingController();
  final _emailController = TextEditingController();
  final _addressController = TextEditingController();
  final _emergencyNameController = TextEditingController();
  final _emergencyPhoneController = TextEditingController();

  @override
  void initState() {
    super.initState();
    _api = ApiClient(storage: SecureStorage());
    _loadData();
  }

  @override
  void dispose() {
    _phoneController.dispose();
    _emailController.dispose();
    _addressController.dispose();
    _emergencyNameController.dispose();
    _emergencyPhoneController.dispose();
    super.dispose();
  }

  Future<void> _loadData() async {
    setState(() { _isLoading = true; _error = null; });
    try {
      final results = await Future.wait([
        _api.get(ApiEndpoints.studentProfile),
        _api.get(ApiEndpoints.studentMemberships),
      ]);
      final profileData = results[0].data;
      final memberData = results[1].data;

      _profile = profileData is Map<String, dynamic>
          ? (profileData['student'] as Map<String, dynamic>? ?? profileData) : null;
      _memberships = memberData is Map<String, dynamic>
          ? (memberData['memberships'] as List<dynamic>? ?? [])
          : (memberData is List ? memberData : []);

      if (_profile != null) {
        _phoneController.text = _profile!['phone']?.toString() ?? '';
        _emailController.text = _profile!['email']?.toString() ?? '';
        _addressController.text = _profile!['address']?.toString() ?? '';
        _emergencyNameController.text = _profile!['emergency_contact_name']?.toString() ?? '';
        _emergencyPhoneController.text = _profile!['emergency_contact_phone']?.toString() ?? '';
      }
      setState(() => _isLoading = false);
    } catch (e) {
      setState(() { _isLoading = false; _error = 'Failed to load profile. Please try again.'; });
    }
  }

  Future<void> _saveProfile() async {
    setState(() => _isSaving = true);
    try {
      await _api.put(ApiEndpoints.studentProfile, data: {
        'phone': _phoneController.text,
        'email': _emailController.text,
        'address': _addressController.text,
        'emergency_contact_name': _emergencyNameController.text,
        'emergency_contact_phone': _emergencyPhoneController.text,
      });
      setState(() { _isEditing = false; _isSaving = false; });
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Profile updated successfully!'), backgroundColor: Colors.green));
      }
      _loadData();
    } catch (e) {
      setState(() => _isSaving = false);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Failed to update profile. Please try again.'), backgroundColor: Colors.red));
      }
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
      appBar: AppBar(
        title: const Text('Profile'),
        actions: [
          if (!_isLoading && _error == null)
            IconButton(
              icon: Icon(_isEditing ? Icons.close : Icons.edit),
              onPressed: () => setState(() { _isEditing = !_isEditing; }),
            ),
        ],
      ),
      drawer: AppDrawer(user: user),
      body: _isLoading
          ? const AppLoadingIndicator(message: 'Loading profile...')
          : _error != null
              ? AppErrorWidget(message: _error!, onRetry: _loadData)
              : RefreshIndicator(
                  onRefresh: _loadData,
                  child: ListView(padding: const EdgeInsets.all(16), children: [
                    _buildProfileHeader(theme),
                    const SizedBox(height: 16),
                    _buildInfoSection(theme),
                    const SizedBox(height: 16),
                    _buildEmergencySection(theme),
                    if (_isEditing) ...[
                      const SizedBox(height: 24),
                      _buildSaveButton(theme),
                    ],
                    if (_memberships.isNotEmpty) ...[
                      const SizedBox(height: 24),
                      _buildMembershipSection(theme),
                    ],
                    const SizedBox(height: 24),
                  ]),
                ),
    );
  }

  Widget _buildProfileHeader(ThemeData theme) {
    final name = _profile?['full_name'] ?? _profile?['first_name'] ?? '';
    final beltRank = _profile?['belt_rank'] ?? '';
    final joinDate = _profile?['join_date'] ?? _profile?['created_at'] ?? '';
    return Card(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      child: Padding(padding: const EdgeInsets.all(20), child: Column(children: [
        CircleAvatar(radius: 40, backgroundColor: theme.colorScheme.primaryContainer,
          child: Text(
            name.toString().isNotEmpty ? name.toString()[0].toUpperCase() : '?',
            style: TextStyle(fontSize: 32, fontWeight: FontWeight.bold, color: theme.colorScheme.onPrimaryContainer),
          )),
        const SizedBox(height: 12),
        Text(name.toString(), style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.bold)),
        const SizedBox(height: 4),
        if (beltRank.toString().isNotEmpty)
          Row(mainAxisAlignment: MainAxisAlignment.center, children: [
            Icon(Icons.military_tech, size: 18, color: BeltColors.fromName(beltRank.toString())),
            const SizedBox(width: 4),
            Text('$beltRank Belt', style: theme.textTheme.bodyMedium?.copyWith(color: Colors.grey[700])),
          ]),
        if (joinDate.toString().isNotEmpty) ...[
          const SizedBox(height: 4),
          Text('Member since $joinDate', style: theme.textTheme.bodySmall?.copyWith(color: Colors.grey[500])),
        ],
      ])),
    );
  }

  Widget _buildInfoSection(ThemeData theme) {
    return Card(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: Padding(padding: const EdgeInsets.all(16), child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Contact Information', style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.bold)),
          const SizedBox(height: 12),
          _buildField(Icons.email, 'Email', _emailController, _isEditing, theme),
          const SizedBox(height: 12),
          _buildField(Icons.phone, 'Phone', _phoneController, _isEditing, theme),
          const SizedBox(height: 12),
          _buildField(Icons.home, 'Address', _addressController, _isEditing, theme),
        ],
      )),
    );
  }

  Widget _buildEmergencySection(ThemeData theme) {
    return Card(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: Padding(padding: const EdgeInsets.all(16), child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            Icon(Icons.emergency, color: Colors.red[400], size: 20),
            const SizedBox(width: 8),
            Text('Emergency Contact', style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.bold)),
          ]),
          const SizedBox(height: 12),
          _buildField(Icons.person, 'Contact Name', _emergencyNameController, _isEditing, theme),
          const SizedBox(height: 12),
          _buildField(Icons.phone, 'Contact Phone', _emergencyPhoneController, _isEditing, theme),
        ],
      )),
    );
  }

  Widget _buildField(IconData icon, String label, TextEditingController controller, bool editable, ThemeData theme) {
    if (editable) {
      return TextFormField(
        controller: controller,
        decoration: InputDecoration(
          prefixIcon: Icon(icon), labelText: label,
          border: const OutlineInputBorder(),
        ),
      );
    }
    return Row(children: [
      Icon(icon, size: 20, color: Colors.grey[600]),
      const SizedBox(width: 12),
      Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(label, style: TextStyle(fontSize: 11, color: Colors.grey[500])),
        const SizedBox(height: 2),
        Text(controller.text.isNotEmpty ? controller.text : 'Not set',
            style: TextStyle(fontSize: 14, color: controller.text.isNotEmpty ? null : Colors.grey[400])),
      ])),
    ]);
  }

  Widget _buildSaveButton(ThemeData theme) {
    return SizedBox(width: double.infinity, height: 48, child: ElevatedButton.icon(
      onPressed: _isSaving ? null : _saveProfile,
      icon: _isSaving ? const SizedBox(width: 18, height: 18,
          child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.save),
      label: Text(_isSaving ? 'Saving...' : 'Save Changes'),
      style: ElevatedButton.styleFrom(shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12))),
    ));
  }

  Widget _buildMembershipSection(ThemeData theme) {
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Text('Memberships', style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.bold)),
      const SizedBox(height: 8),
      ..._memberships.map((m) {
        final name = m['membership_name'] ?? m['name'] ?? 'N/A';
        final status = (m['status'] ?? 'unknown').toString();
        final startDate = m['start_date'] ?? '';
        final endDate = m['end_date'] ?? '';
        final membershipType = m['membership_type'] ?? m['type'] ?? '';
        return Card(
          margin: const EdgeInsets.only(bottom: 8),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          child: Padding(padding: const EdgeInsets.all(16), child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(children: [
                Icon(Icons.card_membership, color: theme.colorScheme.primary, size: 20),
                const SizedBox(width: 8),
                Expanded(child: Text(name.toString(),
                    style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.bold))),
                StatusBadge(
                  label: status[0].toUpperCase() + status.substring(1),
                  color: status.toLowerCase() == 'active' ? Colors.green
                      : status.toLowerCase() == 'expired' ? Colors.red : Colors.orange,
                ),
              ]),
              const SizedBox(height: 8),
              if (membershipType.toString().isNotEmpty)
                Row(children: [
                  Icon(Icons.category, size: 14, color: Colors.grey[600]),
                  const SizedBox(width: 4),
                  Text('Type: $membershipType', style: TextStyle(fontSize: 12, color: Colors.grey[600])),
                ]),
              if (startDate.toString().isNotEmpty) ...[
                const SizedBox(height: 4),
                Row(children: [
                  Icon(Icons.date_range, size: 14, color: Colors.grey[600]),
                  const SizedBox(width: 4),
                  Text('$startDate${endDate.toString().isNotEmpty ? " - $endDate" : ""}',
                      style: TextStyle(fontSize: 12, color: Colors.grey[600])),
                ]),
              ],
            ],
          )),
        );
      }),
    ]);
  }
}
