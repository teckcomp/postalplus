/* Postal+ — Regras de alerta: esmaece o corpo da regra desativada. */
(function () {
    'use strict';

    function iniciar() {
        document.querySelectorAll('[data-pp-regra]').forEach(function (card) {
            var chave = card.querySelector('[data-pp-ativa]');
            if (!chave) { return; }
            chave.addEventListener('change', function () {
                card.classList.toggle('inativa', !chave.checked);
            });
        });
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', iniciar); } else { iniciar(); }
})();
