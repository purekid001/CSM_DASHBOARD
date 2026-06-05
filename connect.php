<?php
// ไฟล์ connect.php
// รองรับการย้าย credential ไปไว้ใน environment variables โดยยังคงทำงานได้กับค่าปัจจุบัน
$servername = getenv('MFOOD_DB_SERVER') ?: "";
$username = getenv('MFOOD_DB_USERNAME') ?: "";
$password = getenv('MFOOD_DB_PASSWORD') ?: "";
$dbname = getenv('MFOOD_DB_NAME') ?: "";

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
