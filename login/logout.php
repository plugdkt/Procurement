<?php
//แค่เซ็ตค่าของคุกกี้ให้ว่าง ด้วยชื่อเดิมตอนที่เราสร้างไว้
setcookie('username','');
 
//กรณีที่ใช้ session ร่วมด้วย
session_start();
session_destroy();
header('location:index.html');
?>