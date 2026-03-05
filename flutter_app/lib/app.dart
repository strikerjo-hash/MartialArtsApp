import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';
import 'package:martial_arts_app/core/network/api_client.dart';
import 'package:martial_arts_app/core/storage/secure_storage.dart';
import 'package:martial_arts_app/core/theme/app_theme.dart';
import 'package:martial_arts_app/core/theme/dynamic_theme_provider.dart';
import 'package:martial_arts_app/features/auth/presentation/bloc/auth_bloc.dart';
import 'package:martial_arts_app/features/auth/presentation/pages/login_page.dart';
import 'package:martial_arts_app/features/notifications/push_notification_service.dart';
import 'package:martial_arts_app/features/student/dashboard/presentation/pages/student_dashboard_page.dart';
import 'package:martial_arts_app/features/student/classes/presentation/pages/student_classes_page.dart';
import 'package:martial_arts_app/features/student/attendance/presentation/pages/student_attendance_page.dart';
import 'package:martial_arts_app/features/student/events/presentation/pages/student_events_page.dart';
import 'package:martial_arts_app/features/student/messages/presentation/pages/student_messages_page.dart';
import 'package:martial_arts_app/features/student/belts/presentation/pages/student_belts_page.dart';
import 'package:martial_arts_app/features/student/profile/presentation/pages/student_profile_page.dart';
import 'package:martial_arts_app/features/parent/children/presentation/pages/parent_children_page.dart';
import 'package:martial_arts_app/features/parent/child_detail/presentation/pages/parent_child_detail_page.dart';
import 'package:martial_arts_app/features/admin/dashboard/presentation/pages/admin_dashboard_page.dart';
import 'package:martial_arts_app/features/admin/students/presentation/pages/admin_students_page.dart';
import 'package:martial_arts_app/features/admin/classes/presentation/pages/admin_classes_page.dart';
import 'package:martial_arts_app/features/admin/attendance/presentation/pages/admin_attendance_page.dart';
import 'package:martial_arts_app/features/admin/events/presentation/pages/admin_events_page.dart';
import 'package:martial_arts_app/features/admin/messages/presentation/pages/admin_messages_page.dart';

class MartialArtsApp extends StatefulWidget {
  final SecureStorage storage;
  final ApiClient apiClient;
  final PushNotificationService pushService;

  const MartialArtsApp({
    super.key,
    required this.storage,
    required this.apiClient,
    required this.pushService,
  });

  @override
  State<MartialArtsApp> createState() => _MartialArtsAppState();
}

class _MartialArtsAppState extends State<MartialArtsApp> {
  late final AuthBloc _authBloc;
  late final GoRouter _router;
  late final DynamicThemeProvider _themeProvider;
  bool _pushInitialized = false;

  @override
  void initState() {
    super.initState();

    // Dynamic theme: load cached branding, then fetch fresh
    _themeProvider = DynamicThemeProvider();
    _themeProvider.initialize(widget.apiClient);
    _themeProvider.addListener(_onThemeChange);

    _authBloc = AuthBloc(
      apiClient: widget.apiClient,
      storage: widget.storage,
      pushService: widget.pushService,
    );
    _authBloc.add(AuthCheckStatus());
    _router = _createRouter();

    // Wire up notification deep-link routing
    widget.pushService.onNotificationTap = (route) {
      _router.go(route);
    };

    // Listen for auth state to init push after login
    _authBloc.stream.listen((state) {
      if (state is AuthAuthenticated && !_pushInitialized) {
        _pushInitialized = true;
        widget.pushService.initialize();
      }
      if (state is AuthUnauthenticated) {
        _pushInitialized = false;
      }
    });
  }

  void _onThemeChange() {
    // Rebuild the widget tree when the theme updates
    if (mounted) setState(() {});
  }

  @override
  void dispose() {
    _themeProvider.removeListener(_onThemeChange);
    _themeProvider.dispose();
    _authBloc.close();
    super.dispose();
  }

  GoRouter _createRouter() {
    return GoRouter(
      refreshListenable: GoRouterRefreshStream(_authBloc.stream),
      redirect: (context, state) {
        final authState = _authBloc.state;
        final isLoginRoute = state.matchedLocation == '/login';

        if (authState is AuthUnauthenticated || authState is AuthInitial) {
          return isLoginRoute ? null : '/login';
        }

        if (authState is AuthAuthenticated && isLoginRoute) {
          if (authState.user.isAdmin) return '/admin/dashboard';
          return '/student/dashboard';
        }

        return null;
      },
      routes: [
        GoRoute(path: '/login', builder: (_, __) => const LoginPage()),

        // Student routes
        GoRoute(
          path: '/student/dashboard',
          builder: (_, __) => const StudentDashboardPage(),
        ),
        GoRoute(
          path: '/student/classes',
          builder: (_, __) => const StudentClassesPage(),
        ),
        GoRoute(
          path: '/student/attendance',
          builder: (_, __) => const StudentAttendancePage(),
        ),
        GoRoute(
          path: '/student/events',
          builder: (_, __) => const StudentEventsPage(),
        ),
        GoRoute(
          path: '/student/messages',
          builder: (_, __) => const StudentMessagesPage(),
        ),
        GoRoute(
          path: '/student/belts',
          builder: (_, __) => const StudentBeltsPage(),
        ),
        GoRoute(
          path: '/student/profile',
          builder: (_, __) => const StudentProfilePage(),
        ),

        // Parent routes
        GoRoute(
          path: '/parent/children',
          builder: (_, __) => const ParentChildrenPage(),
        ),
        GoRoute(
          path: '/parent/child/:childId',
          builder: (_, state) => ParentChildDetailPage(
            childId: int.parse(state.pathParameters['childId']!),
          ),
        ),

        // Admin routes
        GoRoute(
          path: '/admin/dashboard',
          builder: (_, __) => const AdminDashboardPage(),
        ),
        GoRoute(
          path: '/admin/students',
          builder: (_, __) => const AdminStudentsPage(),
        ),
        GoRoute(
          path: '/admin/classes',
          builder: (_, __) => const AdminClassesPage(),
        ),
        GoRoute(
          path: '/admin/attendance',
          builder: (_, __) => const AdminAttendancePage(),
        ),
        GoRoute(
          path: '/admin/events',
          builder: (_, __) => const AdminEventsPage(),
        ),
        GoRoute(
          path: '/admin/messages',
          builder: (_, __) => const AdminMessagesPage(),
        ),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    return BlocProvider.value(
      value: _authBloc,
      child: MaterialApp.router(
        title: _themeProvider.schoolName,
        theme: _themeProvider.theme,
        routerConfig: _router,
        debugShowCheckedModeBanner: false,
      ),
    );
  }
}

/// Bridges BLoC stream to GoRouter's Listenable for redirect reactivity.
class GoRouterRefreshStream extends ChangeNotifier {
  GoRouterRefreshStream(Stream<dynamic> stream) {
    stream.listen((_) => notifyListeners());
  }
}
