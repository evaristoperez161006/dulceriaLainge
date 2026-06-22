<?php
require_once '../config.php';
require_once '../includes/auth_check.php';
checkRol('empleado');
$conn = getConexion();
$empId = $_SESSION['usuario_id'];

$msg = '';
$msgTipo = '';
$venta = null;

// ---- Buscar venta por folio ----
$folioBuscado = trim($_GET['folio'] ?? '');
if ($folioBuscado !== '') {
    $stmt = $conn->prepare("SELECT v.*, u.nombre AS empleado_nombre FROM ventas v JOIN usuarios u ON v.empleado_id=u.id WHERE v.folio = ?");
    $stmt->bind_param('s', $folioBuscado);
    $stmt->execute();
    $venta = $stmt->get_result()->fetch_assoc();

    if ($venta) {
        $det = $conn->prepare("SELECT vd.*, p.nombre AS producto_nombre, p.codigo_barras,
            (SELECT COALESCE(SUM(d.cantidad),0) FROM devoluciones d WHERE d.venta_id = vd.venta_id AND d.producto_id = vd.producto_id) AS ya_devuelto
            FROM venta_detalle vd JOIN productos p ON vd.producto_id = p.id WHERE vd.venta_id = ?");
        $det->bind_param('i', $venta['id']);
        $det->execute();
        $venta['items'] = $det->get_result();
    } else {
        $msg = 'No se encontró ninguna venta con ese folio.';
        $msgTipo = 'error';
    }
}

// ---- Procesar devolución ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'devolver') {
    $ventaId = (int) $_POST['venta_id'];
    $productoId = (int) $_POST['producto_id'];
    $cantidad = (int) $_POST['cantidad'];
    $motivo = trim($_POST['motivo'] ?? '');
    $folioRedirect = $_POST['folio'] ?? '';

    if ($cantidad <= 0 || $motivo === '') {
        $msg = 'Indica una cantidad válida y el motivo de la devolución.';
        $msgTipo = 'error';
    } else {
        $conn->begin_transaction();
        try {
            // Verificar cantidad vendida vs ya devuelta
            $check = $conn->prepare("SELECT cantidad, precio_unitario FROM venta_detalle WHERE venta_id=? AND producto_id=?");
            $check->bind_param('ii', $ventaId, $productoId);
            $check->execute();
            $detalle = $check->get_result()->fetch_assoc();
            if (!$detalle)
                throw new Exception('Producto no encontrado en esta venta.');

            $yaDevuelto = $conn->query("SELECT COALESCE(SUM(cantidad),0) c FROM devoluciones WHERE venta_id=$ventaId AND producto_id=$productoId")->fetch_assoc()['c'];
            $disponibleParaDevolver = $detalle['cantidad'] - $yaDevuelto;

            if ($cantidad > $disponibleParaDevolver) {
                throw new Exception("Solo puedes devolver hasta $disponibleParaDevolver unidades de este producto.");
            }

            $montoDevuelto = $detalle['precio_unitario'] * $cantidad;

            $ins = $conn->prepare("INSERT INTO devoluciones (venta_id, producto_id, empleado_id, cantidad, motivo, monto_devuelto) VALUES (?,?,?,?,?,?)");
            $ins->bind_param('iiiisd', $ventaId, $productoId, $empId, $cantidad, $motivo, $montoDevuelto);
            $ins->execute();

            // Regresar el producto al inventario
            $updStock = $conn->prepare("UPDATE productos SET stock = stock + ? WHERE id = ?");
            $updStock->bind_param('ii', $cantidad, $productoId);
            $updStock->execute();

            // Marcar la venta con estado "con_devolucion"
            $updVenta = $conn->prepare("UPDATE ventas SET estado = 'con_devolucion' WHERE id = ?");
            $updVenta->bind_param('i', $ventaId);
            $updVenta->execute();

            $conn->commit();
            $msg = "Devolución registrada correctamente. Monto devuelto: $" . number_format($montoDevuelto, 2);
            $msgTipo = 'ok';
        } catch (Exception $e) {
            $conn->rollback();
            $msg = 'Error: ' . $e->getMessage();
            $msgTipo = 'error';
        }
    }
    // Recargar la venta para mostrar el estado actualizado
    header('Location: devoluciones.php?folio=' . urlencode($folioRedirect) . '&msg=' . urlencode($msg) . '&tipo=' . $msgTipo);
    exit;
}

if (isset($_GET['msg'])) {
    $msg = $_GET['msg'];
    $msgTipo = $_GET['tipo'] ?? '';
}

// Historial de devoluciones recientes hechas por este empleado
$historial = $conn->prepare("SELECT d.*, p.nombre AS producto_nombre, v.folio FROM devoluciones d
    JOIN productos p ON d.producto_id=p.id JOIN ventas v ON d.venta_id=v.id
    WHERE d.empleado_id=? ORDER BY d.fecha DESC LIMIT 20");
$historial->bind_param('i', $empId);
$historial->execute();
$historialRes = $historial->get_result();

$tituloPagina = 'Devoluciones';
$menu = [
    ['url' => 'dashboard.php', 'label' => 'Dashboard', 'icon' => '📊'],
    ['url' => 'inventario.php', 'label' => 'Ingresar Inventario', 'icon' => '📦'],
    ['url' => 'venta.php', 'label' => 'Punto de Venta', 'icon' => '🛒'],
    ['url' => 'devoluciones.php', 'label' => 'Devoluciones', 'icon' => '↩️'],
];
require_once '../includes/layout_top.php';
?>

<?php if ($msg): ?>
    <div class="alerta <?php echo $msgTipo === 'ok' ? 'alerta-ok' : 'alerta-error'; ?>">
        <?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>

<div class="card">
    <h2>↩️ Buscar venta para devolución</h2>
    <form method="get" style="margin-top:10px;display:flex;gap:10px;">
        <input type="text" name="folio" placeholder="Ingresa el folio del ticket (ej: TCK-20260621-abc12)"
            value="<?php echo htmlspecialchars($folioBuscado); ?>">
        <button class="btn btn-rosa">Buscar</button>
    </form>
</div>

<?php if ($venta): ?>
    <div class="card">
        <h3>Ticket <?php echo htmlspecialchars($venta['folio']); ?></h3>
        <p><strong>Atendido por:</strong> <?php echo htmlspecialchars($venta['empleado_nombre']); ?> &nbsp;
            <strong>Fecha:</strong> <?php echo date('d/m/Y H:i', strtotime($venta['fecha'])); ?></p>
        <table style="margin-top:10px;">
            <tr>
                <th>Producto</th>
                <th>Cant. vendida</th>
                <th>Ya devuelto</th>
                <th>Disponible</th>
                <th>Devolver</th>
            </tr>
            <?php while ($item = $venta['items']->fetch_assoc()):
                $disponible = $item['cantidad'] - $item['ya_devuelto']; ?>
                <tr>
                    <td><?php echo htmlspecialchars($item['producto_nombre']); ?></td>
                    <td><?php echo $item['cantidad']; ?></td>
                    <td><?php echo $item['ya_devuelto']; ?></td>
                    <td><?php echo $disponible; ?></td>
                    <td>
                        <?php if ($disponible > 0): ?>
                            <form method="post" style="display:flex;gap:6px;align-items:center;">
                                <input type="hidden" name="accion" value="devolver">
                                <input type="hidden" name="venta_id" value="<?php echo $venta['id']; ?>">
                                <input type="hidden" name="producto_id" value="<?php echo $item['producto_id']; ?>">
                                <input type="hidden" name="folio" value="<?php echo htmlspecialchars($venta['folio']); ?>">
                                <input type="number" name="cantidad" min="1" max="<?php echo $disponible; ?>" value="1"
                                    style="width:70px;">
                                <input type="text" name="motivo" placeholder="Motivo" required style="width:140px;">
                                <button class="btn btn-rojo">Devolver</button>
                            </form>
                        <?php else: ?>
                            <span class="badge badge-inactivo">Sin disponible</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; ?>
        </table>
    </div>
<?php endif; ?>

<div class="card">
    <h3>📋 Historial reciente de devoluciones</h3>
    <table>
        <tr>
            <th>Folio</th>
            <th>Producto</th>
            <th>Cantidad</th>
            <th>Motivo</th>
            <th>Monto</th>
            <th>Fecha</th>
        </tr>
        <?php while ($h = $historialRes->fetch_assoc()): ?>
            <tr>
                <td><?php echo htmlspecialchars($h['folio']); ?></td>
                <td><?php echo htmlspecialchars($h['producto_nombre']); ?></td>
                <td><?php echo $h['cantidad']; ?></td>
                <td><?php echo htmlspecialchars($h['motivo']); ?></td>
                <td>$<?php echo number_format($h['monto_devuelto'], 2); ?></td>
                <td><?php echo date('d/m/Y H:i', strtotime($h['fecha'])); ?></td>
            </tr>
        <?php endwhile; ?>
    </table>
</div>

<?php require_once '../includes/layout_bottom.php'; ?>