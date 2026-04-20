<?php
/**
 * Página pública de seguimiento de pedido.
 * URL: /repo-app/seguimiento.php?p=PED-XXXXXX
 */
$numero = isset($_GET['p']) ? strtoupper(trim($_GET['p'])) : '';

$pedido = null;
$items  = [];
$error  = '';

if ($numero) {
    try {
        require_once __DIR__ . '/../repo-api/config/db.php';
        $pdo = getDB();

        $stmt = $pdo->prepare("
            SELECT p.id, p.numero, p.cliente, p.telefono, p.direccion, p.total, p.estado, p.created_at,
                   pi.nombre AS item_nombre, pi.cantidad, pi.precio AS precio_unitario
            FROM pedidos p
            LEFT JOIN pedido_items pi ON pi.pedido_id = p.id
            WHERE p.numero = ?
            ORDER BY pi.id ASC
        ");
        $stmt->execute([$numero]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows) {
            $pedido = [
                'id'        => $rows[0]['id'],
                'numero'    => $rows[0]['numero'],
                'cliente'   => $rows[0]['cliente'],
                'telefono'  => $rows[0]['telefono'],
                'direccion' => $rows[0]['direccion'],
                'total'     => $rows[0]['total'],
                'estado'    => $rows[0]['estado'],
                'fecha'     => $rows[0]['created_at'],
            ];
            foreach ($rows as $r) {
                if ($r['item_nombre']) {
                    $items[] = [
                        'nombre'   => $r['item_nombre'],
                        'cantidad' => $r['cantidad'],
                        'precio'   => $r['precio_unitario'],
                    ];
                }
            }
        } else {
            $error = 'No encontramos ningún pedido con ese número.';
        }
    } catch (Exception $e) {
        $error = 'No se pudo consultar el pedido. Intentá más tarde.';
    }
} else {
    $error = 'Número de pedido no especificado.';
}

$estados = [
    'pendiente'  => ['label' => 'Recibido',    'color' => '#f59e0b', 'emoji' => '⏳'],
    'confirmado' => ['label' => 'Confirmado',  'color' => '#3b82f6', 'emoji' => '✅'],
    'preparando' => ['label' => 'Preparando',  'color' => '#8b5cf6', 'emoji' => '👨‍🍳'],
    'enviado'    => ['label' => 'En camino',   'color' => '#06b6d4', 'emoji' => '🚚'],
    'entregado'  => ['label' => 'Entregado',   'color' => '#22c55e', 'emoji' => '🎉'],
    'cancelado'  => ['label' => 'Cancelado',   'color' => '#ef4444', 'emoji' => '❌'],
];
$est = $pedido ? ($estados[$pedido['estado']] ?? ['label' => ucfirst($pedido['estado']), 'color' => '#64748b', 'emoji' => '📦']) : null;

$pasos = ['pendiente', 'confirmado', 'preparando', 'enviado', 'entregado'];
$pasoActual = $pedido ? array_search($pedido['estado'], $pasos) : -1;
?><!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Seguimiento de pedido<?= $pedido ? ' ' . htmlspecialchars($pedido['numero']) : '' ?></title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f1f5f9; min-height: 100vh; padding: 24px 16px 40px; }
    .card { background: #fff; border-radius: 16px; box-shadow: 0 2px 12px rgba(0,0,0,.08); max-width: 480px; margin: 0 auto; overflow: hidden; }
    .card-header { padding: 24px 24px 20px; border-bottom: 1px solid #e2e8f0; }
    .brand { font-size: .82rem; font-weight: 600; color: #94a3b8; letter-spacing: .05em; text-transform: uppercase; margin-bottom: 6px; }
    .ped-num { font-size: 1.4rem; font-weight: 800; color: #1e293b; }
    .ped-fecha { font-size: .82rem; color: #94a3b8; margin-top: 2px; }
    .estado-badge { display: inline-flex; align-items: center; gap: 6px; margin-top: 14px; padding: 8px 16px; border-radius: 50px; font-weight: 700; font-size: .9rem; }
    .progress { padding: 20px 24px; border-bottom: 1px solid #e2e8f0; }
    .progress-title { font-size: .75rem; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 14px; }
    .steps { display: flex; align-items: center; gap: 0; }
    .step { flex: 1; display: flex; flex-direction: column; align-items: center; position: relative; }
    .step-dot { width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; z-index: 1; }
    .step-dot.done  { background: #22c55e; color: #fff; }
    .step-dot.active { background: #f59e0b; color: #fff; box-shadow: 0 0 0 4px #fef3c7; }
    .step-dot.pending { background: #e2e8f0; color: #94a3b8; }
    .step-label { font-size: .65rem; color: #64748b; margin-top: 6px; text-align: center; font-weight: 500; }
    .step-line { position: absolute; top: 14px; left: 50%; width: 100%; height: 2px; z-index: 0; }
    .step-line.done { background: #22c55e; }
    .step-line.pending { background: #e2e8f0; }
    .step:last-child .step-line { display: none; }
    .section { padding: 20px 24px; border-bottom: 1px solid #e2e8f0; }
    .section:last-child { border-bottom: none; }
    .section-title { font-size: .75rem; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 12px; }
    .info-row { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 8px; font-size: .9rem; color: #334155; }
    .info-icon { font-size: 1rem; flex-shrink: 0; margin-top: 1px; }
    .item-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: .9rem; }
    .item-row:last-child { border-bottom: none; }
    .item-nombre { color: #334155; font-weight: 500; }
    .item-cant { color: #94a3b8; font-size: .8rem; }
    .item-precio { font-weight: 600; color: #1e293b; }
    .total-row { display: flex; justify-content: space-between; padding-top: 12px; font-weight: 800; font-size: 1.1rem; color: #1e293b; }
    .error-card { max-width: 480px; margin: 0 auto; text-align: center; padding: 48px 24px; }
    .error-icon { font-size: 3rem; margin-bottom: 16px; }
    .error-title { font-size: 1.2rem; font-weight: 700; color: #1e293b; margin-bottom: 8px; }
    .error-msg { color: #64748b; font-size: .9rem; }
    .back-btn { display: inline-block; margin-top: 24px; padding: 12px 28px; background: #f97316; color: #fff; border-radius: 50px; font-weight: 700; font-size: .95rem; text-decoration: none; }
  </style>
</head>
<body>

<?php if ($error): ?>
  <div class="error-card">
    <div class="error-icon">📦</div>
    <div class="error-title">Pedido no encontrado</div>
    <div class="error-msg"><?= htmlspecialchars($error) ?></div>
    <a href="index.php" class="back-btn">Volver a la tienda</a>
  </div>
<?php else: ?>
  <div class="card">

    <!-- Header -->
    <div class="card-header">
      <div class="brand">🛒 Repo Online</div>
      <div class="ped-num"><?= htmlspecialchars($pedido['numero']) ?></div>
      <div class="ped-fecha"><?= date('d/m/Y H:i', strtotime($pedido['fecha'])) ?></div>
      <div class="estado-badge" style="background:<?= $est['color'] ?>22;color:<?= $est['color'] ?>">
        <?= $est['emoji'] ?> <?= $est['label'] ?>
      </div>
    </div>

    <!-- Progress bar -->
    <?php if ($pedido['estado'] !== 'cancelado'): ?>
    <div class="progress">
      <div class="progress-title">Estado del pedido</div>
      <div class="steps">
        <?php
        $stepLabels = ['Recibido', 'Confirmado', 'Preparando', 'En camino', 'Entregado'];
        foreach ($pasos as $i => $paso):
          $isDone   = $pasoActual > $i;
          $isActive = $pasoActual === $i;
          $cls = $isDone ? 'done' : ($isActive ? 'active' : 'pending');
          $lineCls = $isDone ? 'done' : 'pending';
        ?>
        <div class="step">
          <div class="step-dot <?= $cls ?>"><?= $isDone ? '✓' : ($i + 1) ?></div>
          <div class="step-label"><?= $stepLabels[$i] ?></div>
          <div class="step-line <?= $lineCls ?>"></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Datos del cliente -->
    <div class="section">
      <div class="section-title">Datos del pedido</div>
      <div class="info-row"><span class="info-icon">👤</span> <?= htmlspecialchars($pedido['cliente']) ?></div>
      <div class="info-row"><span class="info-icon">📞</span> <?= htmlspecialchars($pedido['telefono']) ?></div>
      <?php if ($pedido['direccion']): ?>
      <div class="info-row"><span class="info-icon">📍</span> <?= htmlspecialchars($pedido['direccion']) ?></div>
      <?php endif; ?>
    </div>

    <!-- Items -->
    <?php if ($items): ?>
    <div class="section">
      <div class="section-title">Productos</div>
      <?php foreach ($items as $item): ?>
      <div class="item-row">
        <div>
          <div class="item-nombre"><?= htmlspecialchars($item['nombre']) ?></div>
          <div class="item-cant">× <?= $item['cantidad'] ?></div>
        </div>
        <div class="item-precio">$<?= number_format($item['precio'] * $item['cantidad'], 0, ',', '.') ?></div>
      </div>
      <?php endforeach; ?>
      <div class="total-row">
        <span>Total</span>
        <span>$<?= number_format($pedido['total'], 0, ',', '.') ?></span>
      </div>
    </div>
    <?php endif; ?>

    <!-- Volver -->
    <div class="section" style="text-align:center">
      <a href="index.php" class="back-btn">Seguir comprando</a>
    </div>

  </div>
<?php endif; ?>

</body>
</html>
