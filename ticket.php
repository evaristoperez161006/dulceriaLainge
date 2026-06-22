<?php
require_once '../config.php';
require_once '../includes/auth_check.php';
checkRol('empleado');
$conn = getConexion();

$vid = (int)($_GET['id'] ?? 0);
$cab = $conn->prepare("SELECT v.*, u.nombre AS empleado_nombre FROM ventas v JOIN usuarios u ON v.empleado_id = u.id WHERE v.id = ?");
$cab->bind_param('i', $vid);
$cab->execute();
$venta = $cab->get_result()->fetch_assoc();

if (!$venta) { die('Ticket no encontrado.'); }

$det = $conn->prepare("SELECT vd.*, p.nombre AS producto_nombre, p.codigo_barras FROM venta_detalle vd JOIN productos p ON vd.producto_id = p.id WHERE vd.venta_id = ?");
$det->bind_param('i', $vid);
$det->execute();
$items = $det->get_result();

$tituloPagina = 'Ticket de venta';
$menu = [
    ['url'=>'dashboard.php','label'=>'Dashboard','icon'=>'📊'],
    ['url'=>'inventario.php','label'=>'Ingresar Inventario','icon'=>'📦'],
    ['url'=>'venta.php','label'=>'Punto de Venta','icon'=>'🛒'],
    ['url'=>'devoluciones.php','label'=>'Devoluciones','icon'=>'↩️'],
];
require_once '../includes/layout_top.php';
?>
<div class="card">
    <h2>🧾 Ticket <?php echo htmlspecialchars($venta['folio']); ?></h2>
    <p><strong>Empleado que atendió:</strong> <?php echo htmlspecialchars($venta['empleado_nombre']); ?></p>
    <p><strong>Fecha:</strong> <?php echo date('d/m/Y H:i', strtotime($venta['fecha'])); ?></p>
    <p><strong>Método de pago:</strong> <?php echo htmlspecialchars($venta['metodo_pago']); ?></p>
    <table style="margin-top:15px;">
        <tr><th>Código</th><th>Producto</th><th>Cantidad</th><th>Precio unitario</th><th>Subtotal</th></tr>
        <?php while ($item = $items->fetch_assoc()): ?>
        <tr>
            <td><?php echo htmlspecialchars($item['codigo_barras']); ?></td>
            <td><?php echo htmlspecialchars($item['producto_nombre']); ?></td>
            <td><?php echo $item['cantidad']; ?></td>
            <td>$<?php echo number_format($item['precio_unitario'], 2); ?></td>
            <td>$<?php echo number_format($item['subtotal'], 2); ?></td>
        </tr>
        <?php endwhile; ?>
    </table>
    <h3 style="margin-top:15px;text-align:right;">Total: $<?php echo number_format($venta['total'], 2); ?></h3>
    <a class="btn btn-rosa" href="venta.php" style="margin-top:10px;">← Nueva venta</a>
</div>
<?php require_once '../includes/layout_bottom.php'; ?>
