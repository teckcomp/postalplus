/* Postal+ — Painel: filtro pelos cards, busca e fechamento dos toasts (client-side). */
(function () {
    'use strict';

    function iniciar() {
        var raiz = document.querySelector('[data-pp-tela="painel"]');
        if (!raiz) { return; }

        var linhas = Array.prototype.slice.call(raiz.querySelectorAll('tr[data-pp-linha]'));
        var cards  = Array.prototype.slice.call(raiz.querySelectorAll('[data-pp-card]'));
        var busca  = document.getElementById('pp-busca');
        var qtd    = document.getElementById('pp-qtd');
        var titulo = document.getElementById('pp-titulo-lista');
        var vazio  = document.getElementById('pp-vazio');
        var limpar = document.getElementById('pp-limpar');
        var filtro = '';

        function aplicar() {
            var termo = (busca && busca.value ? busca.value : '').trim().toLowerCase();
            var n = 0;
            linhas.forEach(function (tr) {
                var vis = (!filtro || tr.getAttribute('data-card') === filtro)
                    && (!termo || (tr.getAttribute('data-busca') || '').indexOf(termo) !== -1);
                tr.classList.toggle('d-none', !vis);
                if (vis) { n++; }
            });
            if (qtd) { qtd.textContent = String(n); }
            if (vazio) { vazio.classList.toggle('d-none', n !== 0); }
            cards.forEach(function (c) {
                var ativo = c.getAttribute('data-pp-card') === filtro;
                c.classList.toggle('ativo', ativo);
                c.setAttribute('aria-pressed', ativo ? 'true' : 'false');
            });
            if (titulo) {
                var ativoCard = cards.filter(function (c) { return c.getAttribute('data-pp-card') === filtro; })[0];
                titulo.textContent = ativoCard ? ativoCard.querySelector('.pp-st').textContent : 'Todos os objetos em acompanhamento';
            }
            if (limpar) { limpar.classList.toggle('d-none', !filtro && !termo); }
        }

        cards.forEach(function (c) {
            c.addEventListener('click', function () {
                var k = c.getAttribute('data-pp-card');
                filtro = filtro === k ? '' : k;
                aplicar();
            });
        });
        if (busca) { busca.addEventListener('input', aplicar); }
        if (limpar) {
            limpar.addEventListener('click', function () {
                filtro = '';
                if (busca) { busca.value = ''; }
                aplicar();
            });
        }

        raiz.querySelectorAll('[data-pp-fechar]').forEach(function (b) {
            b.addEventListener('click', function () {
                var t = b.closest('[data-pp-toast]');
                if (t) { t.remove(); }
            });
        });
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', iniciar); } else { iniciar(); }
})();
