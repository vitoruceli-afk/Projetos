
</div><!-- /container -->

<footer class="text-center text-muted py-3 small">
    <?= e(\App\Core\Config::get('app.nome')) ?> &middot; <?= date('Y') ?>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
/*
 * Marca/desmarca todos os checkboxes de uma listagem.
 */
document.addEventListener('DOMContentLoaded', function () {
    const selTodos = document.getElementById('selecionarTodos');
    if (selTodos) {
        selTodos.addEventListener('change', function () {
            document.querySelectorAll('.checkbox-item')
                .forEach(cb => { cb.checked = this.checked; });
        });
    }
});
</script>

</body>
</html>
