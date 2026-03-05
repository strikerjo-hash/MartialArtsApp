/// All API endpoint constants.
/// Base URL should be configured in ApiClient.
class ApiEndpoints {
  ApiEndpoints._();

  // Auth
  static const String login = '/auth/login';
  static const String adminLogin = '/auth/admin-login';
  static const String refresh = '/auth/refresh';
  static const String logout = '/auth/logout';

  // Student
  static const String studentProfile = '/student/profile';
  static const String studentClasses = '/student/classes';
  static const String studentAttendance = '/student/attendance';
  static const String studentMemberships = '/student/memberships';
  static const String studentEvents = '/student/events';
  static const String studentPayments = '/student/payments';
  static const String studentMessages = '/student/messages';
  static const String studentBelts = '/student/belts';
  static const String studentDeviceToken = '/student/device-token';

  static String studentEventRegister(int eventId) =>
      '/student/events/$eventId/register';
  static String studentMessageRead(int messageId) =>
      '/student/messages/$messageId/read';

  // Parent
  static const String parentChildren = '/parent/children';
  static String parentChild(int childId, String resource) =>
      '/parent/child/$childId/$resource';

  // Admin
  static const String adminDashboard = '/admin/dashboard';
  static const String adminStudents = '/admin/students';
  static String adminStudentDetail(int id) => '/admin/students/$id';
  static const String adminClasses = '/admin/classes';
  static String adminClassStudents(int classId) =>
      '/admin/classes/$classId/students';
  static const String adminAttendance = '/admin/attendance';
  static const String adminEvents = '/admin/events';
  static String adminEventDetail(int id) => '/admin/events/$id';
  static const String adminMessages = '/admin/messages';
  static const String adminDeviceToken = '/admin/device-token';

  // Public
  static const String theme = '/theme';
  static const String health = '/health';
}
