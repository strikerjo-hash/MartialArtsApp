    <!-- Footer -->
    <footer class="bg-white border-t border-gray-200 mt-8">
        <div class="container mx-auto px-4 py-4 text-center text-sm text-gray-600">
            <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars(getSiteName()); ?>. All rights reserved.</p>
        </div>
    </footer>

    <script>
        function confirmDelete(message = 'Are you sure?') {
            return confirm(message);
        }

        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('[role="alert"]');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 300);
                }, 5000);
            });
        });
    </script>
</body>
</html>
