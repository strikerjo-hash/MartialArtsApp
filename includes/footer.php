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
            var sidebar = document.getElementById('mobileSidebar');
            if (sidebar && sidebar.classList.contains('-translate-x-full')) { openSidebar(); } else { closeSidebar(); }
        }
        function openSidebar() {
            var s = document.getElementById('mobileSidebar'), o = document.getElementById('sidebarOverlay'), t = document.getElementById('sidebarToggle');
            if (!s) return;
            s.classList.remove('-translate-x-full'); s.classList.add('translate-x-0');
            if (o) o.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
            if (t) t.setAttribute('aria-expanded', 'true');
        }
        function closeSidebar() {
            var s = document.getElementById('mobileSidebar'), o = document.getElementById('sidebarOverlay'), t = document.getElementById('sidebarToggle');
            if (!s) return;
            s.classList.add('-translate-x-full'); s.classList.remove('translate-x-0');
            if (o) o.classList.add('hidden');
            document.body.style.overflow = '';
            if (t) t.setAttribute('aria-expanded', 'false');
        }
        document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeSidebar(); });
        window.addEventListener('resize', function() { if (window.innerWidth >= 768) closeSidebar(); });
        
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
