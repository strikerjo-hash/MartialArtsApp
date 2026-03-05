import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';

/// A CircleAvatar that loads and caches a network image,
/// with a fallback to initials when the image is unavailable.
class CachedAvatar extends StatelessWidget {
  /// The URL of the avatar image. Can be null.
  final String? imageUrl;

  /// The display name used to generate initials as fallback.
  final String name;

  /// Radius of the avatar circle.
  final double radius;

  /// Background color of the fallback initials circle.
  final Color? backgroundColor;

  const CachedAvatar({
    super.key,
    this.imageUrl,
    required this.name,
    this.radius = 24,
    this.backgroundColor,
  });

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final bgColor = backgroundColor ?? theme.colorScheme.primary;
    final initials = _getInitials(name);

    if (imageUrl != null && imageUrl!.isNotEmpty) {
      return CachedNetworkImage(
        imageUrl: imageUrl!,
        imageBuilder: (context, imageProvider) => CircleAvatar(
          radius: radius,
          backgroundImage: imageProvider,
        ),
        placeholder: (context, url) => CircleAvatar(
          radius: radius,
          backgroundColor: bgColor.withOpacity(0.2),
          child: SizedBox(
            width: radius,
            height: radius,
            child: const CircularProgressIndicator(strokeWidth: 2),
          ),
        ),
        errorWidget: (context, url, error) => _initialsAvatar(
          initials,
          bgColor,
        ),
      );
    }

    return _initialsAvatar(initials, bgColor);
  }

  Widget _initialsAvatar(String initials, Color bgColor) {
    return CircleAvatar(
      radius: radius,
      backgroundColor: bgColor,
      child: Text(
        initials,
        style: TextStyle(
          color: Colors.white,
          fontWeight: FontWeight.bold,
          fontSize: radius * 0.7,
        ),
      ),
    );
  }

  String _getInitials(String name) {
    if (name.isEmpty) return '?';
    final parts = name.trim().split(' ');
    if (parts.length >= 2) {
      return '${parts[0][0]}${parts[1][0]}'.toUpperCase();
    }
    return parts[0][0].toUpperCase();
  }
}
