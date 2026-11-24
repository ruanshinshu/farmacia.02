CREATE DATABASE IF NOT EXISTS farmacia_vuelve_a_la_vida
  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

USE farmacia_vuelve_a_la_vida;

CREATE TABLE IF NOT EXISTS medicamentos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(120) NOT NULL,
  categoria VARCHAR(80) NULL,
  descripcion TEXT NULL,
  precio DECIMAL(10,2) NOT NULL DEFAULT 0,
  stock INT NOT NULL DEFAULT 0,
  fecha_caducidad DATE NULL,
  proveedor VARCHAR(120) NULL,
  laboratorio VARCHAR(120) NULL,
  presentacion VARCHAR(80) NULL,
  imagen_ruta VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pacientes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  apellido VARCHAR(100) NOT NULL,
  cedula VARCHAR(30) NULL,
  telefono VARCHAR(30) NULL,
  fecha_nacimiento DATE NULL,
  direccion TEXT NULL,
  email VARCHAR(120) NULL,
  alergias TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ventas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  paciente_id INT NOT NULL,
  fecha_venta DATETIME DEFAULT CURRENT_TIMESTAMP,
  total DECIMAL(10,2) NOT NULL DEFAULT 0,
  metodo_pago VARCHAR(30) DEFAULT 'Efectivo',
  observaciones TEXT NULL,
  FOREIGN KEY (paciente_id) REFERENCES pacientes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS detalle_venta (
  id INT AUTO_INCREMENT PRIMARY KEY,
  venta_id INT NOT NULL,
  medicamento_id INT NOT NULL,
  cantidad INT NOT NULL,
  precio_unitario DECIMAL(10,2) NOT NULL,
  subtotal DECIMAL(10,2) NOT NULL,
  FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE CASCADE,
  FOREIGN KEY (medicamento_id) REFERENCES medicamentos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;