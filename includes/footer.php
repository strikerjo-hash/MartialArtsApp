            </main>
            
            <!-- Footer -->
            <footer class="bg-white border-t border-gray-200 px-6 py-4">
                <div class="flex items-center justify-between text-sm text-gray-600">
                    <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars(getSiteName()); ?>. All rights reserved.</p>
                    <p>Version 1.0.0</p>
                </div>
            </footer>
        </div>
    </div>
    
    <script>
        function toggleSidebar() {
            // Mobile sidebar toggle functionality
            const sidebar = document.querySelector('aside');
            sidebar.classList.toggle('hidden');
        }
        
        function confirmDelete(message = 'Are you sure you want to delete this item?') {
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
