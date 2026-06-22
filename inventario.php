<?php
require_once '../config.php';
require_once '../includes/auth_check.php';
checkRol('empleado');
$conn = getConexion();
$empId = $_SESSION['usuario_id'];

$msg = '';
$msgTipo = '';

// ---- Registrar nuevo producto ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'agregar') {
    $codigo = trim($_POST['codigo_barras'] ?? '');
    $nombre = trim($_POST['nombre'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $categoria = trim($_POST['categoria'] ?? '');
    $precioCompra = (float)($_POST['precio_compra'] ?? 0);
    $precioVenta = (float)($_POST['precio_venta'] ?? 0);
    $stock = (int)($_POST['stock'] ?? 0);
    $stockMinimo = (int)($_POST['stock_minimo'] ?? 5);
    $proveedor = trim($_POST['proveedor'] ?? '');

    if ($codigo === '' || $nombre === '' || $precioVenta <= 0) {
        $msg = 'Código de barras, nombre y precio de venta son obligatorios.';
        $msgTipo = 'error';
    } else {
        $check = $conn->prepare("SELECT id, stock FROM productos WHERE codigo_barras = ?");
        $check->bind_param('s', $codigo);
        $check->execute();
        $existe = $check->get_result()->fetch_assoc();

        if ($existe) {
            // Si ya existe el código, se suma al stock (reingreso de inventario)
            $nuevoStock = $existe['stock'] + $stock;
            $upd = $conn->prepare("UPDATE productos SET stock = ?, precio_compra = ?, precio_venta = ? WHERE id = ?");
            $upd->bind_param('iddi', $nuevoStock, $precioCompra, $precioVenta, $existe['id']);
            $upd->execute();
            $msg = "El código ya existía. Se actualizó el stock a $nuevoStock unidades.";
            $msgTipo = 'ok';
        } else {
            $stmt = $conn->prepare("INSERT INTO productos (codigo_barras, nombre, descripcion, categoria, precio_compra, precio_venta, stock, stock_minimo, proveedor, empleado_id) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param('ssssddiisi', $codigo, $nombre, $descripcion, $categoria, $precioCompra, $precioVenta, $stock, $stockMinimo, $proveedor, $empId);
            if ($stmt->execute()) {
                $msg = "Producto '$nombre' agregado al inventario correctamente.";
                $msgTipo = 'ok';
            } else {
                $msg = 'Error al guardar: ' . $stmt->error;
                $msgTipo = 'error';
            }
        }
    }
}

// ---- Búsqueda ----
$busqueda = trim($_GET['q'] ?? '');
if ($busqueda !== '') {
    $stmt = $conn->prepare("SELECT * FROM productos WHERE activo=1 AND (nombre LIKE ? OR codigo_barras LIKE ?) ORDER BY fecha_ingreso DESC");
    $like = "%$busqueda%";
    $stmt->bind_param('ss', $like, $like);
    $stmt->execute();
    $productos = $stmt->get_result();
} else {
    $productos = $conn->query("SELECT * FROM productos WHERE activo=1 ORDER BY fecha_ingreso DESC LIMIT 50");
}

$tituloPagina = 'Ingreso de Inventario';
$menu = [
    ['url'=>'dashboard.php','label'=>'Dashboard','icon'=>'📊'],
    ['url'=>'inventario.php','label'=>'Ingresar Inventario','icon'=>'📦'],
    ['url'=>'venta.php','label'=>'Punto de Venta','icon'=>'🛒'],
    ['url'=>'devoluciones.php','label'=>'Devoluciones','icon'=>'↩️'],
];
require_once '../includes/layout_top.php';
?>

<?php if ($msg): ?>
<div class="alerta <?php echo $msgTipo === 'ok' ? 'alerta-ok' : 'alerta-error'; ?>"><?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>

<div class="card">
    <h2>📦 Ingresar producto al inventario</h2>
    <form method="post" style="margin-top:15px;">
        <input type="hidden" name="accion" value="agregar">
        <div class="form-grid">
            <div>
                <label>Código de barras</label>
                <div style="display:flex;gap:8px;">
                    <input type="text" id="codigo_barras" name="codigo_barras" placeholder="Escanea o escribe el código" required>
                    <button type="button" class="btn btn-amarillo" onclick="generarCodigo()">Generar</button>
                </div>
            </div>
            <div>
                <label>Nombre del producto</label>
                <input type="text" name="nombre" required>
            </div>
            <div>
                <label>Categoría</label>
                <input type="text" name="categoria" placeholder="Dulces, chocolates, paletas...">
            </div>
            <div>
                <label>Proveedor</label>
                <input type="text" name="proveedor">
            </div>
            <div>
                <label>Precio de compra</label>
                <input type="number" step="0.01" min="0" name="precio_compra" required>
            </div>
            <div>
                <label>Precio de venta</label>
                <input type="number" step="0.01" min="0" name="precio_venta" required>
            </div>
            <div>
                <label>Cantidad (stock)</label>
                <input type="number" min="0" name="stock" required>
            </div>
            <div>
                <label>Stock mínimo (alerta)</label>
                <input type="number" min="0" name="stock_minimo" value="5">
            </div>
            <div style="grid-column:1/-1;">
                <label>Descripción</label>
                <textarea name="descripcion" rows="2"></textarea>
            </div>
        </div>
        <button class="btn btn-rosa" style="margin-top:15px;">Guardar en inventario</button>
    </form>
</div>

<div class="card">
    <h2>🔎 Buscar productos en inventario</h2>
    <form method="get" style="margin:15px 0;display:flex;gap:10px;">
        <input type="text" name="q" placeholder="Buscar por nombre o código de barras..." value="<?php echo htmlspecialchars($busqueda); ?>">
        <button class="btn btn-rosa">Buscar</button>
    </form>
    <table>
        <tr><th>Código</th><th>Producto</th><th>Categoría</th><th>Precio venta</th><th>Stock</th><th>Ingresado</th></tr>
        <?php while ($p = $productos->fetch_assoc()): ?>
        <tr>
            <td><?php echo htmlspecialchars($p['codigo_barras']); ?></td>
            <td><?php echo htmlspecialchars($p['nombre']); ?></td>
            <td><?php echo htmlspecialchars($p['categoria']); ?></td>
            <td>$<?php echo number_format($p['precio_venta'], 2); ?></td>
            <td><?php echo $p['stock'] <= $p['stock_minimo'] ? '<span class="badge badge-inactivo">' . $p['stock'] . '</span>' : $p['stock']; ?></td>
            <td><?php echo date('d/m/Y', strtotime($p['fecha_ingreso'])); ?></td>
        </tr>
        <?php endwhile; ?>
    </table>
</div>

<script>
function generarCodigo() {
    // Genera un código de barras numérico único de 12 dígitos (estilo EAN)
    let codigo = '';
    for (let i = 0; i < 12; i++) codigo += Math.floor(Math.random() * 10);
    document.getElementById('codigo_barras').value = codigo;
}
</script>

<?php require_once '../includes/layout_bottom.php'; ?>
