<link rel="stylesheet" href="/css/pregao.css?v=6">

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
                <div class="p-head">📈 ESKL11 — EVOLUÇÃO DA CONTA · CANDLE 1 SESSÃO ≈ 1 DIA
                    <span class="factors" id="factorsBadge">— de 5 fatores ativos</span>
                    <span class="live" id="liveBadge">AO VIVO</span>
                </div>
                <div class="chart-wrap">
                    <canvas id="chart"></canvas>
                    <div class="chart-empty" id="chartEmpty" hidden>aguardando primeiro fechamento</div>
                </div>
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
                <a class="card" id="cTacos" href="/dashboard/ads" data-metric="tacos" style="text-decoration:none;color:inherit">
                    <div class="lb">TACOS</div>
                    <div class="vl" id="vTacos">n/d</div>
                    <div class="sb" id="sTacos">aguardando módulo Ads</div>
                </a>
                <div class="card" id="cPos" data-metric="visitas_7d">
                    <div class="lb">EXPOSIÇÃO (VISITAS 7D)</div>
                    <div class="vl" id="vPos">n/d</div>
                    <div class="sb" id="sPos">aguardando coletor</div>
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
                <div class="card" id="cPerg" data-metric="perguntas_7d">
                    <div class="lb">PERGUNTAS (7 DIAS)</div>
                    <div class="vl" id="vPerg">—</div>
                    <div class="sb" id="sPerg">taxa — · mediana — · — em aberto</div>
                </div>
                <div class="card" id="cAcoes" data-metric="acoes_hora">
                    <div class="lb">AÇÕES DOS ROBÔS / H</div>
                    <div class="vl" id="vAcoes">—</div>
                    <div class="sb" id="sAcoes">somente leitura · zero escrita ML</div>
                </div>
                <a class="card sn-card" id="cSentinela" href="/dashboard/sentinela" data-metric="sentinela">
                    <div class="lb">SENTINELA</div>
                    <div class="vl" id="vSentinela">—</div>
                    <div class="sb" id="sSentinela">abrindo painel de riscos…</div>
                </a>
            </div>

            <section class="panel agents-panel" id="agentsPanel" aria-labelledby="agentsTitle">
                <div class="p-head" id="agentsTitle">
                    🤖 AGENTES 24/7 — MONITORAMENTO
                    <span class="agents-summary is-waiting" id="agentsSummary" role="status" aria-live="polite">0/5 reportando</span>
                </div>
                <div class="agents-grid">
                    <article class="agent-card is-waiting" id="agentCard-sentinela">
                        <div class="agent-card-head"><b>🛡️ SENTINELA</b><span id="agentStatus-sentinela">AGUARDANDO</span></div>
                        <div class="agent-reason" id="agentReason-sentinela">no data</div>
                        <div class="agent-time" id="agentTime-sentinela">sem ciclo registrado</div>
                    </article>
                    <article class="agent-card is-waiting" id="agentCard-collector">
                        <div class="agent-card-head"><b>📡 COLETOR</b><span id="agentStatus-collector">AGUARDANDO</span></div>
                        <div class="agent-reason" id="agentReason-collector">no data</div>
                        <div class="agent-time" id="agentTime-collector">sem ciclo registrado</div>
                    </article>
                    <article class="agent-card is-waiting" id="agentCard-financeiro">
                        <div class="agent-card-head"><b>💰 FINANCEIRO</b><span id="agentStatus-financeiro">AGUARDANDO</span></div>
                        <div class="agent-reason" id="agentReason-financeiro">no data</div>
                        <div class="agent-time" id="agentTime-financeiro">sem ciclo registrado</div>
                    </article>
                    <article class="agent-card is-waiting" id="agentCard-otimizador">
                        <div class="agent-card-head"><b>📈 OTIMIZADOR</b><span id="agentStatus-otimizador">AGUARDANDO</span></div>
                        <div class="agent-reason" id="agentReason-otimizador">no data</div>
                        <div class="agent-time" id="agentTime-otimizador">sem ciclo registrado</div>
                    </article>
                    <article class="agent-card is-waiting" id="agentCard-orquestrador">
                        <div class="agent-card-head"><b>🧭 ORQUESTRADOR</b><span id="agentStatus-orquestrador">AGUARDANDO</span></div>
                        <div class="agent-reason" id="agentReason-orquestrador">no data</div>
                        <div class="agent-time" id="agentTime-orquestrador">sem ciclo registrado</div>
                    </article>
                </div>
                <div class="agents-readonly">Somente leitura · nenhuma alteração automática no Mercado Livre</div>
            </section>

            <section class="panel source-panel" id="sourcePanel" aria-labelledby="sourceTitle">
                <div class="p-head" id="sourceTitle">
                    🔎 FONTES E FRESHNESS
                    <span class="source-freshness" id="sourceFreshness" role="status">aguardando snapshot</span>
                </div>
                <div class="source-grid" id="dataSources">
                    <div class="source-empty">Carregando origem das métricas…</div>
                </div>
                <div class="source-runtime">
                    <span>Transporte <b id="sourceTransport">OFFLINE</b></span>
                    <span>Último evento <b id="sourceLastEvent">—</b></span>
                    <span>Modo <b>SOMENTE LEITURA</b></span>
                </div>
            </section>
        </div>

        <div class="right">
            <div class="panel">
                <div class="p-head">🎞️ FITA DE OPERAÇÕES <span class="live">AO VIVO</span></div>
                <div class="pl-line"><span>P&amp;L DO DIA</span><b id="pnl">—</b></div>
                <ul id="feed"></ul>
            </div>

            <div class="panel" id="openQuestionsPanel">
                <div class="p-head">❓ PERGUNTAS EM ABERTO <span class="live" id="openQCount">0</span></div>
                <ul class="open-q-list" id="openQuestions">
                    <li class="open-q-empty">Nenhuma pergunta em aberto</li>
                </ul>
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
<script src="/js/pregao-chart-layout.js?v=1"></script>
<script src="/js/pregao.js?v=37" defer></script>
