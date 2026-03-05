import 'package:flutter/material.dart';

/// A convenience widget that wraps content in a RefreshIndicator
/// with consistent styling and error handling.
///
/// Usage:
/// ```dart
/// PullToRefreshPage(
///   onRefresh: () => _loadData(),
///   isLoading: _isLoading,
///   error: _errorMessage,
///   onRetry: () => _loadData(),
///   loadingBuilder: () => const SkeletonDashboard(),
///   child: ListView(...),
/// )
/// ```
class PullToRefreshPage extends StatelessWidget {
  /// The main content to display.
  final Widget child;

  /// Called when the user pulls to refresh.
  final Future<void> Function() onRefresh;

  /// Whether the page is currently loading data.
  final bool isLoading;

  /// Error message to display (null = no error).
  final String? error;

  /// Called when user taps retry on error state.
  final VoidCallback? onRetry;

  /// Builder for the loading state (e.g., skeleton shimmer).
  /// If null, shows a centered CircularProgressIndicator.
  final Widget Function()? loadingBuilder;

  const PullToRefreshPage({
    super.key,
    required this.child,
    required this.onRefresh,
    this.isLoading = false,
    this.error,
    this.onRetry,
    this.loadingBuilder,
  });

  @override
  Widget build(BuildContext context) {
    // Error state
    if (error != null && !isLoading) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.error_outline, size: 64, color: Colors.red[300]),
              const SizedBox(height: 16),
              Text(
                error!,
                style: Theme.of(context).textTheme.titleMedium,
                textAlign: TextAlign.center,
              ),
              if (onRetry != null) ...[
                const SizedBox(height: 16),
                ElevatedButton.icon(
                  onPressed: onRetry,
                  icon: const Icon(Icons.refresh),
                  label: const Text('Retry'),
                ),
              ],
            ],
          ),
        ),
      );
    }

    // Loading state
    if (isLoading) {
      if (loadingBuilder != null) {
        return loadingBuilder!();
      }
      return const Center(child: CircularProgressIndicator());
    }

    // Data state with pull-to-refresh
    return RefreshIndicator(
      onRefresh: onRefresh,
      child: child,
    );
  }
}
