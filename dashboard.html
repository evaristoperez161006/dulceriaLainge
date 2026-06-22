<?php
require_once '../config.php';
require_once '../includes/auth_check.php';
checkRol('empleado');
$conn = getConexion();

$empId = $_SESSION['usuario_id'];
$misVentasHoy = $conn->prepare("SELECT COUNT(*) c, COALESCE(SUM(total),0) t FROM ventas WHERE empleado_id=? AND DATE(fecha)=CURDATE()");
$misVentasHoy->bind_param('i', $empId);
$misVentasHoy->execute();
$resumen = $misVentasHoy->get_result()->fetch_assoc();

$tituloPagina = 'Panel Empleado';
$menu = [
    ['url'=>'dashboard.php','label'=>'Dashboard','icon'=>'📊'],
    ['url'=>'inventario.php','label'=>'Ingresar Inventario','icon'=>'📦'],
    ['url'=>'venta.php','label'=>'Punto de Venta','icon'=>'🛒'],
    ['url'=>'devoluciones.php','label'=>'Devoluciones','icon'=>'↩️'],
];
require_once '../includes/layout_top.php';
?>
<div class="card">
    <h2>Bienvenido/a, <?php echo htmlspecialchars($_SESSION['nombre']); ?> 🍬</h2>
</div>
<div class="form-grid">
    <div class="card">
        <h3>🧾 Ventas realizadas hoy</h3>
        <p style="font-size:32px;font-weight:800;color:#ff4fa2;"><?php echo $resumen['c']; ?></p>
    </div>
    <div class="card">
        <h3>💰 Total vendido hoy</h3>
        <p style="font-size:32px;font-weight:800;color:#38a169;">$<?php echo number_format($resumen['t'], 2); ?></p>
    </div>
</div>
<div class="card">
    <h3>Accesos rápidos</h3>
    <div style="display:flex;gap:10px;margin-top:10px;flex-wrap:wrap;">
        <a class="btn btn-rosa" href="venta.php">🛒 Nueva venta</a>
        <a class="btn btn-amarillo" href="inventario.php">📦 Ingresar producto</a>
        <a class="btn btn-gris" href="devoluciones.php">↩️ Procesar devolución</a>
    </div>
</div>
<?php require_once '../includes/layout_bottom.php'; ?>
