import 'package:flutter/material.dart';

/// Application-wide constants
class AppConstants {
  AppConstants._();

  static const String appName = 'Martial Arts School';
  static const int defaultSchoolId = 1;

  // Token expiry buffer (refresh 5 minutes before expiry)
  static const int tokenRefreshBufferSeconds = 300;

  // Pagination
  static const int defaultPageSize = 25;
  static const int maxPageSize = 100;
}

/// Belt color mapping
class BeltColors {
  BeltColors._();

  static Color fromName(String? colorName) {
    if (colorName == null) return Colors.grey;
    switch (colorName.toLowerCase()) {
      case 'white':
        return Colors.white;
      case 'yellow':
        return const Color(0xFFFFD700);
      case 'orange':
        return Colors.orange;
      case 'green':
        return Colors.green;
      case 'blue':
        return Colors.blue;
      case 'purple':
        return Colors.purple;
      case 'brown':
        return const Color(0xFF8B4513);
      case 'red':
        return Colors.red;
      case 'black':
        return Colors.black;
      default:
        return Colors.grey;
    }
  }
}

/// Attendance status helpers
class AttendanceStatus {
  static Color color(String status) {
    switch (status.toLowerCase()) {
      case 'present':
        return Colors.green;
      case 'absent':
        return Colors.red;
      case 'late':
        return Colors.orange;
      case 'excused':
        return Colors.blueGrey;
      default:
        return Colors.grey;
    }
  }

  static IconData icon(String status) {
    switch (status.toLowerCase()) {
      case 'present':
        return Icons.check_circle;
      case 'absent':
        return Icons.cancel;
      case 'late':
        return Icons.access_time;
      case 'excused':
        return Icons.info;
      default:
        return Icons.help;
    }
  }
}

/// Event type helpers
class EventTypes {
  static IconData icon(String type) {
    switch (type.toLowerCase()) {
      case 'belt_test':
        return Icons.military_tech;
      case 'tournament':
        return Icons.emoji_events;
      case 'seminar':
        return Icons.school;
      case 'workshop':
        return Icons.build;
      case 'demonstration':
        return Icons.visibility;
      case 'camp':
        return Icons.terrain;
      default:
        return Icons.event;
    }
  }

  static String label(String type) {
    switch (type.toLowerCase()) {
      case 'belt_test':
        return 'Belt Test';
      case 'tournament':
        return 'Tournament';
      case 'seminar':
        return 'Seminar';
      case 'workshop':
        return 'Workshop';
      case 'demonstration':
        return 'Demonstration';
      case 'camp':
        return 'Camp';
      default:
        return 'Other';
    }
  }
}
