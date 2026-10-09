/* Postal+ — Adicionar objetos: validação do código e classificação do lote (nada é gravado no Bloco 1b). */
(function () {
    'use strict';

    var FORMATO = /^[A-Z]{2}\d{9}[A-Z]{2}$/;

    function existentes() {
        var el = document.getElementById('pp-codigos-existentes');
        var lista = [];
        try { lista = JSON.parse(el ? el.textContent : '[]'); } catch (e) { lista = []; }
        return Array.isArray(lista) ? lista : [];
    }

    /** Classifica uma lista de códigos: valido | invalido | cadastrado | repetido. */
    function classificar(texto, jaCadastrados) {
        var vistos = {};
        var cad = {};
        jaCadastrados.forEach(function (c) { cad[String(c).toUpperCase()] = true; });

        return String(texto || '')
            .split(/[\s,;]+/)
            .map(function (c) { return c.trim().toUpperCase(); })
            .filter(function (c) { return c !== ''; })
            .map(function (c) {
                var estado;
                if (!FORMATO.test(c)) { estado = 'invalido'; }
                else if (cad[c]) { estado = 'cadastrado'; }
                else if (vistos[c]) { estado = 'repetido'; }
                else { estado = 'valido'; }
                vistos[c] = true;
                return { codigo: c, estado: estado };
            });
    }

    var ROTULO = {
        valido: ['Válido', 'bg-green-lt'],
        invalido: ['Formato inválido', 'bg-red-lt'],
        cadastrado: ['Já cadastrado', 'bg-yellow-lt'],
        repetido: ['Repetido na lista', 'bg-yellow-lt']
    };

    function pilula(texto, classe) {
        var s = document.createElement('span');
        s.className = 'badge ' + classe;
        s.textContent = texto;
        return s;
    }

    function iniciar() {
        if (!document.querySelector('[data-pp-tela="adicionar"]')) { return; }
        var cadastrados = existentes();

        // Individual
        var campo = document.getElementById('pp-codigo');
        var status = document.getElementById('pp-codigo-status');
        if (campo && status) {
            campo.addEventListener('input', function () {
                var v = campo.value.replace(/\s+/g, '').toUpperCase();
                if (campo.value !== v) { campo.value = v; }
                status.className = 'small mt-1';
                if (!v) { status.textContent = ''; return; }
                if (!FORMATO.test(v)) {
                    status.classList.add('pp-valida-erro');
                    status.textContent = 'Formato: 2 letras + 9 dígitos + 2 letras (ex.: AA123456789BR)';
                } else if (cadastrados.indexOf(v) !== -1) {
                    status.classList.add('pp-valida-erro');
                    status.textContent = 'Já cadastrado';
                } else {
                    status.classList.add('pp-valida-ok');
                    status.textContent = 'Formato válido · pronto para consultar';
                }
            });
        }

        // Lote
        var lote = document.getElementById('pp-lote');
        var resumo = document.getElementById('pp-lote-resumo');
        var lista = document.getElementById('pp-lote-lista');
        var botao = document.getElementById('pp-importar');
        if (lote && resumo && lista) {
            lote.addEventListener('input', function () {
                var itens = classificar(lote.value, cadastrados);
                var cont = { valido: 0, invalido: 0, dup: 0 };
                lista.innerHTML = '';
                resumo.innerHTML = '';
                itens.forEach(function (i) {
                    if (i.estado === 'valido') { cont.valido++; } else if (i.estado === 'invalido') { cont.invalido++; } else { cont.dup++; }
                    var linha = document.createElement('div');
                    linha.setAttribute('data-pp-item', i.estado);
                    var cod = document.createElement('span');
                    cod.textContent = i.codigo;
                    linha.appendChild(cod);
                    linha.appendChild(pilula(ROTULO[i.estado][0], ROTULO[i.estado][1]));
                    lista.appendChild(linha);
                });
                lista.classList.toggle('d-none', itens.length === 0);
                if (itens.length) {
                    resumo.appendChild(pilula(cont.valido + ' válido(s)', 'bg-green-lt'));
                    if (cont.dup) { resumo.appendChild(pilula(cont.dup + ' já cadastrado(s) ou repetido(s)', 'bg-yellow-lt')); }
                    if (cont.invalido) { resumo.appendChild(pilula(cont.invalido + ' com formato inválido', 'bg-red-lt')); }
                }
                if (botao) { botao.textContent = 'Importar ' + cont.valido + ' objeto(s) válido(s)'; }
            });
        }
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', iniciar); } else { iniciar(); }

    window.PostalplusAdicionar = { classificar: classificar };
})();
