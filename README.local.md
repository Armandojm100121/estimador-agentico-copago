# Levantar el proyecto en LOCAL (Windows + XAMPP + VS Code)

Guía para correr el Estimador Agéntico de Copago en tu PC.
Producción sigue en Railway (deploy automático al hacer push a `main`); esto es solo para desarrollo local.

## Stack local
- **PHP 8.2** → `C:\xampp\php\php.exe` (servidor integrado, no hace falta Apache)
- **MySQL (MariaDB 10.4 de XAMPP)** → escucha en el puerto **3307**
- **API de IA:** Groq, la key está en `config.local.php`

> ⚠️ En este PC hay DOS MySQL:
> - **MySQL Server 8.0** (servicio `MySQL80`) ocupa el puerto **3306**.
> - **MariaDB de XAMPP** usa el puerto **3307**.
> El proyecto local usa el de **XAMPP (3307)**.

## Datos de conexión (desarrollo local)
Definidos en `db.local.php` (no se sube a Git):

| Campo | Valor              |
|-------|--------------------|
| Host  | `127.0.0.1`        |
| Puerto| `3307`             |
| Base  | `copago`           |
| User  | `root`             |
| Pass  | *(vacío)*          |

## Arranque diario (2 pasos)
1. Abre el **XAMPP Control Panel** y pulsa **Start** en **MySQL** (debe quedar verde, puerto 3307).
2. En **VS Code**, pulsa **`Ctrl + Shift + B`** (tarea "Levantar app (PHP server)").
   O en la terminal:
   ```powershell
   & "C:\xampp\php\php.exe" -S localhost:8000 -t .
   ```
3. Abre en el navegador: **http://localhost:8000/login.php**

Para detener: `Ctrl + C` en la terminal (o la papelera 🗑️ del panel de VS Code).

## Preparar la base de datos (solo la primera vez / si se pierde)
```powershell
& "C:\xampp\mysql\bin\mysql.exe" -u root --port=3307 --host=127.0.0.1 -e "CREATE DATABASE IF NOT EXISTS copago CHARACTER SET utf8mb4;"
& "C:\xampp\mysql\bin\mysql.exe" -u root --port=3307 --host=127.0.0.1 copago < "copago_backup_2026-05-21.sql"
```

## Solución de problemas

### XAMPP MySQL no arranca (conflicto de puerto)
Si el puerto 3307 estuviera ocupado o XAMPP se pone en rojo, revisa el log:
`C:\xampp\mysql\data\mysql_error.log`

### Quitar que MySQL80 arranque solo al encender Windows (opcional)
MySQL80 arranca automáticamente con Windows y ocupa el 3306. No estorba (XAMPP usa 3307),
pero si prefieres desactivarlo, abre **PowerShell como Administrador** y corre:
```powershell
Set-Service -Name MySQL80 -StartupType Manual
```
Para detenerlo puntualmente (también como admin):
```powershell
net stop MySQL80
```

### Ver que la conexión funciona
```powershell
& "C:\xampp\php\php.exe" -r "require 'db.php'; getDB(); echo 'Conexion OK';"
```

## Archivos de configuración local (NO se suben a Git)
- `db.local.php` → credenciales de MySQL local (puerto 3307)
- `config.local.php` → API key de Groq
