<?php
// public/inc/footer.php
// Matcher header.php med printMode (?print=1):
// - Normal: lukker app-shell + sidebar toggle + bootstrap bundle
// - Print: lukker kun <main> og dropper JS (for å unngå print preview-heng)

$printMode = (($_GET['print'] ?? '') === '1');
?>

<?php if ($printMode): ?>
    </main>
<?php else: ?>
    </main><!-- /.app-content -->
    </div><!-- /.app-main -->
    </div><!-- /.app-shell -->

    <script>
    (function () {
        var toggleBtn = document.getElementById('sidebarToggle');
        var shell     = document.querySelector('.app-shell');

        if (!toggleBtn || !shell) return;

        toggleBtn.addEventListener('click', function () {
            shell.classList.toggle('sidebar-expanded');

            var expanded = shell.classList.contains('sidebar-expanded') ? '1' : '0';
            document.cookie = 'sidebar_expanded=' + expanded
                + ';path=/;max-age=' + (60 * 60 * 24 * 365);
        });
    })();
    </script>

    <!-- Bootstrap JS (lokalt anbefalt, men lar den stå som du hadde hvis du ikke har lokal kopi enda) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php endif; ?>

</body>
</html>
