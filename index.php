<?php
// ==========================================================
//  FARMACIA VUELVE A LA VIDA - Sistema farmacéutico
//  PHP + MySQL + PDO + Bootstrap 5
// ==========================================================
session_start();
require __DIR__ . '/conexion.php';

const STOCK_BAJO = 5;
$dirSubidas = __DIR__ . '/uploads/';
if (!is_dir($dirSubidas)) {
    mkdir($dirSubidas, 0755, true);
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// Funciones auxiliares //
function e($t): string {
    return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
}

function compactNumber(float $n, string $pref = ''): string {
    if ($n >= 1000000) return $pref . round($n / 1000000, 1) . 'M';
    if ($n >= 1000)    return $pref . round($n / 1000, 1) . 'k';
    return $pref . number_format($n, 0);
}

function urlImagen(?string $archivo): ?string {
    if (!$archivo) return null;
    $archivo = basename(str_replace('\\', '/', trim($archivo, " \t\"'")));
    return is_file(__DIR__ . '/uploads/' . $archivo) ? 'uploads/' . rawurlencode($archivo) : null;
}

function borrarImagen(?string $archivo): void {
    if (!$archivo) return;
    $ruta = __DIR__ . '/uploads/' . basename(str_replace('\\', '/', $archivo));
    if (is_file($ruta)) @unlink($ruta);
}

function subirImagen(array $f, string $dir): ?string {
    if (!isset($f['error']) || $f['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('No se pudo subir la imagen.');
    if ($f['size'] > 3 * 1024 * 1024) throw new RuntimeException('La imagen supera los 3 MB.');

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime] ?? null;
    if (!$ext) throw new RuntimeException('Formato no válido. Usa JPG, PNG, WEBP o GIF.');

    // Guardamos solo el nombre de imagen en la BD //
    $nombre = bin2hex(random_bytes(8)) . '.' . $ext;

    if (!move_uploaded_file($f['tmp_name'], $dir . $nombre)) {
        throw new RuntimeException('No se pudo guardar la imagen en el servidor.');
    }

    return $nombre;
}

// Exportar inventario a CSV //
if (isset($_GET['exportar'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inventario_farmacia_vuelve_a_la_vida.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID', 'Nombre', 'Categoría', 'Precio', 'Stock', 'Caducidad', 'Proveedor'], ',', '"', '\\');

    foreach ($pdo->query('SELECT id, nombre, categoria, precio, stock, fecha_caducidad, proveedor FROM medicamentos ORDER BY id') as $r) {
        fputcsv($out, $r, ',', '"', '\\');
    }
    exit;
}


$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

//  Formulario de medicamento //
$form = [
    'id' => '',
    'nombre' => '',
    'categoria' => '',
    'precio' => '',
    'stock' => '',
    'descripcion' => '',
    'fecha_caducidad' => '',
    'proveedor' => '',
    'laboratorio' => '',
    'presentacion' => '',
    'imagen_ruta' => ''
];

// Procesar formularios //
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('Solicitud no válida. Recarga la página e inténtalo otra vez.');
    }

    $accion = $_POST['accion'] ?? '';

    try {
        // 1) Guardar medicamento
        if ($accion === 'guardar') {
            $id = (int)($_POST['id'] ?? 0);
            $nombre = trim($_POST['nombre'] ?? '');
            $categoria = trim($_POST['categoria'] ?? '');
            $descripcion = trim($_POST['descripcion'] ?? '');
            $precioTxt = trim($_POST['precio'] ?? '');
            $stockTxt = trim($_POST['stock'] ?? '');
            $fechaCaducidad = trim($_POST['fecha_caducidad'] ?? '');
            $proveedor = trim($_POST['proveedor'] ?? '');
            $laboratorio = trim($_POST['laboratorio'] ?? '');
            $presentacion = trim($_POST['presentacion'] ?? '');

            $form = array_merge($form, [
                'id' => $id ?: '',
                'nombre' => $nombre,
                'categoria' => $categoria,
                'precio' => $precioTxt,
                'stock' => $stockTxt,
                'descripcion' => $descripcion,
                'fecha_caducidad' => $fechaCaducidad,
                'proveedor' => $proveedor,
                'laboratorio' => $laboratorio,
                'presentacion' => $presentacion,
            ]);

            if ($nombre === '' || $precioTxt === '' || $stockTxt === '') {
                throw new InvalidArgumentException('Completa nombre, precio y stock.');
            }
            if ($fechaCaducidad === '') {
                throw new InvalidArgumentException('La fecha de caducidad es obligatoria.');
            }
            if ($proveedor === '') {
                throw new InvalidArgumentException('El proveedor es obligatorio.');
            }
            if (!is_numeric($precioTxt) || (float)$precioTxt < 0) {
                throw new InvalidArgumentException('El precio debe ser un número mayor o igual a 0.');
            }
            if (filter_var($stockTxt, FILTER_VALIDATE_INT) === false || (int)$stockTxt < 0) {
                throw new InvalidArgumentException('El stock debe ser un número entero mayor o igual a 0.');
            }

            $precio = round((float)$precioTxt, 2);
            $stock = (int)$stockTxt;
            $nueva = subirImagen($_FILES['imagen'] ?? [], $dirSubidas);
            $cat = $categoria !== '' ? $categoria : null;
            $desc = $descripcion !== '' ? $descripcion : null;

            if ($id > 0) {
                $st = $pdo->prepare('SELECT imagen_ruta FROM medicamentos WHERE id = ?');
                $st->execute([$id]);
                $actual = $st->fetchColumn();
                if ($actual === false) throw new InvalidArgumentException('El medicamento ya no existe.');

                $pdo->prepare('UPDATE medicamentos SET nombre = ?, categoria = ?, descripcion = ?, precio = ?, stock = ?, fecha_caducidad = ?, proveedor = ?, laboratorio = ?, presentacion = ?, imagen_ruta = ? WHERE id = ?')
                    ->execute([$nombre, $cat, $desc, $precio, $stock, $fechaCaducidad, $proveedor, $laboratorio, $presentacion, $nueva ?? $actual, $id]);

                if ($nueva) borrarImagen($actual);
                $_SESSION['flash'] = ['tipo' => 'ok', 'msg' => "Medicamento «{$nombre}» actualizado."];
            } else {
                $pdo->prepare('INSERT INTO medicamentos (nombre, categoria, descripcion, precio, stock, fecha_caducidad, proveedor, laboratorio, presentacion, imagen_ruta) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$nombre, $cat, $desc, $precio, $stock, $fechaCaducidad, $proveedor, $laboratorio, $presentacion, $nueva]);
                $_SESSION['flash'] = ['tipo' => 'ok', 'msg' => "Medicamento «{$nombre}» agregado."];
            }

            header('Location: index.php');
            exit;
        }

        // 2) Eliminar medicamento
        if ($accion === 'eliminar') {
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare('SELECT imagen_ruta FROM medicamentos WHERE id = ?');
            $st->execute([$id]);
            $img = $st->fetchColumn();
            $pdo->prepare('DELETE FROM medicamentos WHERE id = ?')->execute([$id]);
            if ($img) borrarImagen($img);
            $_SESSION['flash'] = ['tipo' => 'ok', 'msg' => 'Medicamento eliminado.'];
            header('Location: index.php');
            exit;
        }

        // 3) Registrar paciente
        if ($accion === 'guardar_paciente') {
            $nombre = trim($_POST['nombre'] ?? '');
            $apellido = trim($_POST['apellido'] ?? '');
            $cedula = trim($_POST['cedula'] ?? '');
            $telefono = trim($_POST['telefono'] ?? '');
            $fechaNacimiento = trim($_POST['fecha_nacimiento'] ?? '');
            $direccion = trim($_POST['direccion'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $alergias = trim($_POST['alergias'] ?? '');

            if ($nombre === '' || $apellido === '') {
                throw new InvalidArgumentException('Nombre y apellido son obligatorios.');
            }

            $pdo->prepare('INSERT INTO pacientes (nombre, apellido, cedula, telefono, fecha_nacimiento, direccion, email, alergias) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $nombre,
                    $apellido,
                    $cedula !== '' ? $cedula : null,
                    $telefono !== '' ? $telefono : null,
                    $fechaNacimiento !== '' ? $fechaNacimiento : null,
                    $direccion !== '' ? $direccion : null,
                    $email !== '' ? $email : null,
                    $alergias !== '' ? $alergias : null,
                ]);

            $_SESSION['flash'] = ['tipo' => 'ok', 'msg' => 'Paciente registrado correctamente.'];
            header('Location: index.php');
            exit;
        }
        
        
        //  Registrar venta
        if ($accion === 'registrar_venta') {
            $pacienteId = (int)($_POST['paciente_id'] ?? 0);
            $medicamentoId = (int)($_POST['medicamento_id'] ?? 0);
            $cantidad = (int)($_POST['cantidad'] ?? 0);
            $metodoPago = trim($_POST['metodo_pago'] ?? 'Efectivo');

            if ($pacienteId <= 0 || $medicamentoId <= 0 || $cantidad <= 0) {
                throw new InvalidArgumentException('Selecciona paciente, medicamento y cantidad válida.');
            }

            $st = $pdo->prepare('SELECT * FROM medicamentos WHERE id = ?');
            $st->execute([$medicamentoId]);
            $medicamento = $st->fetch();

            if (!$medicamento) {
                throw new InvalidArgumentException('El medicamento no existe.');
            }

            if ((int)$medicamento['stock'] < $cantidad) {
                throw new InvalidArgumentException('No hay stock suficiente para esa cantidad.');
            }

            $subtotal = round((float)$medicamento['precio'] * $cantidad, 2);

            $pdo->beginTransaction();

            $venta = $pdo->prepare('INSERT INTO ventas (paciente_id, total, metodo_pago) VALUES (?, ?, ?)');
            $venta->execute([$pacienteId, $subtotal, $metodoPago]);
            $ventaId = $pdo->lastInsertId();

            $pdo->prepare('INSERT INTO detalle_venta (venta_id, medicamento_id, cantidad, precio_unitario, subtotal) VALUES (?, ?, ?, ?, ?)')
                ->execute([$ventaId, $medicamentoId, $cantidad, $medicamento['precio'], $subtotal]);

            $pdo->prepare('UPDATE medicamentos SET stock = stock - ? WHERE id = ?')
                ->execute([$cantidad, $medicamentoId]);

            $pdo->commit();

            $_SESSION['flash'] = ['tipo' => 'ok', 'msg' => 'Venta registrada correctamente.'];
            header('Location: index.php');
            exit;
        }
    } catch (InvalidArgumentException | RuntimeException $ex) {
        $flash = ['tipo' => 'error', 'msg' => $ex->getMessage()];
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $flash = ['tipo' => 'error', 'msg' => 'No se pudo guardar la información en la base de datos.'];
    }
}

//  Editar medicamento //
if (isset($_GET['editar']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $st = $pdo->prepare('SELECT * FROM medicamentos WHERE id = ?');
    $st->execute([(int)$_GET['editar']]);
    if ($fila = $st->fetch()) {
        $form = [
            'id' => $fila['id'],
            'nombre' => $fila['nombre'],
            'categoria' => $fila['categoria'] ?? '',
            'precio' => $fila['precio'],
            'stock' => $fila['stock'],
            'descripcion' => $fila['descripcion'] ?? '',
            'fecha_caducidad' => $fila['fecha_caducidad'] ?? '',
            'proveedor' => $fila['proveedor'] ?? '',
            'laboratorio' => $fila['laboratorio'] ?? '',
            'presentacion' => $fila['presentacion'] ?? '',
            'imagen_ruta' => $fila['imagen_ruta'] ?? '',
        ];
    }
}
$editando = $form['id'] !== '';

//  Filtros //
$q = trim($_GET['q'] ?? '');
$cat = trim($_GET['cat'] ?? '');
$vista = $_GET['vista'] ?? 'todos';
if (!in_array($vista, ['todos', 'bajo', 'agotado'], true)) {
    $vista = 'todos';
}

$where = [];
$params = [];

if ($q !== '') {
    $where[] = '(nombre LIKE :q1 OR categoria LIKE :q2 OR descripcion LIKE :q3 OR proveedor LIKE :q4 OR laboratorio LIKE :q5)';
    $params[':q1'] = $params[':q2'] = $params[':q3'] = $params[':q4'] = $params[':q5'] = "%{$q}%";
}

if ($cat !== '') {
    $where[] = 'categoria = :cat';
    $params[':cat'] = $cat;
}

if ($vista === 'bajo') $where[] = 'stock <= ' . STOCK_BAJO;
if ($vista === 'agotado') $where[] = 'stock = 0';

$sql = 'SELECT * FROM medicamentos' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC';
$st = $pdo->prepare($sql);
$st->execute($params);
$medicamentos = $st->fetchAll();

// Estadísticas //
$res = $pdo->query(
    'SELECT COUNT(*) AS total,
            COALESCE(SUM(stock), 0) AS unidades,
            COALESCE(SUM(CASE WHEN stock <= ' . STOCK_BAJO . ' THEN 1 ELSE 0 END), 0) AS bajos,
            COALESCE(SUM(CASE WHEN stock = 0 THEN 1 ELSE 0 END), 0) AS agotados,
            COALESCE(SUM(precio * stock), 0) AS valor
     FROM medicamentos'
)->fetch();

$categorias = $pdo->query("SELECT categoria FROM medicamentos WHERE categoria IS NOT NULL AND categoria <> '' GROUP BY categoria ORDER BY categoria")->fetchAll(PDO::FETCH_COLUMN);

$barras = $pdo->query(
    "SELECT COALESCE(NULLIF(categoria, ''), 'Sin categoría') AS c, COUNT(*) AS n
     FROM medicamentos GROUP BY c ORDER BY n DESC LIMIT 5"
)->fetchAll();

$bajos = $pdo->query('SELECT nombre, stock FROM medicamentos WHERE stock <= ' . STOCK_BAJO . ' ORDER BY stock ASC, id DESC LIMIT 4')->fetchAll();

$pacientes = $pdo->query('SELECT * FROM pacientes ORDER BY nombre, apellido')->fetchAll();

$ventasRecientes = $pdo->query("
    SELECT v.*, CONCAT(p.nombre, ' ', p.apellido) AS paciente
    FROM ventas v
    INNER JOIN pacientes p ON p.id = v.paciente_id
    ORDER BY v.fecha_venta DESC
    LIMIT 8
")->fetchAll();

function urlVista(string $v, string $q, string $c): string {
    return 'index.php?' . http_build_query(array_filter(['vista' => $v === 'todos' ? '' : $v, 'q' => $q, 'cat' => $c]));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>FARMACIA VUELVE A LA VIDA</title>

  // Bootstrap 5 //
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
  <link rel="stylesheet" href="estilos.css">
</head>
<body>
<div class="container py-4">

  <div class="text-center mb-4">
    <h1 class="main-title">FARMACIA VUELVE A LA VIDA</h1>
  </div>

  <?php if ($flash): ?>
    <div class="alert <?= $flash['tipo'] === 'error' ? 'alert-danger' : 'alert-success' ?> alert-dismissible fade show" role="alert">
      <?= e($flash['msg']) ?>
    </div>
  <?php endif; ?>

  <nav class="navbar navbar-expand-lg farmacia-navbar mb-4">
    <div class="container-fluid">
      <a class="navbar-brand" href="index.php"><i class="fa-solid fa-prescription-bottle-medical"></i> Inventario</a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#menuFarmacia" aria-controls="menuFarmacia" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
      </button>

      <div class="collapse navbar-collapse" id="menuFarmacia">
        <ul class="navbar-nav me-auto mb-2 mb-lg-0">
          <li class="nav-item">
            <a class="nav-link active" href="#formulario"><i class="fa-solid fa-plus"></i> Nuevo medicamento</a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="#pacientes"><i class="fa-solid fa-user-doctor"></i> Pacientes</a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="#ventas"><i class="fa-solid fa-cash-register"></i> Ventas</a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="index.php?vista=bajo">
              <i class="fa-solid fa-bell"></i> Alertas
              <?php if ((int)$res['bajos'] > 0): ?><span class="badge bg-danger ms-2"><?= (int)$res['bajos'] ?></span><?php endif; ?>
            </a>
          </li>
        </ul>

        <form class="d-flex" method="get" action="index.php" role="search">
          <?php if ($vista !== 'todos'): ?><input type="hidden" name="vista" value="<?= e($vista) ?>"><?php endif; ?>
          <input class="form-control me-2" type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar medicamento">
          <button class="btn btn-success" type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>
        </form>
      </div>
    </div>
  </nav>

  <div class="row g-4">
    // Columna izquierda: formulario //
    <div class="col-lg-4">
      <div class="card shadow-sm mb-4" id="formulario">
        <div class="card-body">
          <h2 class="section-title"><?= $editando ? 'Editar medicamento' : 'Nuevo medicamento' ?></h2>

          <form method="post" enctype="multipart/form-data" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="accion" value="guardar">
            <input type="hidden" name="id" value="<?= e($form['id']) ?>">

            <div class="mb-3">
              <label class="form-label">Nombre</label>
              <input type="text" class="form-control" name="nombre" maxlength="120" required value="<?= e($form['nombre']) ?>">
            </div>

            <div class="mb-3">
              <label class="form-label">Categoría</label>
              <input type="text" class="form-control" name="categoria" maxlength="80" list="lista-categorias" value="<?= e($form['categoria']) ?>">
              <datalist id="lista-categorias">
                <?php foreach ($categorias as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?>
              </datalist>
            </div>

            <div class="mb-3">
              <label class="form-label">Descripción</label>
              <textarea class="form-control" name="descripcion" rows="3" maxlength="500"><?= e($form['descripcion']) ?></textarea>
            </div>

            <div class="row">
              <div class="col-md-6">
                <div class="mb-3">
                  <label class="form-label">Precio</label>
                  <input type="number" class="form-control" name="precio" min="0" step="0.01" required value="<?= e($form['precio']) ?>">
                </div>
              </div>
              <div class="col-md-6">
                <div class="mb-3">
                  <label class="form-label">Stock</label>
                  <input type="number" class="form-control" name="stock" min="0" step="1" required value="<?= e($form['stock']) ?>">
                </div>
              </div>
            </div>

            <div class="row">
              <div class="col-md-6">
                <div class="mb-3">
                  <label class="form-label">Caducidad</label>
                  <input type="date" class="form-control" name="fecha_caducidad" value="<?= e($form['fecha_caducidad']) ?>" required>
                </div>
              </div>
              <div class="col-md-6">
                <div class="mb-3">
                  <label class="form-label">Presentación</label>
                  <input type="text" class="form-control" name="presentacion" maxlength="80" value="<?= e($form['presentacion']) ?>">
                </div>
              </div>
            </div>

            <div class="row">
              <div class="col-md-6">
                <div class="mb-3">
                  <label class="form-label">Proveedor</label>
                  <input type="text" class="form-control" name="proveedor" maxlength="120" value="<?= e($form['proveedor']) ?>" required>
                </div>
              </div>
              <div class="col-md-6">
                <div class="mb-3">
                  <label class="form-label">Laboratorio</label>
                  <input type="text" class="form-control" name="laboratorio" maxlength="120" value="<?= e($form['laboratorio']) ?>">
                </div>
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label">Imagen</label>
              <input type="file" class="form-control" name="imagen" accept="image/jpeg,image/png,image/webp,image/gif">
            </div>

            <?php if ($editando && urlImagen($form['imagen_ruta'])): ?>
              <p class="small text-muted text-center mb-3">Si no eliges otra imagen, se conserva la actual.</p>
            <?php endif; ?>

            <button class="btn btn-primary w-100" type="submit"><?= $editando ? 'Guardar cambios' : 'Agregar medicamento' ?></button>
            <?php if ($editando): ?>
              <div class="text-center mt-2">
                <a href="index.php" class="text-decoration-none">Cancelar edición</a>
              </div>
            <?php endif; ?>
          </form>
        </div>
      </div>
    </div>

    <!-- Columna central -->
    <div class="col-lg-4">
      <div class="card shadow-sm mb-4">
        <div class="card-body">
          <h2 class="section-title">Filtrar</h2>
          <form method="get" action="index.php">
            <?php if ($vista !== 'todos'): ?><input type="hidden" name="vista" value="<?= e($vista) ?>"><?php endif; ?>
            <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>

            <div class="mb-3">
              <label class="form-label">Categoría</label>
              <select name="cat" class="form-select">
                <option value="">Todas las categorías</option>
                <?php foreach ($categorias as $c): ?>
                  <option value="<?= e($c) ?>" <?= $c === $cat ? 'selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <button class="btn btn-success w-100" type="submit">Filtrar</button>
          </form>
        </div>
      </div>

      <div class="row g-3 mb-4">
        <div class="col-6">
          <div class="stat-card stat-blue">
            <div class="icon-box"><i class="fa-solid fa-boxes-stacked"></i></div>
            <div class="stat-number"><?= compactNumber((float)$res['total']) ?></div>
            <div class="stat-label">Medicamentos</div>
          </div>
        </div>
        <div class="col-6">
          <div class="stat-card stat-cyan">
            <div class="icon-box"><i class="fa-solid fa-cubes"></i></div>
            <div class="stat-number"><?= compactNumber((float)$res['unidades']) ?></div>
            <div class="stat-label">Unidades</div>
          </div>
        </div>
        <div class="col-6">
          <div class="stat-card stat-pink">
            <div class="icon-box"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div class="stat-number"><?= compactNumber((float)$res['bajos']) ?></div>
            <div class="stat-label">Stock bajo</div>
          </div>
        </div>
        <div class="col-6">
          <div class="stat-card stat-dark">
            <div class="icon-box"><i class="fa-solid fa-sack-dollar"></i></div>
            <div class="stat-number"><?= compactNumber((float)$res['valor'], '$') ?></div>
            <div class="stat-label">Valor</div>
          </div>
        </div>
      </div>

      <div class="card shadow-sm">
        <div class="card-body">
          <h2 class="section-title">Categorías</h2>
          <?php if (!$barras): ?>
            <p class="text-center text-muted mb-0">Aún no hay medicamentos.</p>
          <?php endif; ?>
          <?php foreach ($barras as $b): $pct = $res['total'] ? round($b['n'] / $res['total'] * 100) : 0; ?>
            <div class="mb-3">
              <div class="d-flex justify-content-between small fw-bold">
                <span><?= e($b['c']) ?></span>
                <span><?= (int)$b['n'] ?></span>
              </div>
              <div class="progress" style="height: 8px;">
                <div class="progress-bar bg-success" role="progressbar" style="width: <?= $pct ?>%;"></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    // Columna derecha //
    <div class="col-lg-4">
      <div class="card shadow-sm mb-4">
        <div class="card-header farmacia-header">
          <div class="text-center">
            <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-white text-success fs-4" style="width:52px;height:52px;">
              <i class="fa-solid fa-store"></i>
            </div>
            <h5 class="mt-2 mb-0">FARMACIA VUELVE A LA VIDA</h5>
            <small>Inventario farmacéutico</small>
          </div>
        </div>
        <div class="card-body p-0">
          <div class="d-flex border-bottom">
            <a href="<?= e(urlVista('todos', $q, $cat)) ?>" class="flex-fill text-center py-3 tab-link <?= $vista === 'todos' ? 'active' : '' ?>">Todos</a>
            <a href="<?= e(urlVista('bajo', $q, $cat)) ?>" class="flex-fill text-center py-3 tab-link <?= $vista === 'bajo' ? 'active' : '' ?>">Stock bajo</a>
            <a href="<?= e(urlVista('agotado', $q, $cat)) ?>" class="flex-fill text-center py-3 tab-link <?= $vista === 'agotado' ? 'active' : '' ?>">Agotados</a>
          </div>
          <div class="p-3 text-muted small">
            Tienes <?= (int)$res['total'] ?> medicamentos registrados con <?= (int)$res['unidades'] ?> unidades en total.
            <?= (int)$res['agotados'] ?> agotados y <?= (int)$res['bajos'] ?> con stock de <?= STOCK_BAJO ?> o menos.
          </div>
        </div>
      </div>

      <div class="card shadow-sm">
        <div class="card-header bg-info text-white">
          <strong><i class="fa-solid fa-bell"></i> Por reponer</strong>
        </div>
        <div class="card-body p-0">
          <?php if ($bajos): ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($bajos as $b): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                  <span><?= e($b['nombre']) ?></span>
                  <span class="badge <?= (int)$b['stock'] === 0 ? 'bg-danger' : 'bg-warning text-dark' ?>">
                    <?= (int)$b['stock'] === 0 ? 'Agotado' : (int)$b['stock'] . ' uds' ?>
                  </span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div class="p-3 text-center text-muted">Todo el inventario está en buen nivel.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

   //  Registro de paciente //
  <div class="card shadow-sm mt-5" id="pacientes">
    <div class="card-body">
      <h2 class="section-title">Registrar paciente</h2>

      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <input type="hidden" name="accion" value="guardar_paciente">

        <div class="row">
          <div class="col-md-6">
            <div class="mb-3">
              <label class="form-label">Nombre</label>
              <input type="text" class="form-control" name="nombre" required>
            </div>
          </div>
          <div class="col-md-6">
            <div class="mb-3">
              <label class="form-label">Apellido</label>
              <input type="text" class="form-control" name="apellido" required>
            </div>
          </div>
        </div>

        <div class="row">
          <div class="col-md-6">
            <div class="mb-3">
              <label class="form-label">Cédula</label>
              <input type="text" class="form-control" name="cedula">
            </div>
          </div>
          <div class="col-md-6">
            <div class="mb-3">
              <label class="form-label">Teléfono</label>
              <input type="tel" class="form-control" name="telefono">
            </div>
          </div>
        </div>

        <div class="row">
          <div class="col-md-6">
            <div class="mb-3">
              <label class="form-label">Fecha de nacimiento</label>
              <input type="date" class="form-control" name="fecha_nacimiento">
            </div>
          </div>
          <div class="col-md-6">
            <div class="mb-3">
              <label class="form-label">Correo electrónico</label>
              <input type="email" class="form-control" name="email">
            </div>
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label">Dirección</label>
          <input type="text" class="form-control" name="direccion">
        </div>

        <div class="mb-3">
          <label class="form-label">Alergias</label>
          <textarea class="form-control" name="alergias" rows="2"></textarea>
        </div>

        <button class="btn btn-primary" type="submit">Guardar paciente</button>
      </form>
    </div>
  </div>

   // Registro de venta //
  <div class="card shadow-sm mt-5" id="ventas">
    <div class="card-body">
      <h2 class="section-title">Registrar venta</h2>

      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <input type="hidden" name="accion" value="registrar_venta">

        <div class="row">
          <div class="col-md-4">
            <div class="mb-3">
              <label class="form-label">Paciente</label>
              <select class="form-select" name="paciente_id" required>
                <option value="">Selecciona paciente</option>
                <?php foreach ($pacientes as $p): ?>
                  <option value="<?= (int)$p['id'] ?>"><?= e($p['nombre'] . ' ' . $p['apellido']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="col-md-4">
            <div class="mb-3">
              <label class="form-label">Medicamento</label>
              <select class="form-select" name="medicamento_id" required>
                <option value="">Selecciona medicamento</option>
                <?php foreach ($pdo->query("SELECT * FROM medicamentos WHERE stock > 0 ORDER BY nombre")->fetchAll() as $m): ?>
                  <option value="<?= (int)$m['id'] ?>"><?= e($m['nombre']) ?> - $<?= number_format((float)$m['precio'], 2) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="col-md-2">
            <div class="mb-3">
              <label class="form-label">Cantidad</label>
              <input type="number" class="form-control" name="cantidad" min="1" value="1" required>
            </div>
          </div>

          <div class="col-md-2">
            <div class="mb-3">
              <label class="form-label">Pago</label>
              <select class="form-select" name="metodo_pago">
                <option value="Efectivo">Efectivo</option>
                <option value="Tarjeta">Tarjeta</option>
                <option value="Transferencia">Transferencia</option>
              </select>
            </div>
          </div>
        </div>

        <button class="btn btn-success" type="submit">Registrar venta</button>
      </form>
    </div>
  </div>

  // Medicamentos //
  <div class="d-flex justify-content-between align-items-center mt-5 mb-3">
    <h2 class="section-title mb-0">Medicamentos en inventario</h2>
    <span class="text-muted small"><?= count($medicamentos) ?> resultado<?= count($medicamentos) === 1 ? '' : 's' ?></span>
  </div>

  <div class="row g-4">
    <?php if (!$medicamentos): ?>
      <div class="col-12">
        <div class="alert alert-light text-center py-5">
          <?= ($q !== '' || $cat !== '' || $vista !== 'todos') ? 'No hay medicamentos con esos filtros.' : 'Todavía no hay medicamentos. Agrega el primero desde el formulario.' ?>
        </div>
      </div>
    <?php endif; ?>

    <?php foreach ($medicamentos as $p):
        $img = urlImagen($p['imagen_ruta']);
        $stk = (int)$p['stock'];
        $badgeClass = $stk === 0 ? 'bg-danger' : ($stk <= STOCK_BAJO ? 'bg-warning text-dark' : 'bg-success');
    ?>
      <div class="col-md-6 col-lg-4">
        <div class="card h-100 shadow-sm producto-card">
          <div class="product-image">
            <?php if ($img): ?>
              <img src="<?= e($img) ?>" class="card-img-top" alt="<?= e($p['nombre']) ?>">
            <?php else: ?>
              <div class="no-image text-center"><i class="fa-regular fa-image"></i></div>
            <?php endif; ?>
          </div>
          <div class="card-body d-flex flex-column">
            <div class="small text-success fw-bold"><?= e($p['categoria'] ?: 'Sin categoría') ?></div>
            <h5 class="card-title mt-2"><?= e($p['nombre']) ?></h5>
            <?php if (!empty($p['descripcion'])): ?><p class="card-text text-muted small"><?= e($p['descripcion']) ?></p><?php endif; ?>
            <?php if (!empty($p['fecha_caducidad'])): ?><p class="mb-1 small text-muted">Caduca: <?= date('d/m/Y', strtotime($p['fecha_caducidad'])) ?></p><?php endif; ?>
            <?php if (!empty($p['proveedor'])): ?><p class="mb-1 small text-muted">Proveedor: <?= e($p['proveedor']) ?></p><?php endif; ?>
            <div class="mt-auto d-flex justify-content-between align-items-center pt-3">
              <span class="fw-bold text-success fs-5">$<?= number_format((float)$p['precio'], 2) ?></span>
              <span class="badge <?= $badgeClass ?>"><?= $stk === 0 ? 'Agotado' : 'Stock: ' . $stk ?></span>
            </div>
          </div>
          <div class="card-footer bg-white border-0 d-flex p-0">
            <a href="index.php?editar=<?= (int)$p['id'] ?>#formulario" class="btn btn-outline-primary flex-fill rounded-0">
              <i class="fa-solid fa-pen"></i> Editar
            </a>
            <form method="post" class="flex-fill m-0" onsubmit="return confirm('¿Seguro que quieres eliminar este medicamento?');">
              <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
              <input type="hidden" name="accion" value="eliminar">
              <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <button type="submit" class="btn btn-outline-danger w-100 rounded-0">
                <i class="fa-solid fa-trash"></i> Eliminar
              </button>
            </form>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

   //  Pacientes registrados //
  <div class="d-flex justify-content-between align-items-center mt-5 mb-3">
    <h2 class="section-title mb-0">Pacientes registrados</h2>
    <span class="text-muted small"><?= count($pacientes) ?> paciente<?= count($pacientes) === 1 ? '' : 's' ?></span>
  </div>

  <div class="row g-4">
    <?php if (!$pacientes): ?>
      <div class="col-12">
        <div class="alert alert-light text-center py-5">Aún no hay pacientes registrados.</div>
      </div>
    <?php endif; ?>

    <?php foreach ($pacientes as $p): ?>
      <div class="col-md-6 col-lg-3">
        <div class="card shadow-sm h-100">
          <div class="card-body text-center">
            <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center mb-3" style="width:64px;height:64px;">
              <i class="fa-solid fa-user-doctor fs-4 text-success"></i>
            </div>
            <h5 class="mb-1"><?= e($p['nombre'] . ' ' . $p['apellido']) ?></h5>
            <div class="small text-muted mb-2"><?= e($p['cedula'] ?: 'Sin cédula') ?></div>
            <?php if (!empty($p['telefono'])): ?><p class="small mb-1"><i class="fa-solid fa-phone"></i> <?= e($p['telefono']) ?></p><?php endif; ?>
            <?php if (!empty($p['email'])): ?><p class="small mb-1"><i class="fa-solid fa-envelope"></i> <?= e($p['email']) ?></p><?php endif; ?>
            <?php if (!empty($p['alergias'])): ?><p class="small mb-0"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($p['alergias']) ?></p><?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
 // Ventas recientes //
  <div class="d-flex justify-content-between align-items-center mt-5 mb-3">
    <h2 class="section-title mb-0">Ventas recientes</h2>
    <span class="text-muted small"><?= count($ventasRecientes) ?> registro<?= count($ventasRecientes) === 1 ? '' : 's' ?></span>
  </div>

  <div class="card shadow-sm">
    <div class="card-body">
      <?php if (!$ventasRecientes): ?>
        <div class="text-center text-muted py-3">No hay ventas registradas.</div>
      <?php else: ?>
        <?php foreach ($ventasRecientes as $v): ?>
          <div class="d-flex justify-content-between align-items-center border-bottom py-3">
            <div>
              <strong><?= e($v['paciente']) ?></strong><br>
              <small class="text-muted"><?= date('d/m/Y H:i', strtotime($v['fecha_venta'])) ?></small>
            </div>
            <div class="text-end">
              <span class="badge bg-light text-dark"><?= e($v['metodo_pago']) ?></span><br>
              <strong class="text-success">$<?= number_format((float)$v['total'], 2) ?></strong>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="text-center mt-5 mb-3 text-muted small">
    FARMACIA VUELVE A LA VIDA · Sistema farmacéutico
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
