/// Represents an authenticated user (student or admin).
class UserModel {
  final int id;
  final String? firstName;
  final String? lastName;
  final String? username;
  final String? fullName;
  final String email;
  final String? phone;
  final String? beltRank;
  final String? role;
  final int schoolId;
  final int isParent;
  final String userType; // 'student' or 'admin'

  UserModel({
    required this.id,
    this.firstName,
    this.lastName,
    this.username,
    this.fullName,
    required this.email,
    this.phone,
    this.beltRank,
    this.role,
    required this.schoolId,
    this.isParent = 0,
    required this.userType,
  });

  String get displayName {
    if (fullName != null && fullName!.isNotEmpty) return fullName!;
    if (firstName != null) return '$firstName ${lastName ?? ''}'.trim();
    if (username != null) return username!;
    return email;
  }

  bool get isParentAccount => isParent == 1;
  bool get isAdmin => userType == 'admin';
  bool get isStudent => userType == 'student';

  factory UserModel.fromJson(Map<String, dynamic> json, {String userType = 'student'}) {
    return UserModel(
      id: json['id'] as int,
      firstName: json['first_name'] as String?,
      lastName: json['last_name'] as String?,
      username: json['username'] as String?,
      fullName: json['full_name'] as String?,
      email: (json['email'] as String?) ?? '',
      phone: json['phone'] as String?,
      beltRank: json['belt_rank'] as String?,
      role: json['role'] as String?,
      schoolId: (json['school_id'] as int?) ?? 1,
      isParent: (json['is_parent'] as int?) ?? 0,
      userType: userType,
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'first_name': firstName,
        'last_name': lastName,
        'username': username,
        'full_name': fullName,
        'email': email,
        'phone': phone,
        'belt_rank': beltRank,
        'role': role,
        'school_id': schoolId,
        'is_parent': isParent,
        'user_type': userType,
      };
}
