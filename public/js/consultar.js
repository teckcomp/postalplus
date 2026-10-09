/* Postal+ — botões "Consultar agora" (Painel e Detalhe): POST AJAX e recarrega a página com o resultado. */
(function () {
    'use strict';

    var tokenAtual = null;

    function csrf() {
        if (tokenAtual) { return tokenAtual; }
        var m = document.querySelector('meta[property="glpi:csrf_token"]');
        return m ? m.getAttribute('content') : '';
    }

    /** nivel: true/'ok' => verde, 'parcial' => amarelo, false/'erro' => vermelho */
    function aviso(caixa, nivel, texto) {
        if (!caixa) { return; }
        var cls = nivel === true || nivel === 'ok' ? 'alert-success' : (nivel === 'parcial' ? 'alert-warning' : 'alert-danger');
        caixa.className = 'alert ' + cls + ' mt-2 mb-0';
        caixa.innerHTML = '';
        var div = document.createElement('div');
        div.textContent = texto;
        caixa.appendChild(div);
    }

    /** Envia a consulta; devolve uma Promise com o JSON (nunca rejeita). */
    function consultar(url, campos) {
        var corpo = new URLSearchParams();
        Object.keys(campos).forEach(function (k) { corpo.append(k, campos[k]); });
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-Glpi-Csrf-Token': csrf(),
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'Accept': 'application/json'
            },
            body: corpo.toString()
        }).then(function (resp) {
            return resp.text().then(function (txt) {
                try { return JSON.parse(txt); } catch (e) { return { ok: false, mensagem: 'Resposta inválida do servidor (HTTP ' + resp.status + ').' }; }
            });
        }).catch(function () {
            return { ok: false, mensagem: 'Não foi possível falar com o GLPI.' };
        }).then(function (r) {
            if (!r || typeof r !== 'object') { r = { ok: false, mensagem: 'Resposta vazia.' }; }
            if (r.csrf) { tokenAtual = r.csrf; }
            return r;
        });
    }

    var CHAVE = 'postalplus-consulta';

    /** Consulta feita com algum erro (sem interromper) = parcial. */
    function nivelDe(r) {
        if (r.ok) { return 'ok'; }
        if (!r.interrompida && typeof r.objetos === 'number' && r.objetos > 0 && r.erros < r.objetos) { return 'parcial'; }
        return 'erro';
    }

    function guardar(r) {
        try { window.sessionStorage.setItem(CHAVE, JSON.stringify({ ok: nivelDe(r), mensagem: r.mensagem || '' })); } catch (e) { /* sem storage: só não mostra após recarregar */ }
    }

    function recuperar() {
        try {
            var v = window.sessionStorage.getItem(CHAVE);
            window.sessionStorage.removeItem(CHAVE);
            return v ? JSON.parse(v) : null;
        } catch (e) { return null; }
    }

    function iniciar() {
        var anterior = recuperar();
        document.querySelectorAll('[data-pp-consultar]').forEach(function (botao) {
            if (anterior && anterior.mensagem) {
                aviso(document.getElementById(botao.getAttribute('data-pp-resultado') || ''), anterior.ok, anterior.mensagem);
                anterior = null;
            }
            botao.addEventListener('click', function () {
                if (botao.disabled) { return; }
                var caixa = document.getElementById(botao.getAttribute('data-pp-resultado') || '');
                var campos = botao.getAttribute('data-codigo') ? { codigo: botao.getAttribute('data-codigo') } : { todos: '1' };
                var original = botao.innerHTML;
                botao.disabled = true;
                botao.textContent = 'Consultando…';

                consultar(botao.getAttribute('data-url'), campos).then(function (r) {
                    var houveConsulta = typeof r.objetos === 'number' && r.objetos > 0;
                    aviso(caixa, nivelDe(r), r.mensagem || (r.ok ? 'Consulta concluída.' : 'A consulta falhou.'));
                    botao.disabled = false;
                    botao.innerHTML = original;
                    if (houveConsulta && botao.getAttribute('data-recarregar') !== '0') {
                        guardar(r);
                        window.setTimeout(function () { window.location.reload(); }, 600);
                    }
                });
            });
        });
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', iniciar); } else { iniciar(); }

    window.PostalplusConsultar = { consultar: consultar };
})();
