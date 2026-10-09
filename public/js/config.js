/* Postal+ — tela de Configuração: botão "Testar conexão" (AJAX). */
(function () {
    'use strict';

    // Token CSRF: começa com o da página (meta do GLPI) e rotaciona com o "csrf" devolvido pelo AJAX.
    // Não sobrescreve a meta do GLPI, que é usada pelo JS do próprio core.
    var tokenAtual = null;

    function csrf() {
        if (tokenAtual) { return tokenAtual; }
        var m = document.querySelector('meta[property="glpi:csrf_token"]');
        return m ? m.getAttribute('content') : '';
    }

    function guardarCsrf(token) {
        if (token) { tokenAtual = token; }
    }

    function el(tag, cls, texto) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        if (texto !== undefined) { e.textContent = texto; }
        return e;
    }

    function linha(lista, rotulo, valor) {
        var dt = el('dt', 'col-5', rotulo);
        var dd = el('dd', 'col-7', valor);
        lista.appendChild(dt);
        lista.appendChild(dd);
    }

    function formatarData(iso) {
        if (!iso) { return '—'; }
        var p = String(iso).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        return p ? p[3] + '/' + p[2] + '/' + p[1] + ' ' + p[4] + ':' + p[5] : String(iso);
    }

    function renderizar(caixa, r) {
        caixa.innerHTML = '';
        caixa.classList.remove('d-none');

        var ok = r && r.ok === true;
        var alerta = el('div', 'alert mb-0 ' + (ok ? 'alert-success' : (r && r.token ? 'alert-warning' : 'alert-danger')));
        alerta.setAttribute('data-pp-teste', ok ? 'ok' : 'falha');

        // .alert do Tabler é flex: o conteúdo vai num bloco único para empilhar título e lista.
        var corpo = el('div', 'w-100');
        alerta.appendChild(corpo);
        var titulo = el('div', 'fw-semibold mb-2', ok ? 'Conexão OK' : (r && r.token ? 'Token gerado, mas a API Rastro não confirmou' : 'Falha na conexão'));
        corpo.appendChild(titulo);

        var dl = el('dl', 'row mb-0 small');
        if (r && r.ambiente) { linha(dl, 'Ambiente', r.ambiente + (r.host ? ' · ' + r.host : '') + (r.simulado ? ' (simulado)' : '')); }
        if (r && r.token) {
            linha(dl, 'Token válido até', formatarData(r.token.expira));
            if (r.token.cartao) { linha(dl, 'Cartão / contrato', r.token.cartao + (r.token.contrato ? ' / ' + r.token.contrato : '')); }
            if (Array.isArray(r.token.apis) && r.token.apis.length) { linha(dl, 'APIs no token', r.token.apis.join(', ')); }
        }
        if (r && r.rastro) {
            var estado = r.rastro.liberada === true ? 'Liberada' : (r.rastro.liberada === false ? 'Não liberada' : 'Indeterminado');
            linha(dl, 'API Rastro', estado + ' (HTTP ' + r.rastro.status + ')');
            linha(dl, '', r.rastro.mensagem || '');
        }
        if (r && r.erro) { linha(dl, 'Erro', r.erro); }
        corpo.appendChild(dl);
        caixa.appendChild(alerta);

        // Token novo gravado: atualiza o selo sem recarregar a página.
        var selo = document.getElementById('pp-token');
        if (selo && r && r.token && r.token.expira) {
            selo.setAttribute('data-pp-token', 'valido');
            selo.innerHTML = '';
            var b = el('span', 'badge bg-green-lt');
            b.appendChild(el('i', 'ti ti-key me-1'));
            b.appendChild(document.createTextNode('Token válido até ' + formatarData(r.token.expira)));
            selo.appendChild(b);
        }
    }

    /** "Testar rastreio de um código": resposta crua + classificação de cada evento. */
    function diagnostico() {
        var botao = document.getElementById('pp-diag-testar');
        var campo = document.getElementById('pp-diag-codigo');
        var caixa = document.getElementById('pp-diag-resultado');
        if (!botao || !campo || !caixa) { return; }

        function mostrar(r) {
            caixa.innerHTML = '';
            caixa.classList.remove('d-none');
            var alerta = el('div', 'alert mb-2 ' + (r.ok ? 'alert-success' : 'alert-warning'));
            alerta.setAttribute('data-pp-diag', r.ok ? 'ok' : 'falha');
            var corpo = el('div', 'w-100');
            corpo.appendChild(el('div', 'fw-semibold', r.ok ? ('Situação pelo plugin: ' + r.situacao) : ('Sem eventos: ' + (r.erro || ''))));
            if (r.simulado) { corpo.appendChild(el('div', 'small', 'Resposta do simulador (PLUGIN_POSTALPLUS_SIMULADO ativo), não da API real.')); }
            alerta.appendChild(corpo);
            caixa.appendChild(alerta);
            if (Array.isArray(r.eventos) && r.eventos.length) {
                var tabela = el('table', 'table table-sm table-vcenter mb-2');
                var thead = el('thead');
                var tr = el('tr');
                ['Data', 'Código', 'Tipo', 'Descrição', 'Local', 'Classificação'].forEach(function (t) { tr.appendChild(el('th', '', t)); });
                thead.appendChild(tr);
                tabela.appendChild(thead);
                var tbody = el('tbody');
                r.eventos.forEach(function (e) {
                    var linha = el('tr');
                    linha.appendChild(el('td', 'text-nowrap', formatarData(e.data)));
                    linha.appendChild(el('td', 'font-monospace', e.codigo || ''));
                    linha.appendChild(el('td', 'font-monospace', e.tipo || ''));
                    linha.appendChild(el('td', '', e.descricao || ''));
                    linha.appendChild(el('td', 'small', e.local || ''));
                    linha.appendChild(el('td', '', e.rotulo + ' (' + e.situacao + (e.critico ? ', crítico: ' + e.critico : '') + ')'));
                    tbody.appendChild(linha);
                });
                tabela.appendChild(tbody);
                var wrap = el('div', 'table-responsive');
                wrap.appendChild(tabela);
                caixa.appendChild(wrap);
            }
            if (r.bruto) {
                var det = el('details');
                det.appendChild(el('summary', '', 'Resposta crua da API (objetos[0]) — copie e cole no chat para ajustar o mapeamento'));
                var pre = el('pre', 'pp-diag-bruto', JSON.stringify(r.bruto, null, 2));
                det.appendChild(pre);
                caixa.appendChild(det);
            }
        }

        function testar() {
            var codigo = campo.value.replace(/\s+/g, '').toUpperCase();
            campo.value = codigo;
            if (!/^[A-Z]{2}\d{9}[A-Z]{2}$/.test(codigo)) { mostrar({ ok: false, erro: 'Código inválido: 2 letras + 9 dígitos + 2 letras.' }); return; }
            botao.disabled = true;
            var original = botao.textContent;
            botao.textContent = 'Consultando…';
            var corpo = new URLSearchParams();
            corpo.append('codigo', codigo);
            fetch(botao.getAttribute('data-url'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-Glpi-Csrf-Token': csrf(),
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'Accept': 'application/json'
                },
                body: corpo.toString()
            })
                .then(function (resp) { return resp.text().then(function (t) { try { return JSON.parse(t); } catch (e) { return { ok: false, erro: 'Resposta inválida do servidor (HTTP ' + resp.status + ').' }; } }); })
                .then(function (r) { guardarCsrf(r && r.csrf); mostrar(r || { ok: false, erro: 'Resposta vazia.' }); })
                .catch(function () { mostrar({ ok: false, erro: 'Não foi possível falar com o GLPI.' }); })
                .then(function () { botao.disabled = false; botao.textContent = original; });
        }

        botao.addEventListener('click', testar);
        campo.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); testar(); } });
    }

    function iniciar() {
        diagnostico();
        var botao = document.getElementById('pp-testar');
        var caixa = document.getElementById('pp-resultado-teste');
        var form  = document.getElementById('pp-form-config');
        if (!botao || !caixa) { return; }

        var sujo = false;
        if (form) {
            form.addEventListener('input', function () { sujo = true; });
            form.addEventListener('change', function () { sujo = true; });
        }

        botao.addEventListener('click', function () {
            if (sujo && !window.confirm('Há alterações não salvas. O teste usa as credenciais JÁ SALVAS. Testar mesmo assim?')) {
                return;
            }
            botao.disabled = true;
            var original = botao.innerHTML;
            botao.textContent = 'Testando…';

            fetch(botao.getAttribute('data-url'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-Glpi-Csrf-Token': csrf(),
                    'Accept': 'application/json'
                }
            })
                .then(function (resp) {
                    return resp.text().then(function (txt) {
                        try { return JSON.parse(txt); } catch (e) { return { ok: false, erro: 'Resposta inválida do servidor (HTTP ' + resp.status + ').' }; }
                    });
                })
                .then(function (r) {
                    if (!r || typeof r !== 'object') { r = { ok: false, erro: 'Resposta vazia.' }; }
                    guardarCsrf(r.csrf);
                    renderizar(caixa, r);
                })
                .catch(function () {
                    renderizar(caixa, { ok: false, erro: 'Não foi possível falar com o GLPI.' });
                })
                .then(function () {
                    botao.disabled = false;
                    botao.innerHTML = original;
                });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }

    window.PostalplusConfig = { renderizar: renderizar };
})();
