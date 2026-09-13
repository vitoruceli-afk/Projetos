
        </div><!-- /main-content -->
    </div><!-- /main-col -->
</div><!-- /app-shell -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
(function () {
    var rail = document.getElementById('rail');
    var backdrop = document.getElementById('railBackdrop');
    var toggle = document.getElementById('railToggle');
    if (rail && backdrop && toggle) {
        var openRail = function () {
            rail.classList.add('is-open');
            backdrop.classList.add('is-visible');
            toggle.setAttribute('aria-expanded', 'true');
        };
        var closeRail = function () {
            rail.classList.remove('is-open');
            backdrop.classList.remove('is-visible');
            toggle.setAttribute('aria-expanded', 'false');
        };
        toggle.addEventListener('click', function () {
            rail.classList.contains('is-open') ? closeRail() : openRail();
        });
        backdrop.addEventListener('click', closeRail);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeRail();
        });
        rail.querySelectorAll('a.rail-link, a.rail-logout').forEach(function (link) {
            link.addEventListener('click', closeRail);
        });
    }
})();

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
