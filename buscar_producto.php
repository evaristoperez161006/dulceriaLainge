<?php
require_once '../config.php';
require_once '../includes/auth_check.php';
checkRol('empleado');
$conn = getConexion();

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if ($q === '') { echo json_encode([]); exit; }

$stmt = $conn->prepare("SELECT id, codigo_barras, nombre, precio_venta, stock FROM productos WHERE activo=1 AND stock > 0 AND (nombre LIKE ? OR codigo_barras = ?) ORDER BY nombre LIMIT 15");
$like = "%$q%";
$stmt->bind_param('ss', $like, $q);
$stmt->execute();
$res = $stmt->get_result();

$out = [];
while ($row = $res->fetch_assoc()) { $out[] = $row; }
echo json_encode($out);
