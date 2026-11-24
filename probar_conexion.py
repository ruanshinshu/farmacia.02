try:
    import pymysql
except ModuleNotFoundError as error:
    if error.name != "pymysql":
        raise
    raise SystemExit(
        "Falta PyMySQL. Instalalo con: python -m pip install PyMySQL"
    ) from error


HOST = "127.0.0.1"
PORT = 3306
DATABASE = "farmacia_vuelve_a_la_vida"


def main() -> int:
    print("Conectando a MySQL de XAMPP...")
    print(f"Direccion: {HOST}:{PORT}")

    try:
        with pymysql.connect(
            host=HOST,
            port=PORT,
            user="root",
            password="",
            connect_timeout=10,
            charset="utf8mb4",
        ) as db:
            with db.cursor() as cursor:
                cursor.execute(
                    f"CREATE DATABASE IF NOT EXISTS `{DATABASE}` "
                    "CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
                )
                db.select_db(DATABASE)

                cursor.execute("""
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
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                """)

                cursor.execute("""
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
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                """)

                cursor.execute("""
                    CREATE TABLE IF NOT EXISTS ventas (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        paciente_id INT NOT NULL,
                        fecha_venta DATETIME DEFAULT CURRENT_TIMESTAMP,
                        total DECIMAL(10,2) NOT NULL DEFAULT 0,
                        metodo_pago VARCHAR(30) DEFAULT 'Efectivo',
                        observaciones TEXT NULL,
                        FOREIGN KEY (paciente_id) REFERENCES pacientes(id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                """)

                cursor.execute("""
                    CREATE TABLE IF NOT EXISTS detalle_venta (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        venta_id INT NOT NULL,
                        medicamento_id INT NOT NULL,
                        cantidad INT NOT NULL,
                        precio_unitario DECIMAL(10,2) NOT NULL,
                        subtotal DECIMAL(10,2) NOT NULL,
                        FOREIGN KEY (venta_id) REFERENCES ventas(id)
                            ON DELETE CASCADE,
                        FOREIGN KEY (medicamento_id) REFERENCES medicamentos(id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                """)

        print("Conexión exitosa.")
        print(f"Base de datos '{DATABASE}' y tablas listas.")
        return 0
    except pymysql.MySQLError as error:
        print(f"Error de MySQL: {error}")
        print("Verifica que MySQL esté iniciado en XAMPP y que el puerto 3306 esté disponible.")
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
