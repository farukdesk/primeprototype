    </main><!-- /#content -->
</div><!-- /#main-wrapper -->

<!-- Bootstrap 5 Bundle (includes Popper) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(e) {
        var sidebar = document.getElementById('sidebar');
        if (window.innerWidth <= 768 && sidebar.classList.contains('open')) {
            if (!sidebar.contains(e.target) && !e.target.closest('.toggle-btn')) {
                sidebar.classList.remove('open');
            }
        }
    });
</script>
</body>
</html>
