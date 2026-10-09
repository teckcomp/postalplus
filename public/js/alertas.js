/* Postal+ — toasts de alerta em todas as páginas do GLPI (hook add_javascript). Consulta ajax/alertas.php
   periodicamente; fechar marca o alerta como lido para este usuário. */
(function () {
    'use strict';

    var WEBPATH = '/plugins/postalplus';
    var tokenAtual = null;
    var mostrados = {};
    var timer = null;

    function root() {
        var r = (window.CFG_GLPI && CFG_GLPI.root_doc) ? CFG_GLPI.root_doc : '';
        return String(r || '').replace(/\/$/, '');
    }

    function csrf() {
        if (tokenAtual) { return tokenAtual; }
        var m = document.querySelector('meta[property="glpi:csrf_token"]');
        return m ? m.getAttribute('content') : '';
    }

    function url() { return root() + WEBPATH + '/ajax/alertas.php'; }

    function pedir(metodo, corpo) {
        var opts = {
            method: metodo,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': csrf(), 'Accept': 'application/json' }
        };
        if (corpo) {
            opts.headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
            opts.body = corpo;
        }
        return fetch(url(), opts).then(function (resp) {
            if (resp.status === 403) { return { ok: false, parar: true }; }
            return resp.text().then(function (t) { try { return JSON.parse(t); } catch (e) { return { ok: false }; } });
        }).catch(function () { return { ok: false }; }).then(function (r) {
            if (r && r.csrf) { tokenAtual = r.csrf; }
            return r || { ok: false };
        });
    }

    function caixa() {
        var c = document.getElementById('pp-toasts');
        if (!c) {
            c = document.createElement('div');
            c.id = 'pp-toasts';
            c.setAttribute('aria-live', 'polite');
            document.body.appendChild(c);
        }
        return c;
    }

    function el(tag, cls, texto) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        if (texto !== undefined) { e.textContent = texto; }
        return e;
    }

    function fechar(id, no) {
        if (no && no.parentNode) { no.parentNode.removeChild(no); }
        pedir('POST', 'lido=' + encodeURIComponent(id));
    }

    function mostrar(a) {
        if (mostrados[a.id]) { return; }
        mostrados[a.id] = true;
        var c = caixa();
        var t = el('div', 'pp-toast pp-toast-' + (a.nivel || 'info'));
        t.setAttribute('data-pp-alerta', a.id);
        var topo = el('div', 'pp-toast-topo');
        topo.appendChild(el('strong', '', a.titulo || 'Alerta'));
        var x = el('button', 'pp-toast-fechar', '×');
        x.type = 'button';
        x.title = 'Fechar (marca como lido)';
        x.addEventListener('click', function () { fechar(a.id, t); });
        topo.appendChild(x);
        t.appendChild(topo);
        if (a.mensagem) { t.appendChild(el('div', 'pp-toast-msg', a.mensagem)); }
        var rodape = el('div', 'pp-toast-rodape');
        rodape.appendChild(el('span', 'pp-sub', a.quando || ''));
        var link = el('a', 'ms-auto', 'Ver objeto');
        link.href = root() + WEBPATH + '/front/objeto.php?codigo=' + encodeURIComponent(a.codigo);
        link.addEventListener('click', function () { pedir('POST', 'lido=' + encodeURIComponent(a.id)); });
        rodape.appendChild(link);
        if (a.chamado) {
            var ch = el('a', 'ms-2', 'Chamado #' + a.chamado);
            ch.href = root() + '/front/ticket.form.php?id=' + a.chamado;
            rodape.appendChild(ch);
        }
        t.appendChild(rodape);
        c.appendChild(t);
    }

    function consultar() {
        pedir('GET').then(function (r) {
            if (!r || r.parar) { if (timer) { clearInterval(timer); timer = null; } return; }
            if (!r.ok || !Array.isArray(r.alertas)) { return; }
            // Alertas lidos em outra aba somem daqui também.
            var ativos = {};
            r.alertas.forEach(function (a) { ativos[a.id] = true; });
            Array.prototype.forEach.call(document.querySelectorAll('[data-pp-alerta]'), function (n) {
                if (!ativos[n.getAttribute('data-pp-alerta')]) { n.parentNode.removeChild(n); }
            });
            r.alertas.slice().reverse().forEach(mostrar);
            if (!timer && r.intervalo) { timer = setInterval(consultar, Math.max(15, r.intervalo) * 1000); }
        });
    }

    function iniciar() {
        if (!document.body || !document.querySelector('meta[property="glpi:csrf_token"]')) { return; }
        consultar();
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', iniciar); } else { iniciar(); }

    window.PostalplusAlertas = { mostrar: mostrar, consultar: consultar };
})();
