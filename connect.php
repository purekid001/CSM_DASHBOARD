<?php
// ไฟล์ connect.php
// รองรับการย้าย credential ไปไว้ใน environment variables โดยยังคงทำงานได้กับค่าปัจจุบัน
$servername = getenv('MFOOD_DB_SERVER') ?: "192.168.2.3\MFOODSQL";
$username = getenv('MFOOD_DB_USERNAME') ?: "mfood";
$password = getenv('MFOOD_DB_PASSWORD') ?: "mf00d@csm";
$dbname = getenv('MFOOD_DB_NAME') ?: "M_FOOD";

$conn = null;
$dbError = null;

try {
    $conn = new PDO("sqlsrv:Server=$servername;Database=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    $dbError = 'ไม่สามารถเชื่อมต่อฐานข้อมูลได้ในขณะนี้';
}
?>
