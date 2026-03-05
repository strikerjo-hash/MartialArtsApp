import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:equatable/equatable.dart';
import 'package:martial_arts_app/core/constants/api_endpoints.dart';
import 'package:martial_arts_app/core/network/api_client.dart';
import 'package:martial_arts_app/core/storage/secure_storage.dart';
import 'package:martial_arts_app/features/auth/data/models/user_model.dart';
import 'package:martial_arts_app/features/notifications/push_notification_service.dart';

// ---------- Events ----------

abstract class AuthEvent extends Equatable {
  @override
  List<Object?> get props => [];
}

class AuthCheckStatus extends AuthEvent {}

class AuthLoginRequested extends AuthEvent {
  final String email;
  final String password;
  final String? deviceInfo;

  AuthLoginRequested({
    required this.email,
    required this.password,
    this.deviceInfo,
  });

  @override
  List<Object?> get props => [email, password];
}

class AuthAdminLoginRequested extends AuthEvent {
  final String username;
  final String password;
  final String? deviceInfo;

  AuthAdminLoginRequested({
    required this.username,
    required this.password,
    this.deviceInfo,
  });

  @override
  List<Object?> get props => [username, password];
}

class AuthLogoutRequested extends AuthEvent {}

// ---------- States ----------

abstract class AuthState extends Equatable {
  @override
  List<Object?> get props => [];
}

class AuthInitial extends AuthState {}

class AuthLoading extends AuthState {}

class AuthAuthenticated extends AuthState {
  final UserModel user;

  AuthAuthenticated({required this.user});

  @override
  List<Object?> get props => [user.id, user.userType];
}

class AuthUnauthenticated extends AuthState {}

class AuthError extends AuthState {
  final String message;

  AuthError({required this.message});

  @override
  List<Object?> get props => [message];
}

// ---------- BLoC ----------

class AuthBloc extends Bloc<AuthEvent, AuthState> {
  final ApiClient _apiClient;
  final SecureStorage _storage;
  final PushNotificationService? _pushService;

  AuthBloc({
    required ApiClient apiClient,
    required SecureStorage storage,
    PushNotificationService? pushService,
  })  : _apiClient = apiClient,
        _storage = storage,
        _pushService = pushService,
        super(AuthInitial()) {
    on<AuthCheckStatus>(_onCheckStatus);
    on<AuthLoginRequested>(_onLogin);
    on<AuthAdminLoginRequested>(_onAdminLogin);
    on<AuthLogoutRequested>(_onLogout);
  }

  Future<void> _onCheckStatus(
    AuthCheckStatus event,
    Emitter<AuthState> emit,
  ) async {
    final hasTokens = await _storage.hasTokens();
    if (!hasTokens) {
      emit(AuthUnauthenticated());
      return;
    }

    // Try to restore user from stored data
    final userData = await _storage.getUserData();
    final userType = await _storage.getUserType();
    if (userData != null && userType != null) {
      emit(AuthAuthenticated(
        user: UserModel.fromJson(userData, userType: userType),
      ));
    } else {
      emit(AuthUnauthenticated());
    }
  }

  Future<void> _onLogin(
    AuthLoginRequested event,
    Emitter<AuthState> emit,
  ) async {
    emit(AuthLoading());

    try {
      final response = await _apiClient.post(
        ApiEndpoints.login,
        data: {
          'email': event.email,
          'password': event.password,
          'device_info': event.deviceInfo ?? 'Flutter Android App',
        },
      );

      final data = response.data;
      final user = UserModel.fromJson(
        data['user'] as Map<String, dynamic>,
        userType: 'student',
      );

      await _storage.saveAuthTokens(
        accessToken: data['access_token'] as String,
        refreshToken: data['refresh_token'] as String,
        user: user.toJson(),
        userType: 'student',
      );

      emit(AuthAuthenticated(user: user));
    } catch (e) {
      final message = _extractErrorMessage(e);
      emit(AuthError(message: message));
      emit(AuthUnauthenticated());
    }
  }

  Future<void> _onAdminLogin(
    AuthAdminLoginRequested event,
    Emitter<AuthState> emit,
  ) async {
    emit(AuthLoading());

    try {
      final response = await _apiClient.post(
        ApiEndpoints.adminLogin,
        data: {
          'username': event.username,
          'password': event.password,
          'device_info': event.deviceInfo ?? 'Flutter Android App',
        },
      );

      final data = response.data;
      final user = UserModel.fromJson(
        data['user'] as Map<String, dynamic>,
        userType: 'admin',
      );

      await _storage.saveAuthTokens(
        accessToken: data['access_token'] as String,
        refreshToken: data['refresh_token'] as String,
        user: user.toJson(),
        userType: 'admin',
      );

      emit(AuthAuthenticated(user: user));
    } catch (e) {
      final message = _extractErrorMessage(e);
      emit(AuthError(message: message));
      emit(AuthUnauthenticated());
    }
  }

  Future<void> _onLogout(
    AuthLogoutRequested event,
    Emitter<AuthState> emit,
  ) async {
    // Unregister push token before logout (best-effort)
    try {
      await _pushService?.unregisterToken();
    } catch (_) {}

    // Call server logout endpoint
    try {
      final refreshToken = await _storage.getRefreshToken();
      if (refreshToken != null) {
        await _apiClient.post(
          ApiEndpoints.logout,
          data: {'refresh_token': refreshToken},
        );
      }
    } catch (_) {
      // Best-effort logout
    }

    await _storage.clearAll();
    emit(AuthUnauthenticated());
  }

  String _extractErrorMessage(dynamic error) {
    if (error is Exception) {
      try {
        final dioError = error as dynamic;
        if (dioError.response?.data != null) {
          final data = dioError.response.data;
          if (data is Map && data.containsKey('message')) {
            return data['message'] as String;
          }
        }
      } catch (_) {}
    }
    return 'An error occurred. Please try again.';
  }
}
