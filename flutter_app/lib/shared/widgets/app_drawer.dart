import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';
import 'package:martial_arts_app/features/auth/data/models/user_model.dart';
import 'package:martial_arts_app/features/auth/presentation/bloc/auth_bloc.dart';

/// Role-aware navigation drawer that shows different menu items
/// based on user type (student, parent, admin).
class AppDrawer extends StatelessWidget {
  final UserModel user;

  const AppDrawer({super.key, required this.user});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final currentLocation = GoRouterState.of(context).matchedLocation;

    return Drawer(
      child: Column(
        children: [
          // Drawer Header
          UserAccountsDrawerHeader(
            decoration: BoxDecoration(
              color: theme.colorScheme.primary,
            ),
            accountName: Text(
              user.displayName,
              style: const TextStyle(fontWeight: FontWeight.bold),
            ),
            accountEmail: Text(user.email),
            currentAccountPicture: CircleAvatar(
              backgroundColor: Colors.white,
              child: Text(
                user.displayName.isNotEmpty
                    ? user.displayName[0].toUpperCase()
                    : '?',
                style: TextStyle(
                  fontSize: 28,
                  fontWeight: FontWeight.bold,
                  color: theme.colorScheme.primary,
                ),
              ),
            ),
            otherAccountsPictures: [
              if (user.beltRank != null && user.beltRank!.isNotEmpty)
                Chip(
                  label: Text(
                    user.beltRank!,
                    style: const TextStyle(fontSize: 10, color: Colors.white),
                  ),
                  backgroundColor: theme.colorScheme.secondary,
                  padding: EdgeInsets.zero,
                  materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
                ),
            ],
          ),

          // Navigation items based on role
          Expanded(
            child: ListView(
              padding: EdgeInsets.zero,
              children: _buildNavItems(context, currentLocation, theme),
            ),
          ),

          // Logout
          const Divider(),
          ListTile(
            leading: const Icon(Icons.logout, color: Colors.red),
            title: const Text('Sign Out', style: TextStyle(color: Colors.red)),
            onTap: () {
              Navigator.pop(context);
              context.read<AuthBloc>().add(AuthLogoutRequested());
            },
          ),
          const SizedBox(height: 8),
        ],
      ),
    );
  }

  List<Widget> _buildNavItems(
    BuildContext context,
    String currentLocation,
    ThemeData theme,
  ) {
    if (user.isAdmin) {
      return _buildAdminNav(context, currentLocation, theme);
    }

    final items = _buildStudentNav(context, currentLocation, theme);

    if (user.isParentAccount) {
      items.addAll([
        const Divider(),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          child: Text(
            'PARENT',
            style: TextStyle(
              fontSize: 12,
              fontWeight: FontWeight.bold,
              color: Colors.grey[600],
            ),
          ),
        ),
        ..._buildParentNav(context, currentLocation, theme),
      ]);
    }

    return items;
  }

  List<Widget> _buildStudentNav(
    BuildContext context,
    String currentLocation,
    ThemeData theme,
  ) {
    return [
      _navTile(context, Icons.dashboard, 'Dashboard', '/student/dashboard',
          currentLocation, theme),
      _navTile(context, Icons.class_, 'My Classes', '/student/classes',
          currentLocation, theme),
      _navTile(context, Icons.fact_check, 'Attendance', '/student/attendance',
          currentLocation, theme),
      _navTile(context, Icons.event, 'Events', '/student/events',
          currentLocation, theme),
      _navTile(context, Icons.message, 'Messages', '/student/messages',
          currentLocation, theme),
      _navTile(context, Icons.military_tech, 'Belt Progress', '/student/belts',
          currentLocation, theme),
      _navTile(context, Icons.person, 'Profile', '/student/profile',
          currentLocation, theme),
    ];
  }

  List<Widget> _buildParentNav(
    BuildContext context,
    String currentLocation,
    ThemeData theme,
  ) {
    return [
      _navTile(context, Icons.people, 'My Children', '/parent/children',
          currentLocation, theme),
    ];
  }

  List<Widget> _buildAdminNav(
    BuildContext context,
    String currentLocation,
    ThemeData theme,
  ) {
    return [
      _navTile(context, Icons.dashboard, 'Dashboard', '/admin/dashboard',
          currentLocation, theme),
      _navTile(context, Icons.people, 'Students', '/admin/students',
          currentLocation, theme),
      _navTile(context, Icons.class_, 'Classes', '/admin/classes',
          currentLocation, theme),
      _navTile(context, Icons.fact_check, 'Attendance', '/admin/attendance',
          currentLocation, theme),
      _navTile(context, Icons.event, 'Events', '/admin/events',
          currentLocation, theme),
      _navTile(context, Icons.send, 'Messages', '/admin/messages',
          currentLocation, theme),
    ];
  }

  Widget _navTile(
    BuildContext context,
    IconData icon,
    String title,
    String route,
    String currentLocation,
    ThemeData theme,
  ) {
    final isSelected = currentLocation == route;
    return ListTile(
      leading: Icon(
        icon,
        color: isSelected ? theme.colorScheme.primary : null,
      ),
      title: Text(
        title,
        style: TextStyle(
          fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
          color: isSelected ? theme.colorScheme.primary : null,
        ),
      ),
      selected: isSelected,
      selectedTileColor: theme.colorScheme.primary.withOpacity(0.08),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
      onTap: () {
        Navigator.pop(context); // Close drawer
        if (!isSelected) {
          context.go(route);
        }
      },
    );
  }
}
