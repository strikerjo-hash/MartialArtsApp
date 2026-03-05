import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/material.dart';

/// A widget that checks network connectivity and shows appropriate UI.
///
/// - Online: builds [builder]
/// - Offline with cached data: builds [builder] + shows an offline banner
/// - Offline without data: shows [offlineWidget] or a default offline screen
class NetworkAwareBuilder extends StatelessWidget {
  /// Builds the main content when data is available.
  final Widget Function(BuildContext context) builder;

  /// Shown when offline and no cached data is available.
  final Widget? offlineWidget;

  /// Whether cached data is available to show while offline.
  final bool hasCachedData;

  /// Callback when user taps retry.
  final VoidCallback? onRetry;

  const NetworkAwareBuilder({
    super.key,
    required this.builder,
    this.offlineWidget,
    this.hasCachedData = false,
    this.onRetry,
  });

  @override
  Widget build(BuildContext context) {
    return StreamBuilder<List<ConnectivityResult>>(
      stream: Connectivity().onConnectivityChanged,
      builder: (context, snapshot) {
        final results = snapshot.data ?? [ConnectivityResult.none];
        final isOffline = results.every((r) => r == ConnectivityResult.none);

        if (isOffline && !hasCachedData) {
          return offlineWidget ?? _DefaultOfflineWidget(onRetry: onRetry);
        }

        return Column(
          children: [
            if (isOffline)
              MaterialBanner(
                content: const Text(
                  'You are offline. Showing cached data.',
                  style: TextStyle(color: Colors.white, fontSize: 13),
                ),
                backgroundColor: Colors.orange[700]!,
                leading:
                    const Icon(Icons.wifi_off, color: Colors.white, size: 20),
                actions: [
                  TextButton(
                    onPressed: onRetry,
                    child: const Text('Retry',
                        style: TextStyle(color: Colors.white)),
                  ),
                ],
              ),
            Expanded(child: builder(context)),
          ],
        );
      },
    );
  }
}

/// Default offline screen with a retry button.
class _DefaultOfflineWidget extends StatelessWidget {
  final VoidCallback? onRetry;

  const _DefaultOfflineWidget({this.onRetry});

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.wifi_off, size: 80, color: Colors.grey[400]),
            const SizedBox(height: 24),
            Text(
              'No Internet Connection',
              style: theme.textTheme.headlineSmall?.copyWith(
                fontWeight: FontWeight.bold,
              ),
            ),
            const SizedBox(height: 8),
            Text(
              'Check your connection and try again.',
              style: theme.textTheme.bodyMedium?.copyWith(
                color: Colors.grey[600],
              ),
              textAlign: TextAlign.center,
            ),
            if (onRetry != null) ...[
              const SizedBox(height: 24),
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
}

/// A standalone full-screen error widget with retry.
class AppNetworkError extends StatelessWidget {
  final String message;
  final VoidCallback? onRetry;

  const AppNetworkError({
    super.key,
    this.message = 'Something went wrong',
    this.onRetry,
  });

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.error_outline, size: 72, color: Colors.red[300]),
            const SizedBox(height: 20),
            Text(
              message,
              style: theme.textTheme.titleMedium?.copyWith(
                fontWeight: FontWeight.w600,
              ),
              textAlign: TextAlign.center,
            ),
            if (onRetry != null) ...[
              const SizedBox(height: 20),
              OutlinedButton.icon(
                onPressed: onRetry,
                icon: const Icon(Icons.refresh),
                label: const Text('Try Again'),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
