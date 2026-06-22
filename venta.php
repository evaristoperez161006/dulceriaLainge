<?php
require_once '../config.php';
require_once '../includes/auth_check.php';
checkRol('empleado');
$conn = getConexion();
$empId = $_SESSION['usuario_id'];

$msg = '';
$msgTipo = '';
$ticketGenerado = null;

// ---- Procesar venta ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'finalizar_venta') {
    $carrito = json_decode($_POST['carrito_json'] ?? '[]', true);
    $metodoPago = $_POST['metodo_pago'] ?? 'efectivo';

    if (!$carrito || count($carrito) === 0) {
        $msg = 'El carrito está vacío.';
        $msgTipo = 'error';
    } else {
        $conn->begin_transaction();
        try {
            $total = 0;
            // Validar stock
            foreach ($carrito as $item) {
                $pid = (int)$item['id'];
                $cant = (int)$item['cantidad'];
                $check = $conn->prepare("SELECT stock, precio_venta FROM productos WHERE id = ? FOR UPDATE");
                $check->bind_param('i', $pid);
                $check->execute();
                $prod = $check->get_result()->fetch_assoc();
                if (!$prod || $prod['stock'] < $cant) {
                    throw new Exception('Stock insuficiente para uno de los productos.');
                }
                $total += $prod['precio_venta'] * $cant;
            }

            $folio = 'TCK-' . date('Ymd') . '-' . substr(uniqid(), -5);
            $ventaStmt = $conn->prepare("INSERT INTO ventas (folio, empleado_id, total, metodo_pago) VALUES (?, ?, ?, ?)");
            $ventaStmt->bind_param('sids', $folio, $empId, $total, $metodoPago);
            $ventaStmt->execute();
            $ventaId = $conn->insert_id;

            foreach ($carrito as $item) {
                $pid = (int)$item['id'];
                $cant = (int)$item['cantidad'];
                $pStmt = $conn->prepare("SELECT precio_venta FROM productos WHERE id = ?");
                $pStmt->bind_param('i', $pid);
                $pStmt->execute();
                $precio = $pStmt->get_result()->fetch_assoc()['precio_venta'];
                $subtotal = $precio * $cant;

                $detStmt = $conn->prepare("INSERT INTO venta_detalle (venta_id, producto_id, cantidad, precio_unitario, subtotal) VALUES (?,?,?,?,?)");
                $detStmt->bind_param('iiidd', $ventaId, $pid, $cant, $precio, $subtotal);
                $detStmt->execute();

                $updStock = $conn->prepare("UPDATE productos SET stock = stock - ? WHERE id = ?");
                $updStock->bind_param('ii', $cant, $pid);
                $updStock->execute();
            }

            $conn->commit();
            $msg = "Venta registrada correctamente. Folio: $folio";
            $msgTipo = 'ok';
            $ticketGenerado = $ventaId;
        } catch (Exception $e) {
            $conn->rollback();
            $msg = 'Error al procesar la venta: ' . $e->getMessage();
            $msgTipo = 'error';
        }
    }
}

$tituloPagina = 'Punto de Venta';
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
<?php if ($ticketGenerado): ?>
<div class="card">
    <a class="btn btn-rosa" href="ticket.php?id=<?php echo $ticketGenerado; ?>" target="_blank">Ver ticket generado</a>
</div>
<?php endif; ?>
<?php endif; ?>

<div class="card">
    <h2>🛒 Buscar productos</h2>
    <input type="text" id="buscador" placeholder="Escanea el código de barras o escribe el nombre del producto..." autofocus>
    <div id="resultados" style="margin-top:10px;"></div>
</div>

<div class="card">
    <h2>🧺 Carrito de venta</h2>
    <table id="tablaCarrito">
        <tr><th>Producto</th><th>Precio</th><th>Cantidad</th><th>Subtotal</th><th></th></tr>
    </table>
    <h3 style="text-align:right;margin-top:15px;">Total: $<span id="totalCarrito">0.00</span></h3>

    <form method="post" id="formVenta" style="margin-top:15px;">
        <input type="hidden" name="accion" value="finalizar_venta">
        <input type="hidden" name="carrito_json" id="carrito_json">
        <div class="form-grid">
            <div>
                <label>Método de pago</label>
                <select name="metodo_pago">
                    <option value="efectivo">Efectivo</option>
                    <option value="tarjeta">Tarjeta</option>
                    <option value="transferencia">Transferencia</option>
                </select>
            </div>
        </div>
        <button type="submit" class="btn btn-verde" style="margin-top:15px;">✅ Finalizar venta</button>
    </form>
</div>

<script>
let carrito = [];

const buscador = document.getElementById('buscador');
const resultadosDiv = document.getElementById('resultados');

let timeoutBusqueda;
buscador.addEventListener('input', () => {
    clearTimeout(timeoutBusqueda);
    const q = buscador.value.trim();
    if (q.length < 1) { resultadosDiv.innerHTML = ''; return; }
    timeoutBusqueda = setTimeout(() => buscarProductos(q), 250);
});

// Soporte para lectores de código de barras (envían Enter al final)
buscador.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
        e.preventDefault();
        buscarProductos(buscador.value.trim(), true);
    }
});

function buscarProductos(q, autoAgregar = false) {
    fetch('buscar_producto.php?q=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(data => {
            if (autoAgregar && data.length === 1) {
                agregarAlCarrito(data[0]);
                resultadosDiv.innerHTML = '';
                buscador.value = '';
                return;
            }
            mostrarResultados(data);
        });
}

function mostrarResultados(data) {
    if (data.length === 0) { resultadosDiv.innerHTML = '<p>No se encontraron productos.</p>'; return; }
    let html = '<table><tr><th>Código</th><th>Producto</th><th>Precio</th><th>Stock</th><th></th></tr>';
    data.forEach(p => {
        html += `<tr>
            <td>${p.codigo_barras}</td>
            <td>${p.nombre}</td>
            <td>$${parseFloat(p.precio_venta).toFixed(2)}</td>
            <td>${p.stock}</td>
            <td><button type="button" class="btn btn-rosa" onclick='agregarAlCarrito(${JSON.stringify(p)})'>Agregar</button></td>
        </tr>`;
    });
    html += '</table>';
    resultadosDiv.innerHTML = html;
}

function agregarAlCarrito(producto) {
    const existente = carrito.find(i => i.id === producto.id);
    if (existente) {
        if (existente.cantidad < producto.stock) existente.cantidad++;
    } else {
        carrito.push({ id: producto.id, nombre: producto.nombre, precio: parseFloat(producto.precio_venta), cantidad: 1, stockMax: producto.stock });
    }
    renderCarrito();
}

function quitarDelCarrito(id) {
    carrito = carrito.filter(i => i.id !== id);
    renderCarrito();
}

function cambiarCantidad(id, delta) {
    const item = carrito.find(i => i.id === id);
    if (!item) return;
    item.cantidad += delta;
    if (item.cantidad <= 0) { quitarDelCarrito(id); return; }
    if (item.cantidad > item.stockMax) item.cantidad = item.stockMax;
    renderCarrito();
}

function renderCarrito() {
    const tabla = document.getElementById('tablaCarrito');
    let html = '<tr><th>Producto</th><th>Precio</th><th>Cantidad</th><th>Subtotal</th><th></th></tr>';
    let total = 0;
    carrito.forEach(item => {
        const subtotal = item.precio * item.cantidad;
        total += subtotal;
        html += `<tr>
            <td>${item.nombre}</td>
            <td>$${item.precio.toFixed(2)}</td>
            <td>
                <button type="button" class="btn btn-gris" onclick="cambiarCantidad(${item.id}, -1)">-</button>
                ${item.cantidad}
                <button type="button" class="btn btn-gris" onclick="cambiarCantidad(${item.id}, 1)">+</button>
            </td>
            <td>$${subtotal.toFixed(2)}</td>
            <td><button type="button" class="btn btn-rojo" onclick="quitarDelCarrito(${item.id})">Quitar</button></td>
        </tr>`;
    });
    tabla.innerHTML = html;
    document.getElementById('totalCarrito').innerText = total.toFixed(2);
}

document.getElementById('formVenta').addEventListener('submit', function (e) {
    if (carrito.length === 0) {
        e.preventDefault();
        alert('Agrega al menos un producto al carrito.');
        return;
    }
    document.getElementById('carrito_json').value = JSON.stringify(carrito.map(i => ({ id: i.id, cantidad: i.cantidad })));
});
</script>

<?php require_once '../includes/layout_bottom.php'; ?>
