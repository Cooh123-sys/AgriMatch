<footer class="bg-success text-white text-center py-3 mt-5">
    <div class="container">
        <p class="mb-0">
            &copy; <?php echo date('Y'); ?> AgriMatch &mdash; Web-Based Aggregation and Matching System
            for Smallholder Farmers and Buyers in Rural Blantyre
        </p>
        <small>Developed by Comfort Olesi (BIS/21/SS/027) | MUBAS</small>
    </div>
</footer>

<!-- Bootstrap 5 JS Bundle (includes Popper) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Auto-dismiss flash alerts after 4 seconds (X button still works manually)
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.alert-dismissible').forEach(function (alertEl) {
            setTimeout(function () {
                var bsAlert = bootstrap.Alert.getOrCreateInstance(alertEl);
                if (bsAlert) bsAlert.close();
            }, 4000);
        });
    });
</script>

<!-- Custom JS -->
<script src="/AgriMatch/assets/js/script.js"></script>