<?php
// milkminder_iofc.php
// Interfaccia pulita, senza dati esempio, con calcoli corretti e trasparenti.
// Note sui calcoli:
// - Prezzo €/ton significa €/1000 kg (as-fed). Costo = (kg as-fed / 1000) * €/ton.
// - Sostanza secca (SS) è un fattore fra 0 e 1. Kg SS = kg as-fed * SS%.
// - Latte prodotto totale = latte venduto + latte non consegnato.
// - Prezzo €/L (venduto) = Importo fattura / Latte venduto.
// - L/kg SS = Latte prodotto totale / Totale kg SS (mese).
// - Concentrati: somma dei soli item con Tipo = "concentrato".
// - €/ton concentrati (ponderato) = (Costo totale concentrati) / (Tonnellate concentrati).
// - IOFC mese = Valore latte mese - Costo alimenti mese.
// - IOFC/capo/mese = IOFC mese / Vacche in latte a fine mese.
// - IOFC/capo/giorno = IOFC mese / Vacche in latte / Giorni.
// - IOFC/L = IOFC mese / Latte venduto.
// - Litri/capo/giorno = Latte venduto / Vacche in latte / Giorni.
// - Litri/capo/mese = Latte venduto / Vacche in latte.

// Helpers
function fnum($v, $dec = 2) {
    if (!is_finite($v)) return '';
    return number_format($v, $dec, ',', '.');
}
function post($k, $default = '') { return isset($_POST[$k]) ? $_POST[$k] : $default; }

// Config: numero iniziale di righe razione
$default_rows = 10;

// Se hai inviato il form, calcola
$results = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Inputs latte/mandria
    $days         = floatval(post('days', '0'));
    $milk_sold    = floatval(post('milk_sold', '0'));          // litri
    $milk_not     = floatval(post('milk_not', '0'));           // litri
    $invoice_eur  = floatval(post('invoice_eur', '0'));        // €
    $cows_in_milk = floatval(post('cows_in_milk', '0'));       // vacche in latte (fine mese)

    // Righe razione
    $rows = intval(post('rows', $default_rows));
    $feed_items = [];
    for ($i = 0; $i < $rows; $i++) {
        $type   = trim(post("type_$i", ''));                 // concentrato | foraggio | altro
        $kgphd  = floatval(post("kgphd_$i", '0'));           // kg per capo per giorno (as-fed)
        $priceT = floatval(post("priceT_$i", '0'));          // €/ton
        $dm     = trim(post("dm_$i", ''));                   // SS %, accetta 0.88 o 88
        $dmv    = 0.0;
        if ($dm !== '') {
            $dmf = floatval($dm);
            // Accetta sia 0.88 sia 88 (%). Se > 1, interpreta come percentuale e converte.
            $dmv = ($dmf > 1.0) ? ($dmf / 100.0) : $dmf;
        }
        $heads  = floatval(post("heads_$i", '0'));           // n° capi in razione

        // Salta righe completamente vuote
        if ($type === '' && $kgphd == 0 && $priceT == 0 && $dmv == 0 && $heads == 0) {
            continue;
        }

        $feed_items[] = [
            'type'    => strtolower($type),
            'kgphd'   => $kgphd,
            'priceT'  => $priceT,
            'dm'      => $dmv,
            'heads'   => $heads,
        ];
    }

    // Calcoli latte
    $milk_total = $milk_sold + $milk_not;                   // L
    $price_per_L_sold = ($milk_sold > 0) ? ($invoice_eur / $milk_sold) : 0.0;
    $price_per_L_produced = ($milk_total > 0) ? ($invoice_eur / $milk_total) : 0.0;

    // Calcoli razione / alimenti
    $daily_asfed_total = 0.0;   // kg/giorno (mandria)
    $daily_dm_total    = 0.0;   // kg/giorno (mandria, SS)
    $monthly_asfed_total = 0.0; // kg/mese (mandria)
    $monthly_dm_total    = 0.0; // kg/mese (mandria, SS)
    $monthly_cost_total  = 0.0; // € mese

    // Concentrati
    $conc_monthly_kg = 0.0;
    $conc_monthly_cost = 0.0;

    // Per-tabella dettagli voce
    $item_details = [];

    foreach ($feed_items as $it) {
        $type   = $it['type'];
        $kgphd  = $it['kgphd'];
        $priceT = $it['priceT'];
        $dmv    = $it['dm'];
        $heads  = $it['heads'];

        // kg/giorno mandria (as-fed)
        $daily_asfed = $kgphd * $heads;
        // kg/giorno mandria (SS)
        $daily_dm    = $daily_asfed * $dmv;
        // kg/mese
        $monthly_asfed = $daily_asfed * $days;
        $monthly_dm    = $daily_dm * $days;
        // costo mese: €/ton * tonnellate mese (as-fed)
        $monthly_cost  = ($monthly_asfed / 1000.0) * $priceT;

        $daily_asfed_total   += $daily_asfed;
        $daily_dm_total      += $daily_dm;
        $monthly_asfed_total += $monthly_asfed;
        $monthly_dm_total    += $monthly_dm;
        $monthly_cost_total  += $monthly_cost;

        $is_conc = ($type === 'concentrato' || $type === 'concentrati');
        if ($is_conc) {
            $conc_monthly_kg   += $monthly_asfed;
            $conc_monthly_cost += $monthly_cost;
        }

        $item_details[] = [
            'type'   => $type,
            'heads'  => $heads,
            'kgphd'  => $kgphd,
            'dm'     => $dmv,
            'priceT' => $priceT,
            'daily_asfed'   => $daily_asfed,
            'daily_dm'      => $daily_dm,
            'monthly_asfed' => $monthly_asfed,
            'monthly_dm'    => $monthly_dm,
            'monthly_cost'  => $monthly_cost,
        ];
    }

    // Metriche concentrati
    $conc_monthly_ton = $conc_monthly_kg / 1000.0; // t/mese
    $conc_eur_per_ton = ($conc_monthly_ton > 0) ? ($conc_monthly_cost / $conc_monthly_ton) : 0.0;

    // L/kg SS
    $L_per_kgSS = ($monthly_dm_total > 0) ? ($milk_total / $monthly_dm_total) : 0.0;

    // €/L (venduto)
    $eur_per_L = $price_per_L_sold;

    // IOFC
    $iofc_month = $invoice_eur - $monthly_cost_total;
    $iofc_per_cow_month = ($cows_in_milk > 0) ? ($iofc_month / $cows_in_milk) : 0.0;
    $iofc_per_cow_day   = ($cows_in_milk > 0 && $days > 0) ? ($iofc_month / $cows_in_milk / $days) : 0.0;
    $iofc_per_L         = ($milk_sold > 0) ? ($iofc_month / $milk_sold) : 0.0;

    // Litri/capo
    $L_per_cow_day  = ($cows_in_milk > 0 && $days > 0) ? ($milk_sold / $cows_in_milk / $days) : 0.0;
    $L_per_cow_month= ($cows_in_milk > 0) ? ($milk_sold / $cows_in_milk) : 0.0;

    // Vacche totali a fine mese (se fornito in input separato, altrimenti usa vacche in latte come proxy)
    $cows_end_month = floatval(post('cows_end_month', '0'));
    // Se non fornito, lascia 0 per evitare supposizioni.

    // Raccogli risultati
    $results = [
        'days' => $days,
        'milk_sold' => $milk_sold,
        'milk_not'  => $milk_not,
        'milk_total'=> $milk_total,
        'invoice_eur'=> $invoice_eur,
        'cows_in_milk'=> $cows_in_milk,
        'cows_end_month'=> $cows_end_month,

        'price_per_L_sold'     => $price_per_L_sold,
        'price_per_L_produced' => $price_per_L_produced,

        'daily_asfed_total'    => $daily_asfed_total,
        'daily_dm_total'       => $daily_dm_total,
        'monthly_asfed_total'  => $monthly_asfed_total,
        'monthly_dm_total'     => $monthly_dm_total,
        'monthly_cost_total'   => $monthly_cost_total,

        'conc_monthly_kg'      => $conc_monthly_kg,
        'conc_monthly_ton'     => $conc_monthly_ton,
        'conc_monthly_cost'    => $conc_monthly_cost,
        'conc_eur_per_ton'     => $conc_eur_per_ton,

        'L_per_kgSS'           => $L_per_kgSS,
        'eur_per_L'            => $eur_per_L,

        'iofc_month'           => $iofc_month,
        'iofc_per_cow_month'   => $iofc_per_cow_month,
        'iofc_per_cow_day'     => $iofc_per_cow_day,
        'iofc_per_L'           => $iofc_per_L,

        'L_per_cow_day'        => $L_per_cow_day,
        'L_per_cow_month'      => $L_per_cow_month,

        'item_details'         => $item_details,
    ];
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Milkminder IOFC & alimenti</title>
<style>
    :root {
        --bg: #0f0f12;
        --panel: #1a1d22;
        --accent: #2f7cf0;
        --text: #eaeef5;
        --muted: #a9b1c1;
        --good: #2ecc71;
        --warn: #f39c12;
        --bad:  #e74c3c;
        --grid: #2a2e35;
    }
    body {
        margin: 0; padding: 0; font-family: system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, 'Helvetica Neue', Arial;
        background: radial-gradient(1200px 700px at 10% 10%, #13151a, var(--bg));
        color: var(--text);
    }
    header { padding: 24px; border-bottom: 1px solid var(--grid); }
    h1 { margin: 0; font-size: 22px; font-weight: 700; letter-spacing: 0.3px; }
    main { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; padding: 16px; }
    .panel {
        background: linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.0));
        border: 1px solid var(--grid); border-radius: 12px; padding: 16px;
        box-shadow: 0 10px 24px rgba(0,0,0,0.25);
    }
    .panel h2 { margin: 0 0 12px; font-size: 18px; }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    label { font-size: 13px; color: var(--muted); }
    input, select {
        width: 100%; padding: 10px 12px; border-radius: 8px; border: 1px solid var(--grid);
        background: #13161b; color: var(--text);
    }
    .row { display: grid; grid-template-columns: 140px 1fr 1fr 1fr 1fr 1fr; gap: 8px; align-items: center; }
    .row label { display: block; font-size: 12px; }
    .btnbar { display: flex; gap: 8px; justify-content: flex-end; margin-top: 12px; }
    button, .button {
        background: var(--accent); color: white; border: none; padding: 10px 14px; border-radius: 10px;
        cursor: pointer; font-weight: 600; letter-spacing: 0.2px;
    }
    .secondary { background: #2b3340; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border-bottom: 1px solid var(--grid); padding: 10px; text-align: right; }
    th { text-align: left; font-size: 13px; color: var(--muted); }
    .metric { display: grid; grid-template-columns: 2fr 1fr; border-bottom: 1px solid var(--grid); padding: 8px 0; }
    .metric span.key { color: var(--muted); font-size: 13px; }
    .metric span.val { font-weight: 700; }
    .ok { color: var(--good); } .warn { color: var(--warn); } .bad { color: var(--bad); }
    footer { padding: 12px 16px; color: var(--muted); border-top: 1px solid var(--grid); }
    @media (max-width: 1100px) { main { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<header>
    <h1>Milkminder — Calcolatore latte, alimenti, IOFC</h1>
</header>

<form method="post" novalidate>
<main>
    <section class="panel">
        <h2>Dati latte e mandria</h2>
        <div class="grid">
            <div>
                <label>Giorni nel mese</label>
                <input type="number" step="1" min="1" name="days" value="<?= htmlspecialchars(post('days', '')) ?>" placeholder="es. 31" />
            </div>
            <div>
                <label>Latte venduto (L)</label>
                <input type="number" step="0.01" min="0" name="milk_sold" value="<?= htmlspecialchars(post('milk_sold', '')) ?>" placeholder="litri da fattura" />
            </div>
            <div>
                <label>Latte non consegnato (L)</label>
                <input type="number" step="0.01" min="0" name="milk_not" value="<?= htmlspecialchars(post('milk_not', '')) ?>" placeholder="vitelli/uso interno" />
            </div>
            <div>
                <label>Importo fattura (€)</label>
                <input type="number" step="0.01" min="0" name="invoice_eur" value="<?= htmlspecialchars(post('invoice_eur', '')) ?>" placeholder="totale valore latte" />
            </div>
            <div>
                <label>Vacche in latte a fine mese (n°)</label>
                <input type="number" step="1" min="0" name="cows_in_milk" value="<?= htmlspecialchars(post('cows_in_milk', '')) ?>" placeholder="n° vacche in lattazione" />
            </div>
            <div>
                <label>Vacche totali a fine mese (n°)</label>
                <input type="number" step="1" min="0" name="cows_end_month" value="<?= htmlspecialchars(post('cows_end_month', '')) ?>" placeholder="opzionale se disponibile" />
            </div>
        </div>
    </section>

    <section class="panel">
        <h2>Razione (media giornaliera)</h2>
        <div class="grid" style="grid-template-columns: 1fr;">
            <input type="hidden" name="rows" id="rows" value="<?= intval(post('rows', $default_rows)) ?>">
            <div id="feedRows">
                <?php
                $rows_to_render = intval(post('rows', $default_rows));
                for ($i = 0; $i < $rows_to_render; $i++): ?>
                <div class="row">
                    <div>
                        <label>Tipo</label>
                        <select name="type_<?= $i ?>">
                            <?php
                            $opt = strtolower(post("type_$i", ''));
                            $types = ['', 'concentrato', 'foraggio', 'altro'];
                            foreach ($types as $t) {
                                $sel = ($opt === $t) ? 'selected' : '';
                                echo "<option value=\"$t\" $sel>" . ($t === '' ? '—' : ucfirst($t)) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div>
                        <label>Kg/capo/giorno (as-fed)</label>
                        <input type="number" step="0.001" min="0" name="kgphd_<?= $i ?>" value="<?= htmlspecialchars(post("kgphd_$i", '')) ?>" />
                    </div>
                    <div>
                        <label>Prezzo (€/ton)</label>
                        <input type="number" step="0.01" min="0" name="priceT_<?= $i ?>" value="<?= htmlspecialchars(post("priceT_$i", '')) ?>" />
                    </div>
                    <div>
                        <label>Sostanza secca (SS)</label>
                        <input type="number" step="0.01" min="0" name="dm_<?= $i ?>" value="<?= htmlspecialchars(post("dm_$i", '')) ?>" placeholder="0.88 o 88" />
                    </div>
                    <div>
                        <label>N° capi in razione</label>
                        <input type="number" step="1" min="0" name="heads_<?= $i ?>" value="<?= htmlspecialchars(post("heads_$i", '')) ?>" />
                    </div>
                    <div style="text-align:right;">
                        <label>&nbsp;</label>
                        <button type="button" class="button secondary" onclick="clearRow(<?= $i ?>)">Pulisci</button>
                    </div>
                </div>
                <?php endfor; ?>
            </div>
            <div class="btnbar">
                <button type="button" class="button secondary" onclick="addRow()">+ Aggiungi riga</button>
                <button type="button" class="button secondary" onclick="removeRow()">− Rimuovi riga</button>
                <button type="submit" class="button">Calcola</button>
            </div>
        </div>
    </section>

    <?php if ($results): ?>
    <section class="panel">
        <h2>Risultati principali</h2>
        <div class="metric">
            <span class="key">Totale latte prodotto (L)</span>
            <span class="val"><?= fnum($results['milk_total'], 2) ?></span>
        </div>
        <div class="metric">
            <span class="key">Totale valore latte (€)</span>
            <span class="val"><?= fnum($results['invoice_eur'], 2) ?></span>
        </div>
        <div class="metric">
            <span class="key">Prezzo €/L (su venduto)</span>
            <span class="val"><?= fnum($results['price_per_L_sold'], 4) ?></span>
        </div>
        <div class="metric">
            <span class="key">Prezzo €/L (su prodotto)</span>
            <span class="val"><?= fnum($results['price_per_L_produced'], 4) ?></span>
        </div>
        <div class="metric">
            <span class="key">Vacche totali a fine mese (n°)</span>
            <span class="val"><?= fnum($results['cows_end_month'], 0) ?></span>
        </div>
        <div class="metric">
            <span class="key">Vacche in latte a fine mese (n°)</span>
            <span class="val"><?= fnum($results['cows_in_milk'], 0) ?></span>
        </div>
        <div class="metric">
            <span class="key">Totale kg SS (mese)</span>
            <span class="val"><?= fnum($results['monthly_dm_total'], 2) ?></span>
        </div>
        <div class="metric">
            <span class="key">Litri / kg SS</span>
            <span class="val"><?= fnum($results['L_per_kgSS'], 4) ?></span>
        </div>
        <div class="metric">
            <span class="key">Totale € alimenti (mese)</span>
            <span class="val"><?= fnum($results['monthly_cost_total'], 2) ?></span>
        </div>
        <div class="metric">
            <span class="key">Totale concentrati (t/mese)</span>
            <span class="val"><?= fnum($results['conc_monthly_ton'], 3) ?></span>
        </div>
        <div class="metric">
            <span class="key">€/ton (concentrati, ponderato)</span>
            <span class="val"><?= fnum($results['conc_eur_per_ton'], 2) ?></span>
        </div>
        <div class="metric">
            <span class="key">IOFC mandria / mese (€)</span>
            <span class="val <?= ($results['iofc_month']>=0?'ok':'bad') ?>"><?= fnum($results['iofc_month'], 2) ?></span>
        </div>
        <div class="metric">
            <span class="key">IOFC / capo / mese (€)</span>
            <span class="val"><?= fnum($results['iofc_per_cow_month'], 2) ?></span>
        </div>
        <div class="metric">
            <span class="key">IOFC / capo / giorno (€)</span>
            <span class="val"><?= fnum($results['iofc_per_cow_day'], 3) ?></span>
        </div>
        <div class="metric">
            <span class="key">IOFC / litro (€)</span>
            <span class="val"><?= fnum($results['iofc_per_L'], 4) ?></span>
        </div>
        <div class="metric">
            <span class="key">Litri di latte / capo / giorno</span>
            <span class="val"><?= fnum($results['L_per_cow_day'], 3) ?></span>
        </div>
        <div class="metric">
            <span class="key">Litri di latte / capo / mese</span>
            <span class="val"><?= fnum($results['L_per_cow_month'], 2) ?></span>
        </div>
    </section>

    <section class="panel">
        <h2>Dettaglio alimenti (calcoli per voce)</h2>
        <table>
            <thead>
                <tr>
                    <th>Tipo</th>
                    <th style="text-align:right;">N° capi</th>
                    <th style="text-align:right;">Kg/capo/giorno</th>
                    <th style="text-align:right;">SS</th>
                    <th style="text-align:right;">€/ton</th>
                    <th style="text-align:right;">Kg/giorno mandria</th>
                    <th style="text-align:right;">Kg SS/giorno</th>
                    <th style="text-align:right;">Kg/mese</th>
                    <th style="text-align:right;">Kg SS/mese</th>
                    <th style="text-align:right;">€ mese</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results['item_details'] as $d): ?>
                <tr>
                    <td style="text-align:left;"><?= htmlspecialchars(ucfirst($d['type'])) ?></td>
                    <td><?= fnum($d['heads'], 0) ?></td>
                    <td><?= fnum($d['kgphd'], 3) ?></td>
                    <td><?= fnum($d['dm'], 3) ?></td>
                    <td><?= fnum($d['priceT'], 2) ?></td>
                    <td><?= fnum($d['daily_asfed'], 2) ?></td>
                    <td><?= fnum($d['daily_dm'], 2) ?></td>
                    <td><?= fnum($d['monthly_asfed'], 2) ?></td>
                    <td><?= fnum($d['monthly_dm'], 2) ?></td>
                    <td><?= fnum($d['monthly_cost'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($results['item_details'])): ?>
                <tr><td colspan="10" style="text-align:center; color:var(--muted);">Nessun alimento inserito.</td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th style="text-align:left;">Totali</th>
                    <th></th><th></th><th></th><th></th>
                    <th><?= fnum($results['daily_asfed_total'], 2) ?></th>
                    <th><?= fnum($results['daily_dm_total'], 2) ?></th>
                    <th><?= fnum($results['monthly_asfed_total'], 2) ?></th>
                    <th><?= fnum($results['monthly_dm_total'], 2) ?></th>
                    <th><?= fnum($results['monthly_cost_total'], 2) ?></th>
                </tr>
            </tfoot>
        </table>
    </section>
    <?php endif; ?>
</main>

<footer>
    Inserisci i dati reali e premi “Calcola”. Nessun dato d’esempio viene mostrato o salvato.
</footer>
</form>

<script>
function addRow() {
    const rowsInput = document.getElementById('rows');
    let n = parseInt(rowsInput.value || '0', 10);
    rowsInput.value = (n + 1).toString();
    document.forms[0].submit();
}
function removeRow() {
    const rowsInput = document.getElementById('rows');
    let n = parseInt(rowsInput.value || '0', 10);
    rowsInput.value = Math.max(0, n - 1).toString();
    document.forms[0].submit();
}
function clearRow(i) {
    const form = document.forms[0];
    ['type_', 'kgphd_', 'priceT_', 'dm_', 'heads_'].forEach(prefix => {
        const el = form.elements[prefix + i];
        if (el) {
            if (el.tagName === 'SELECT') el.selectedIndex = 0;
            else el.value = '';
        }
    });
}
</script>
</body>
</html>
