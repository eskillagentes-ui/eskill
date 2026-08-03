<link rel="stylesheet" href="/css/pregao.css?v=1">

<div id="pregao-root" data-account-id="<?= (int)($pregaoAccountId ?? 0) ?>" data-read-only="1">
    <header class="pg-header">
        <div class="brand">
            <div class="dot"></div>
            <h1>ESKILL <span>PREGÃO</span></h1>
        </div>
        <div class="ticker-main">
            <span class="sym">ESKL11 · ÍNDICE DA CONTA</span>
            <span class="px" id="px">—</span>
            <span class="chg up" id="chg">▲ +0,00%</span>
        </div>
        <div class="spacer"></div>
        <div class="sema" id="sema">
            <div class="s" id="semaDot"></div>
            <span id="semaText">CONECTANDO…</span>
        </div>
        <div class="clock" id="clock">--:--:--</div>
        <div class="conn" id="conn" title="transporte">●</div>
    </header>

    <div class="tape">
        <div class="tape-in" id="tape"></div>
    </div>

    <div class="wrap">
        <div>
            <div class="panel">
                <div class="p-head">📈 ESKL11 — EVOLUÇÃO DA CONTA · CANDLE 1 SESSÃO ≈ 1 DIA <span class="live" id="liveBadge">AO VIVO</span></div>
                <canvas id="chart"></canvas>
                <div class="chart-foot">
                    <span>ABERTURA <b id="fOpen">—</b></span>
                    <span>MÁXIMA <b id="fHigh">—</b></span>
                    <span>MÍNIMA <b id="fLow">—</b></span>
                    <span>VENDAS HOJE <b id="fSales">—</b></span>
                </div>
            </div>

            <div class="cards">
                <div class="card" id="cVendas" data-metric="vendas_hoje">
                    <div class="lb">VENDAS HOJE</div>
                    <div class="vl" id="vVendas">—</div>
                    <div class="sb" id="sVendas">aguardando dados</div>
                </div>
                <div class="card" id="cRec" data-metric="receita_hoje">
                    <div class="lb">RECEITA HOJE</div>
                    <div class="vl" id="vRec">—</div>
                    <div class="sb">ticket médio <b id="vTicket">—</b></div>
                </div>
                <div class="card" id="cTacos" data-metric="tacos">
                    <div class="lb">TACOS</div>
                    <div class="vl" id="vTacos">—</div>
                    <div class="sb" id="sTacos">—</div>
                </div>
                <div class="card" id="cPos" data-metric="posicao_media">
                    <div class="lb">POSIÇÃO MÉDIA BUSCA</div>
                    <div class="vl" id="vPos">—</div>
                    <div class="sb" id="sPos">—</div>
                </div>
                <div class="card" id="cHealth" data-metric="health_medio">
                    <div class="lb">HEALTH MÉDIO</div>
                    <div class="vl" id="vHealth">—</div>
                    <div class="sb" id="sHealth">—</div>
                </div>
                <div class="card" id="cRep" data-metric="reputacao">
                    <div class="lb">REPUTAÇÃO</div>
                    <div class="vl" id="vRep">—</div>
                    <div class="sb" id="sRep">—</div>
                </div>
                <div class="card" id="cPerg" data-metric="perguntas_hoje">
                    <div class="lb">PERGUNTAS HOJE</div>
                    <div class="vl" id="vPerg">—</div>
                    <div class="sb">tempo médio <b id="vTmed">—</b></div>
                </div>
                <div class="card" id="cAcoes" data-metric="acoes_hora">
                    <div class="lb">AÇÕES DOS ROBÔS / H</div>
                    <div class="vl" id="vAcoes">—</div>
                    <div class="sb" id="sAcoes">somente leitura · zero escrita ML</div>
                </div>
            </div>
        </div>

        <div class="right">
            <div class="panel">
                <div class="p-head">🎞️ FITA DE OPERAÇÕES <span class="live">AO VIVO</span></div>
                <div class="pl-line"><span>P&amp;L DO DIA</span><b id="pnl">—</b></div>
                <ul id="feed"></ul>
            </div>

            <div class="panel">
                <div class="p-head">🖱️ QA PLAYWRIGHT — HERMES TESTANDO O ESKILL <span class="live" id="qaLive">STANDBY</span></div>
                <div class="qa-stage" id="stage">
                    <div id="qaMedia">
                        <div class="qa-idle" id="qaIdle">Aguardando eventos <code>qa.status</code>…</div>
                        <iframe id="qaStream" class="qa-frame" hidden title="QA live stream"></iframe>
                        <video id="qaVideo" class="qa-frame" controls playsinline hidden></video>
                    </div>
                    <div class="qa-log" id="qalog">▶ standby</div>
                </div>
            </div>
        </div>
    </div>

    <footer class="pg-foot">ESKILL PREGÃO · read-only · sem escrita no Mercado Livre · ML_WRITE_AUTOMATION intocado</footer>
</div>

<script>
    window.PREGAO_BOOT = {
        accountId: <?= (int)($pregaoAccountId ?? 0) ?>,
        snapshotUrl: '/api/pregao/snapshot',
        streamUrl: '/api/pregao/stream',
        ticketUrl: '/api/pregao/ticket',
        wsPath: '/ws/pregao'
    };
</script>
<script src="/js/pregao.js?v=1" defer></script>
